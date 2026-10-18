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

namespace mindstellar\api\read;

use mindstellar\api\ProblemException;
use mindstellar\api\RowId;
use mindstellar\cache\CacheGroup;
use mindstellar\category\CategoryQuery;
use mindstellar\database\Db;
use mindstellar\listing\ListingQuery;
use mindstellar\listing\ListingStatus;
use mindstellar\listing\PhotoStore;

/**
 * Every enabled category, with its translations, read once and looked up by id or slug.
 * A listing's category, its path and the category endpoints all come from here, so a page
 * of listings costs no category query of its own.
 */
final class CategoryCatalog
{
    /** Characters of a publish time in a cover stamp, before the listing id. */
    private const STAMP = 14;

    /** @var array<int,array<string,mixed>> id => row, in display order */
    private array $byId = [];

    /** @var array<int,int[]> parent id (0 for roots) => child ids */
    private array $children = [];

    private ?\Closure $source = null;

    /**
     * @param array<int,array<string,mixed>> $rows CategoryQuery::enabledTree() rows, each with a
     *                                             `locale` map of s_name, s_description, s_slug
     */
    public function __construct(array|\Closure $rows)
    {
        if ($rows instanceof \Closure) {
            $this->source = $rows;

            return;
        }
        $this->fill($rows);
    }

