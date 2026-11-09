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
use mindstellar\database\Db;
use mindstellar\exception\ConflictException;
use mindstellar\exception\InvalidException;
use mindstellar\exception\NotFoundException;
use mindstellar\exception\RefusedException;
use mindstellar\job\CategoryJobs;
use mindstellar\listing\ListingCountStore;
use mindstellar\listing\ListingStore;
use mindstellar\routing\ReservedSlugs;
use mindstellar\utility\DeferredMail;

/**
 * Category writes the categories screen and the API share, each firing the screen's hooks:
 * adding, moving, saving settings and texts (the model keeps the slug history), switching on
 * or off, setting expiry, deleting, and the bulk forms of these. Each call reads the tree once.
 */
final class CategoryService
{
    /** Most categories one bulk request may name. */
    public const BULK_LIMIT = 1000;

    /** What bulk() can do. */
    public const BULK_ACTIONS = ['enable', 'disable', 'delete', 'move', 'expiry'];

    public function __construct(private Category $categories)
    {
    }

    public static function make(): self
    {
        return new self(Category::getInstance());
    }

    /**
     * Add a category, last among its siblings or with $first before every root category,
     * and fire `add_category`. Without $enabled it is on unless its parent is off.
     *
     * @param array{i_expiration_days:int,b_price_enabled:int} $settings
     * @param array<string,array<string,mixed>>                 $descriptions locale => s_name, s_description
     *
     * @return int the new id
     * @throws InvalidException for a parent that does not exist, or one at the theme's depth limit
     * @throws ConflictException when $enabled is true under a disabled parent
     */
    public function create(?int $parentId, array $settings, array $descriptions, ?bool $enabled = null, bool $first = false): int
    {
        $on = $enabled ?? true;
        if ($parentId !== null) {
            if (!CategoryStore::exists($parentId)) {
                throw new InvalidException('/parent_id', 'unknown', _m('is not a category'));
            }
            if (!CategoryDepth::allowsChild($this->tree(), $parentId)) {
                throw $this->tooDeep();
            }
            if (!$this->canEnableUnder($parentId)) {
                if ($enabled === true) {
                    throw new ConflictException(_m('The parent category is disabled. Enable it first.'));
                }
                $on = false;
            }
        }
        $fields   = [
            'fk_i_parent_id'    => $parentId,
            'i_expiration_days' => (int) $settings['i_expiration_days'],
            'i_position'        => $first ? 0 : CategoryStore::childCount($parentId),
            'b_enabled'         => $on ? 1 : 0,
            'b_price_enabled'   => (int) $settings['b_price_enabled'],
        ];
        $categories = $this->categories;

        return (int) Db::transaction(static function () use ($categories, $fields, $descriptions, $first): int {
            $id = (int) $categories->insert($fields, $descriptions);
            CategoryStore::forget();
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
     * when the write failed. Without `i_expiration_days`, or with it empty, the expiry is left
     * alone; with it, the expiry is written as setExpiry() does, giving listings new end dates
     * only when $newDates.
     *
     * @param array{i_expiration_days?:int|string,b_price_enabled:int} $fields
     * @param array<string,array<string,mixed>>                        $descriptions locale => s_name, s_description, s_slug
     * @param int                                                      $outcome      the screen's code: 0 saved, 1 saved with a title missing
     *
     * @return bool false when the write failed
     * @throws InvalidException for a slug the site's API has reserved, or days that are not a whole number of 0 or more
     */
    public function update(int $id, array $fields, array $descriptions, bool $toSubcategories, int $outcome = 0, bool $newDates = false): bool
    {
        foreach ($descriptions as $locale => $text) {
            if (ReservedSlugs::taken((string) ($text['s_slug'] ?? ''))) {
                throw new InvalidException('/translations/' . $locale . '/slug', 'invalid', ReservedSlugs::message());
            }
        }
        $days = $fields['i_expiration_days'] ?? '';
        if ($days === '') {
            unset($fields['i_expiration_days']);
        } elseif (!is_int($days) && !(is_string($days) && ctype_digit($days))) {
            throw new InvalidException('/expiration_days', 'invalid', _m('Enter a number of days, 0 or more.'));
        }
        $result = $this->categories->updateByPrimaryKey(['fields' => $fields, 'aFieldsDescription' => $descriptions], $id);
        if (array_key_exists('i_expiration_days', $fields)) {
            $this->writeExpiry([$id], (int) $fields['i_expiration_days'], $newDates, $toSubcategories);
        }
        $this->categories->updatePriceEnabled($id, $fields['b_price_enabled'], $toSubcategories);
        $saved = !is_bool($result);
        $this->edited($id, $saved ? $outcome : 2);

        return $saved;
    }

    /**
     * Save an edit as the categories screen sends it. A blank name is stored empty, nothing is
     * saved without at least one name, and a new parent moves the category last among its new
     * siblings before the fields are written.
     *
     * @param int|null                                                 $parentId the new parent (0 = top level), null to leave it
     * @param array{i_expiration_days?:int|string,b_price_enabled:int} $fields
     * @param array<string,array<string,mixed>>                        $descriptions locale => s_name, s_description, s_slug
     *
     * @return array{outcome:int,moved:bool} outcome 0 saved, 1 not saved for want of a name, 2 the write failed, 4 saved with some names blank
     * @throws RefusedException for a refused move (pointer /parent_id), a reserved slug or days that are not a whole number
     */
    public function save(int $id, ?int $parentId, array $fields, array $descriptions, bool $toSubcategories, bool $newDates = false): array
    {
        $blank = false;
        foreach ($descriptions as $locale => $text) {
            if (array_key_exists('s_name', $text) && (string) $text['s_name'] === '') {
                $descriptions[$locale]['s_name'] = null;
                $blank = true;
            }
        }
        if ($blank && !self::titled($descriptions)) {
            $this->edited($id, 1);

            return ['outcome' => 1, 'moved' => false];
        }
        $moved = false;
        if ($parentId !== null && $parentId !== (int) $this->tree()->parent($id)) {
            $this->move($id, $parentId > 0 ? $parentId : null, PHP_INT_MAX);
            $moved = true;
        }
        if (!$this->update($id, $fields, $descriptions, $toSubcategories, $blank ? 1 : 0, $newDates)) {
            return ['outcome' => 2, 'moved' => $moved];
        }

        return ['outcome' => $blank ? 4 : 0, 'moved' => $moved];
    }

    /**
     * Whether at least one language has a name.
     *
     * @param array<string,array<string,mixed>> $descriptions
     */
    public static function titled(array $descriptions): bool
    {
        foreach ($descriptions as $text) {
            if ((string) ($text['s_name'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * An admin's edit and on/off switch, all or none: the edit first, then the switch when it
     * changes the category.
     *
     * @param array<string,mixed>      $category the category's row
     * @param array{fields:array{i_expiration_days?:int|string,b_price_enabled:int},descriptions:array<string,array<string,mixed>>,toSubcategories:bool,newDates?:bool}|null $edit null for no edit; newDates defaults to false
     * @param bool|null                $enabled  null to leave it
     *
     * @return string 'ok', 'failed' when the edit's write failed, or 'parent_off' when the parent is off; nothing is kept unless 'ok'
     * @throws InvalidException for a slug the site's API has reserved
     */
    public function adminEdit(int $id, array $category, ?array $edit, ?bool $enabled): string
    {
        $outcome = 'ok';
        try {
            DeferredMail::transaction(function () use ($id, $category, $edit, $enabled, &$outcome): void {
                if ($edit !== null && !$this->update($id, $edit['fields'], $edit['descriptions'], $edit['toSubcategories'], 0, $edit['newDates'] ?? false)) {
                    $outcome = 'failed';
                }
                if ($outcome === 'ok' && $enabled !== null && $enabled !== ((int) $category['b_enabled'] === 1)
                    && $this->setEnabled($id, $enabled, $category) === null
                ) {
                    $outcome = 'parent_off';
                }
                if ($outcome !== 'ok') {
                    throw new \RuntimeException('The category edit was not kept.');
                }
            });
        } catch (\RuntimeException $e) {
            if ($outcome === 'ok') {
                throw $e;
            }
        }

        return $outcome;
    }

    /**
     * Fire `edited_category` for an edit: 0 saved, 1 a title missing, 2 the write failed.
     */
    public function edited(int $id, int $outcome): void
    {
        osc_run_hook('edited_category', $id, $outcome);
    }

    /**
     * Switch a category on or off with every category below it, at any depth, and their
     * listings. A category cannot be switched on under a disabled parent.
     *
     * @param array<string,mixed> $category the category's row
     *
     * @return int[]|null the ids switched, the category first, or null when the parent is off
     */
    public function setEnabled(int $id, bool $enabled, array $category): ?array
    {
        return $this->enableIn($this->tree(), $id, $enabled, $category);
    }

    /**
     * setEnabled() on a tree already read.
     *
     * @param array<string,mixed> $category
     *
     * @return int[]|null
     */
    private function enableIn(CategoryTree $tree, int $id, bool $enabled, array $category): ?array
    {
        $parent = (string) ($category['fk_i_parent_id'] ?? '');
        if ($enabled && $parent !== '' && !$this->canEnableUnder((int) $parent)) {
            return null;
        }
        $ids = array_reverse($tree->subtree($id)) ?: [$id];
        Db::transaction(static function () use ($ids, $enabled): void {
            if ($enabled) {
                CategoryStore::setEnabled($ids, true);
                self::listingsOn($ids);
            } else {
                self::listingsOff($ids);
                CategoryStore::setEnabled($ids, false);
            }
        });
        $this->flushCaches();

        return $ids;
    }

    /**
     * Put a category under $parentId (null = top level) at $position among its siblings, renumbering
     * only the two sibling groups and recounting only the two ancestor chains. Under a disabled
     * parent it is switched off; fires `edited_category_order` with 0, or 1 when a write failed.
     *
     * @throws NotFoundException for an unknown category
     * @throws InvalidException  for an unknown parent, one inside the category, or too deep
     */
    public function move(int $id, ?int $parentId, int $position): void
    {
        $this->moveIn($this->tree(), $id, $parentId, $position);
    }

    /**
     * Place categories in the order given, each under its parent (null = top level), as the
     * drag-and-drop list sends the whole tree. Each placement passes move()'s checks, and
     * `edited_category_order` fires once, with 1 when any placement failed.
     *
     * @param array<int,array{0:int,1:?int}> $rows id and parent, in order
     *
     * @return bool false when a placement was refused or failed
     */
    public function arrange(array $rows): bool
    {
        $tree = $this->tree();
        $next = [];
        $ok   = true;
        foreach ($rows as [$id, $parentId]) {
            $key        = (string) $parentId;
            $position   = $next[$key] ?? 0;
            $next[$key] = $position + 1;
            if ($tree->exists($id) && $tree->parent($id) === $parentId && array_search($id, $tree->children($parentId), true) === $position) {
                continue;
            }
            try {
                $tree = $this->moveIn($tree, $id, $parentId, $position, false);
            } catch (RefusedException | \RuntimeException $e) {
                $ok = false;
            }
        }
        osc_run_hook('edited_category_order', $ok ? 0 : 1);

        return $ok;
    }

    /**
     * move() on a tree already read; returns the tree as it is after the move. Without $hook the
     * caller fires `edited_category_order` itself.
     *
     * @throws NotFoundException
     * @throws InvalidException
     * @throws \mindstellar\database\DbException when a write failed
     */
    private function moveIn(CategoryTree $tree, int $id, ?int $parentId, int $position, bool $hook = true): CategoryTree
    {
        if (!$tree->exists($id)) {
            throw new NotFoundException(_m('No such category.'));
        }
        if ($parentId !== null) {
            if (!$tree->exists($parentId)) {
                throw new InvalidException('/parent_id', 'unknown', _m('is not a category'));
            }
            if ($parentId === $id || $tree->isInside($parentId, $id)) {
                throw new InvalidException('/parent_id', 'inside_itself', _m("A category can't go inside its own subcategory."));
            }
        }
        if (!CategoryDepth::allows($tree, $id, $parentId)) {
            throw $this->tooDeep();
        }

        $oldParent = $tree->parent($id);
        $moved     = $oldParent !== $parentId;
        $old       = array_values(array_diff($tree->children($oldParent), [$id]));
        $new       = $moved ? array_values(array_diff($tree->children($parentId), [$id])) : $old;
        array_splice($new, max(0, min($position, count($new))), 0, [$id]);

        $row   = CategoryStore::flagsAndCount($id);
        $count = (int) ($row['i_num_items'] ?? 0);
        $from  = $tree->ancestors($id);
        $to    = $parentId === null ? [] : array_merge([$parentId], $tree->ancestors($parentId));
        $off   = $moved && $parentId !== null && !empty($row['b_enabled']) && !$this->canEnableUnder($parentId)
            ? $tree->subtree($id) : [];

        try {
            Db::transaction(static function () use ($id, $parentId, $moved, $old, $new, $count, $from, $to, $off): void {
                if ($moved) {
                    CategoryStore::setParent($id, $parentId);
                    CategoryStore::reorder($old);
                    if ($count > 0) {
                        ListingCountStore::categories()->add(array_values(array_diff($from, $to)), -$count);
                        ListingCountStore::categories()->add(array_values(array_diff($to, $from)), $count);
                    }
                }
                CategoryStore::reorder($new);
                if ($off !== []) {
                    self::listingsOff($off);
                    CategoryStore::setEnabled($off, false);
                }
            });
        } catch (\Throwable $e) {
            if ($hook) {
                osc_run_hook('edited_category_order', 1);
            }
            throw $e;
        }
        $this->flushCaches();
        if ($hook) {
            osc_run_hook('edited_category_order', 0);
        }

        // The same map with this category's parent changed and both groups in their new order.
        $map      = $tree->map();
        $map[$id] = $parentId;
        foreach (array_merge($old, $new) as $sibling) {
            $parent = $map[$sibling];
            unset($map[$sibling]);
            $map[$sibling] = $parent;
        }

        return new CategoryTree($map);
    }

    /**
     * Set the expiry days of these categories, and of everything below them when
     * $toSubcategories, and fire `edited_category` with 0 for each one written. With $newDates
     * their listings get new end dates too (0 days = never end), in a background job when
     * there are more than a request should handle.
     *
     * @param int[] $ids
     *
     * @return array{categories:int,listings:int,queued:bool} categories written, listings re-dated or queued
     * @throws InvalidException for negative days
     * @throws \RuntimeException when the job could not be queued
     */
    public function setExpiry(array $ids, int $days, bool $newDates, bool $toSubcategories): array
    {
        [$result, $targets] = $this->writeExpiry($ids, $days, $newDates, $toSubcategories);
        foreach ($targets as $target) {
            $this->edited($target, 0);
        }

        return $result;
    }

    /**
     * What deleting these categories would remove, everything below them included. Every
     * listing counts, expired, unapproved and disabled too.
     *
     * @param int[] $ids
     *
     * @return array{categories:int,listings:int,queued:bool} queued when a job would do it
     */
    public function deletePreview(array $ids): array
    {
        $tree     = $this->tree();
        $all      = $this->targets($tree, $tree->topMost($this->known($tree, $ids)), true);
        $listings = $all === [] ? 0 : ListingStore::countInCategories($all);

        return ['categories' => count($all), 'listings' => $listings, 'queued' => self::queues($listings)];
    }

    /**
     * What setting $days on these categories would do to their listings: how many sit in the
     * reach (everything below too when $toSubcategories) and how many live ones would end at
     * once under new end dates. endNow is null over the inline limit.
     *
     * @param int[] $ids
     *
     * @return array{listings:int,endNow:int|null}
     */
    public function expiryPreview(array $ids, int $days, bool $toSubcategories): array
    {
        $tree    = $this->tree();
        $targets = $this->targets($tree, $tree->topMost($this->known($tree, $ids)), $toSubcategories);
        if ($targets === []) {
            return ['listings' => 0, 'endNow' => 0];
        }
        $listings = ListingStore::countInCategories($targets);
        if ($days <= 0) {
            return ['listings' => $listings, 'endNow' => 0];
        }
        if (self::queues($listings)) {
            return ['listings' => $listings, 'endNow' => null];
        }

        return ['listings' => $listings, 'endNow' => ListingStore::countEndingInCategories($targets, $days)];
    }

    /**
     * Do $action to the top-most of these categories, each through its single method so hooks
     * fire per category. A delete over the inline limit queues every category.
     *
     * @param int[]               $ids
     * @param array<string,mixed> $args move: parent (?int), position (int); expiry: days (int), newDates, toSubcategories (bool)
     *
     * @return array{done:int[],failed:array<int,string>,queued:int[]} failed is id => reason
     * @throws InvalidException for an unknown action, too many ids or negative days
     */
    public function bulk(string $action, array $ids, array $args = []): array
    {
        if (!in_array($action, self::BULK_ACTIONS, true)) {
            throw new InvalidException('/action', 'invalid', _m('The request was refused.'));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if (count($ids) > self::BULK_LIMIT) {
            throw new InvalidException('/ids', 'too_many', sprintf(
                _mn('Choose up to %d category at a time.', 'Choose up to %d categories at a time.', self::BULK_LIMIT),
                self::BULK_LIMIT
            ));
        }
        $out   = ['done' => [], 'failed' => [], 'queued' => []];
        $tree  = $this->tree();
        $known = $this->known($tree, $ids);
        foreach (array_diff($ids, $known) as $missing) {
            $out['failed'][$missing] = _m('No such category.');
        }
        $top = $tree->topMost($known);
        if ($top === []) {
            return $out;
        }

        if ($action === 'expiry') {
            $result = $this->setExpiry($top, (int) ($args['days'] ?? 0), (bool) ($args['newDates'] ?? false), (bool) ($args['toSubcategories'] ?? false));
            $out[$result['queued'] ? 'queued' : 'done'] = $top;

            return $out;
        }

        $queue    = $action === 'delete' && self::queues(ListingStore::countInCategories($this->targets($tree, $top, true)));
        $parent   = isset($args['parent']) && (int) $args['parent'] > 0 ? (int) $args['parent'] : null;
        $position = (int) ($args['position'] ?? 0);
        $error    = $action === 'delete' ? __('An error occurred while deleting') : __('An error occurred while updating');
        foreach ($top as $id) {
            try {
                switch ($action) {
                    case 'enable':
                    case 'disable':
                        $row = ['pk_i_id' => $id, 'fk_i_parent_id' => $tree->ancestors($id)[0] ?? null];
                        if ($this->enableIn($tree, $id, $action === 'enable', $row) === null) {
                            $out['failed'][$id] = __('Parent category is disabled, you can not enable that category');
                            continue 2;
                        }
                        break;
                    case 'move':
                        $tree = $this->moveIn($tree, $id, $parent, $position);
                        $position++;
                        break;
                    case 'delete':
                        if ($this->delete($id, $queue) === 'queued') {
                            $out['queued'][] = $id;
                            continue 2;
                        }
                        break;
                }
                $out['done'][] = $id;
            } catch (InvalidException | NotFoundException | ConflictException $e) {
                $out['failed'][$id] = $e->getMessage();
            } catch (\RuntimeException $e) {
                $out['failed'][$id] = $error;
            }
        }

        return $out;
    }

    /**
     * How many levels of categories the active theme shows, or null for no limit.
     */
    public function depthLimit(): ?int
    {
        return CategoryDepth::limit();
    }

    /**
     * Delete a category and its subcategories. One with more listings than a request can
     * remove, or any with $queue, is hidden now and emptied by a background job.
     *
     * @return string 'done', or 'queued' when a job finishes it
     * @throws NotFoundException
     * @throws \RuntimeException when it could not be deleted
     */
    public function delete(int $id, bool $queue = false): string
    {
        if (!CategoryStore::exists($id)) {
            throw new NotFoundException(_m('No such category.'));
        }
        $result = CategoryJobs::requestDelete($id, $queue);
        CategoryStore::forget();
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
        return CategoryStore::isEnabled($parentId);
    }

    /**
     * setExpiry() without the hooks, for update(), which fires its own.
     *
     * @param int[] $ids
     *
     * @return array{0:array{categories:int,listings:int,queued:bool},1:int[]} the result and the categories written
     * @throws InvalidException for negative days
     * @throws \RuntimeException when the job could not be queued
     */
    private function writeExpiry(array $ids, int $days, bool $newDates, bool $toSubcategories): array
    {
        if ($days < 0) {
            throw new InvalidException('/days', 'invalid', _m('Enter a number of days, 0 or more.'));
        }
        $tree    = $this->tree();
        $targets = $this->targets($tree, $tree->topMost($this->known($tree, $ids)), $toSubcategories);
        if ($targets === []) {
            return [['categories' => 0, 'listings' => 0, 'queued' => false], []];
        }
        CategoryStore::setExpirationDays($targets, $days);
        $this->flushCaches();
        if (!$newDates) {
            return [['categories' => count($targets), 'listings' => 0, 'queued' => false], $targets];
        }

        $listings = ListingStore::countInCategories($targets);
        if (self::queues($listings)) {
            if (!CategoryJobs::requestExpiry($targets, $days)) {
                throw new \RuntimeException('The new end dates could not be queued.');
            }

            return [['categories' => count($targets), 'listings' => $listings, 'queued' => true], $targets];
        }
        if ($listings > 0) {
            $items = Item::getInstance();
            foreach (ListingStore::idsInCategories($targets, $listings) as $row) {
                $items->updateExpirationDate((int) $row['pk_i_id'], $days);
            }
        }

        return [['categories' => count($targets), 'listings' => $listings, 'queued' => false], $targets];
    }

    /**
     * Whether work over this many listings goes to a job instead of running now.
     */
    private static function queues(int $listings): bool
    {
        return $listings > CategoryJobs::INLINE_LIMIT;
    }

    /**
     * Switch off the listings in these categories, noting first the ones already off on their own.
     *
     * @param int[] $ids
     */
    private static function listingsOff(array $ids): void
    {
        ListingStore::rememberOffInCategories($ids);
        Item::getInstance()->enableByCategory(0, $ids);
    }

    /**
     * Switch the listings in these categories back on, except those that were off on their own.
     *
     * @param int[] $ids
     */
    private static function listingsOn(array $ids): void
    {
        ListingStore::enableInCategories($ids);
        osc_run_hook('items_bulk_enabled_by_category', $ids, 1);
    }

    private function tree(): CategoryTree
    {
        return CategoryStore::tree();
    }

    /**
     * The ids that are categories, in the order given.
     *
     * @param int[] $ids
     *
     * @return int[]
     */
    private function known(CategoryTree $tree, array $ids): array
    {
        return array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $tree->exists($id)));
    }

    /**
     * These categories, and everything below them when $below.
     *
     * @param int[] $ids top-most ids
     *
     * @return int[]
     */
    private function targets(CategoryTree $tree, array $ids, bool $below): array
    {
        if (!$below) {
            return $ids;
        }
        $all = [];
        foreach ($ids as $id) {
            array_push($all, ...$tree->subtree($id));
        }

        return array_values(array_unique($all));
    }

    private function tooDeep(): InvalidException
    {
        $limit = (int) CategoryDepth::limit();

        return new InvalidException('/parent_id', 'too_deep', sprintf(
            _mn('This theme shows %d level of categories.', 'This theme shows %d levels of categories.', $limit),
            $limit
        ));
    }

    private function flushCaches(): void
    {
        osc_invalidate_category_cache();
        osc_invalidate_search_cache();
        osc_purge_page_cache('category');
    }
}
