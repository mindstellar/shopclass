<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\category;

use Category;
use Item;
use mindstellar\job\CategoryJobs;
use mindstellar\routing\ReservedSlugs;
use mindstellar\validation\ConflictException;
use mindstellar\validation\InvalidException;
use mindstellar\validation\NotFoundException;

/**
 * Category writes the categories screen and the API share, each firing the screen's hooks:
 * adding, saving settings and texts (the model keeps the slug history), switching on or off,
 * deleting.
 */
final class CategoryService
{
    public function __construct(private Category $categories)
    {
    }

    public static function make(): self
    {
        return new self(Category::newInstance());
    }

    /**
     * Add a category, last among its siblings or with $first before every root category,
     * and fire `add_category`. Without $enabled it is on unless its parent is off.
     *
     * @param array{i_expiration_days:int,b_price_enabled:int} $settings
     * @param array<string,array<string,mixed>>                 $descriptions locale => s_name, s_description
     *
     * @return int the new id
     * @throws InvalidException for a parent that does not exist
     * @throws ConflictException when $enabled is true under a disabled parent
     */
    public function create(?int $parentId, array $settings, array $descriptions, ?bool $enabled = null, bool $first = false): int
    {
        $on = $enabled ?? true;
        if ($parentId !== null) {
            if (osc_db_table(DB_TABLE_PREFIX . 't_category')->where('pk_i_id', $parentId)->first() === null) {
                throw new InvalidException('/parent_id', 'unknown', _m('is not a category'));
            }
            if (!$this->canEnableUnder($parentId)) {
                if ($enabled === true) {
                    throw new ConflictException(_m('The parent category is disabled. Enable it first.'));
                }
                $on = false;
            }
        }
        $siblings = osc_db_table(DB_TABLE_PREFIX . 't_category');
        $siblings = $parentId === null ? $siblings->whereNull('fk_i_parent_id') : $siblings->where('fk_i_parent_id', $parentId);
        $fields   = [
            'fk_i_parent_id'    => $parentId,
            'i_expiration_days' => (int) $settings['i_expiration_days'],
            'i_position'        => $first ? 0 : $siblings->count(),
            'b_enabled'         => $on ? 1 : 0,
            'b_price_enabled'   => (int) $settings['b_price_enabled'],
        ];
        $categories = $this->categories;

        return (int) osc_db_transaction(static function () use ($categories, $fields, $descriptions, $first): int {
            $id = (int) $categories->insert($fields, $descriptions);
            if ($first) {
                foreach ($categories->findRootCategories() as $root) {
                    if ((int) $root['pk_i_id'] !== $id) {
                        $categories->updateOrder($root['pk_i_id'], (int) $root['i_position'] + 1);
                    }
                }
            }
            osc_run_hook('add_category', $id);

            return $id;
        });
    }

    /**
     * Save a category's settings and texts, and fire `edited_category` with $outcome, or 2
     * when the write failed. Without `i_expiration_days` the expiry, and its listings', is left
     * alone; expiry and prices can be passed down to its subcategories.
     *
     * @param array{i_expiration_days?:int|string,b_price_enabled:int} $fields
     * @param array<string,array<string,mixed>>                        $descriptions locale => s_name, s_description, s_slug
     * @param int                                                      $outcome      the screen's code: 0 saved, 1 saved with a title missing
     *
     * @return bool false when the write failed
     * @throws InvalidException for a slug the site's API has reserved
     */
    public function update(int $id, array $fields, array $descriptions, bool $toSubcategories, int $outcome = 0): bool
    {
        foreach ($descriptions as $locale => $text) {
            if (ReservedSlugs::taken((string) ($text['s_slug'] ?? ''))) {
                throw new InvalidException('/translations/' . $locale . '/slug', 'invalid', ReservedSlugs::message());
            }
        }
        $result = $this->categories->updateByPrimaryKey(['fields' => $fields, 'aFieldsDescription' => $descriptions], $id);
        if (array_key_exists('i_expiration_days', $fields)) {
            $this->categories->updateExpiration($id, $fields['i_expiration_days'], $toSubcategories);
        }
        $this->categories->updatePriceEnabled($id, $fields['b_price_enabled'], $toSubcategories);
        $saved = !is_bool($result);
        $this->edited($id, $saved ? $outcome : 2);

        return $saved;
    }

    /**
     * Fire `edited_category` for an edit: 0 saved, 1 a title missing, 2 the write failed.
     */
    public function edited(int $id, int $outcome): void
    {
        osc_run_hook('edited_category', $id, $outcome);
    }

    /**
     * Switch a category on or off. A root category takes its subcategories and their
     * listings with it; a subcategory cannot be switched on under a disabled parent. The
     * parent is read from the table, not the request's cached tree.
     *
     * @param array<string,mixed> $category the category's row
     *
     * @return int[]|null the ids switched, or null when the parent is off
     */
    public function setEnabled(int $id, bool $enabled, array $category): ?array
    {
        $value = $enabled ? 1 : 0;
        if ((string) ($category['fk_i_parent_id'] ?? '') === '') {
            $this->categories->update(['b_enabled' => $value], ['pk_i_id' => $id]);
            $this->categories->update(['b_enabled' => $value], ['fk_i_parent_id' => $id]);
            $ids = [$id];
            foreach ($this->categories->findSubcategories($id) as $subcategory) {
                $ids[] = (int) $subcategory['pk_i_id'];
            }
            Item::newInstance()->enableByCategory($value, $ids);
        } else {
            if ($enabled && !$this->canEnableUnder((int) $category['fk_i_parent_id'])) {
                return null;
            }
            $this->categories->update(['b_enabled' => $value], ['pk_i_id' => $id]);
            $ids = [$id];
        }
        osc_invalidate_category_cache();
        osc_invalidate_search_cache();
        osc_purge_page_cache('category');

        return $ids;
    }

    /**
     * Delete a category and its subcategories. One with more listings than a request can
     * remove is hidden now and emptied by a background job.
     *
     * @return string 'done', or 'queued' when a job finishes it
     * @throws NotFoundException
     * @throws \RuntimeException when it could not be deleted
     */
    public function delete(int $id): string
    {
        if (osc_db_table(DB_TABLE_PREFIX . 't_category')->where('pk_i_id', $id)->first() === null) {
            throw new NotFoundException(_m('No such category.'));
        }
        $result = CategoryJobs::requestDelete($id);
        if ($result === 'failed') {
            throw new \RuntimeException('The category could not be deleted.');
        }

        return $result;
    }

    /**
     * Whether a subcategory of this parent may be on: only while the parent is.
     */
    public function canEnableUnder(int $parentId): bool
    {
        $parent = osc_db_table(DB_TABLE_PREFIX . 't_category')->select('b_enabled')->where('pk_i_id', $parentId)->first();

        return !empty($parent['b_enabled']);
    }
}