    private function fill(array $rows): void
    {
        foreach ($rows as $row) {
            $id = (int) ($row['pk_i_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            unset($row['categories']);
            $this->byId[$id] = $row;
        }
        foreach ($this->byId as $id => $row) {
            $parent = (int) ($row['fk_i_parent_id'] ?? 0);
            $this->children[isset($this->byId[$parent]) ? $parent : 0][] = $id;
        }
    }

    private function load(): void
    {
        if ($this->source !== null) {
            $source       = $this->source;
            $this->source = null;
            $this->fill($source());
        }
    }

    /**
     * The site's enabled categories, read on first use from the cache the category group keeps.
     * One catalog per locale serves the whole request until a category changes.
     */
    public static function fromSite(): self
    {
        static $site = [];
        $locale = OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();
        $key    = $locale . '|' . CacheGroup::generation('category');

        return $site[$key] ??= new self(static fn (): array => (new CategoryQuery())->enabledTree($locale));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $this->load();
        return $this->byId[$id] ?? null;
    }

    /**
     * A category by id, or by slug in any locale (the given one first). A slug path such as
     * `vehicles/cars` names its last slug, as search URLs do.
     *
     * @return array<string,mixed>|null
     */
    public function lookup(string $idOrSlug, string $locale): ?array
    {
        $this->load();
        $id = RowId::parse($idOrSlug);
        if ($id !== null) {
            return $this->find($id);
        }
        $parts    = explode('/', trim($idOrSlug, '/'));
        $idOrSlug = end($parts);
        $fallback = null;
        foreach ($this->byId as $row) {
            foreach ((array) ($row['locale'] ?? []) as $code => $text) {
                if (($text['s_slug'] ?? null) === $idOrSlug) {
                    if ($code === $locale) {
                        return $row;
                    }
                    $fallback ??= $row;
                }
            }
        }

        return $fallback;
    }

    /**
     * The ancestors of a category, root first, without the category itself.
     *
     * @return array<int,array<string,mixed>>
     */
    public function ancestors(int $id): array
    {
        $this->load();
        $path = [];
        $seen = [$id => true];
        $row  = $this->find($id);
        while ($row !== null) {
            $parent = (int) ($row['fk_i_parent_id'] ?? 0);
            if ($parent <= 0 || isset($seen[$parent]) || !isset($this->byId[$parent])) {
                break;
            }
            $seen[$parent] = true;
            $row           = $this->byId[$parent];
            array_unshift($path, $row);
        }

        return $path;
    }

    /**
     * Category ids for ids or slugs. An unknown one is refused, so a typo never widens a
     * filter to every category.
     *
     * @param string[] $values
     * @param bool     $anyId  take an id as given, so an admin may name a category that is off
     *
     * @return int[]
     * @throws ProblemException 422
     */
    public function resolve(array $values, string $locale, bool $anyId = false): array
    {
        $ids = [];
        foreach ($values as $value) {
            $row = $anyId && (RowId::parse($value) ?? 0) > 0 ? ['pk_i_id' => $value] : $this->lookup($value, $locale);
            if ($row === null) {
                throw ProblemException::field('/category', 'enum', 'is not a known category: ' . $value, 'query');
            }
            $ids[] = (int) $row['pk_i_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return int[] child ids in display order; 0 for the roots
     */
    public function childIds(int $id): array
    {
        $this->load();
        return $this->children[$id] ?? [];
    }

    /**
     * @return array<int,array<string,mixed>> every category in display order, parents first
     */
    public function all(): array
    {
        $this->load();
        $out  = [];
        $walk = function (int $parent) use (&$walk, &$out): void {
            foreach ($this->childIds($parent) as $id) {
                $out[] = $this->byId[$id];
                $walk($id);
            }
        };
        $walk(0);

        return $out;
    }

    /**
     * Each category's cover: the first photo of the newest live listing with a photo in the
     * category or any category below it. A category with none has no entry. Two queries for
     * the whole list, both cached until a listing changes.
     *
     * @return array<int,array<string,mixed>> category id => photo row
     * @throws \mindstellar\database\DbException
     */
    public function covers(): array
    {
        $this->load();
        $ids    = array_keys($this->byId);
        $newest = CacheGroup::remember('search', 'category-covers', static fn (): array => self::newestByCategory($ids));
        $best   = [];
        $find   = function (int $id) use (&$find, &$best, $newest): string {
            if (!isset($best[$id])) {
                $best[$id] = $newest[$id] ?? '';
                foreach ($this->childIds($id) as $child) {
                    $best[$id] = max($best[$id], $find($child));
                }
            }

            return $best[$id];
        };
        $items = [];
        foreach (array_keys($this->byId) as $id) {
            if ($find($id) !== '') {
                $items[$id] = (int) substr($best[$id], self::STAMP);
            }
        }
        $itemIds = array_values(array_unique($items));
        $photos  = CacheGroup::remember('search', 'category-cover-photos|' . implode(',', $itemIds), static function () use ($itemIds): array {
            $photos = [];
            foreach (PhotoStore::ofItems($itemIds) as $photo) {
                if (str_starts_with((string) ($photo['s_content_type'] ?? ''), 'image/')) {
                    $photos[(int) $photo['fk_i_item_id']] ??= Db::stringifyRows([$photo])[0];
                }
            }

            return $photos;
        }) ?? [];
        $covers = [];
        foreach ($items as $category => $item) {
            if (isset($photos[$item])) {
                $covers[$category] = $photos[$item];
            }
        }

        return $covers;
    }

    /**
     * category id => the newest live listing with a photo in it, as a sortable string: the
     * publish time as 14 digits, then the id padded to 10. One LIMIT 1 per category on the
     * category index, joined with UNION ALL, so no query scans every listing.
     *
     * @param int[] $ids
     *
     * @return array<int,string>
     * @throws \mindstellar\database\DbException
     */
    private static function newestByCategory(array $ids): array
    {
        $live = implode(' AND ', ListingStatus::liveConditions('i.')) . ' AND ' . PhotoStore::hasImageSql('i.pk_i_id', true);
        $out  = [];
        foreach (array_chunk(array_map('intval', $ids), 200) as $chunk) {
            $parts = array_map(
                static fn (int $id): string => '(SELECT ' . $id . ' AS category, CONCAT(DATE_FORMAT(i.dt_pub_date, \'%Y%m%d%H%i%s\'), LPAD(i.pk_i_id, 10, \'0\')) AS stamp'
                    . ' FROM ' . ListingQuery::tableName() . ' i WHERE i.fk_i_category_id = ' . $id . ' AND ' . $live
                    . ' ORDER BY i.dt_pub_date DESC, i.pk_i_id DESC LIMIT 1)',
                $chunk
            );
            foreach (Db::select(implode(' UNION ALL ', $parts)) as $row) {
                $out[(int) $row['category']] = (string) $row['stamp'];
            }
        }

        return $out;
    }

    /**
     * A translated text of a category row: the locale's own, else the row's default.
     *
     * @param array<string,mixed> $row
     */
    public static function text(array $row, string $key, string $locale): string
    {
        $value = $row['locale'][$locale][$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return (string) ($row[$key] ?? '');
    }
}
