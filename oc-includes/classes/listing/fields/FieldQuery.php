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

namespace mindstellar\fields;

use mindstellar\cache\CacheGroup;
use mindstellar\database\Db;
use mindstellar\database\QueryBuilder;

/**
 * Custom field reads: one field's row, the fields of a category, and the values listings
 * hold, joined to their field. The legacy Field model reads its category fields from here.
 */
final class FieldQuery
{
    /** The s_meta flag that keeps a field off the public listing page and the public API. */
    public const HIDDEN = 'b_hidden';

    /**
     * @return array<string,mixed>|null the bare t_meta_fields row
     * @throws \mindstellar\database\DbException
     */
    public static function find(int $id): ?array
    {
        return Db::table(DB_TABLE_PREFIX . 't_meta_fields')->where('pk_i_id', $id)->first();
    }

    /**
     * The type of each field asked for that exists.
     *
     * @param int[] $ids
     *
     * @return array<int,string> id => e_type
     * @throws \mindstellar\database\DbException
     */
    public static function types(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $types = [];
        foreach (Db::table(DB_TABLE_PREFIX . 't_meta_fields')->select('pk_i_id', 'e_type')->whereIn('pk_i_id', $ids)->get() as $row) {
            $types[(int) $row['pk_i_id']] = (string) $row['e_type'];
        }

        return $types;
    }

    /**
     * One listing's values, each with its field's type.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function listingValues(int $itemId): array
    {
        return Db::select(
            'SELECT m.fk_i_field_id, m.s_multi, m.s_value, f.e_type FROM ' . DB_TABLE_PREFIX . 't_item_meta m'
            . ' LEFT JOIN ' . DB_TABLE_PREFIX . 't_meta_fields f ON f.pk_i_id = m.fk_i_field_id WHERE m.fk_i_item_id = ?',
            [$itemId]
        );
    }

    /**
     * One listing's t_item_meta rows, locked (FOR UPDATE) until the transaction ends.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function lockedListingValues(int $itemId): array
    {
        return Db::select(
            'SELECT fk_i_field_id, s_multi, s_value FROM ' . DB_TABLE_PREFIX . 't_item_meta WHERE fk_i_item_id = ? FOR UPDATE',
            [$itemId]
        );
    }

    /**
     * Form order for two field rows that carry cf_group_position and cf_field_position, as
     * forCategory() returns them: the same order as formOrderSql(), for rows from several queries.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    public static function compareFormOrder(array $a, array $b): int
    {
        return [(int) ($a['cf_group_position'] ?? 0), (int) ($a['cf_field_position'] ?? 0), (int) ($a['pk_i_id'] ?? 0)]
            <=> [(int) ($b['cf_group_position'] ?? 0), (int) ($b['cf_field_position'] ?? 0), (int) ($b['pk_i_id'] ?? 0)];
    }

    /**
     * ORDER BY terms that put fields in form order: form position, then position inside the
     * form, then id. A field in no form sorts first by its library position; in several forms,
     * by the earliest. Needs the field table aliased as mf.
     */
    public static function formOrderSql(): string
    {
        $p    = DB_TABLE_PREFIX;
        $from = $p . 't_meta_group_fields gf';

        return 'COALESCE((SELECT MIN(g.i_position) FROM ' . $from . ' JOIN ' . $p . 't_meta_group g ON g.pk_i_id = gf.fk_i_group_id'
            . ' WHERE gf.fk_i_field_id = mf.pk_i_id), 0) ASC,'
            . ' COALESCE((SELECT MIN(gf.i_position) FROM ' . $from . ' WHERE gf.fk_i_field_id = mf.pk_i_id), mf.i_position) ASC,'
            . ' mf.pk_i_id ASC';
    }

    /**
     * The values of several listings joined to their field, in form order.
     *
     * @param int[] $itemIds
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function valuesOf(array $itemIds): array
    {
        $p = DB_TABLE_PREFIX;

        return Db::select(
            'SELECT im.fk_i_item_id, mf.pk_i_id, im.s_value, mf.s_name, mf.e_type, im.s_multi, mf.s_slug, mf.s_meta'
            . ' FROM ' . $p . 't_item_meta im'
            . ' INNER JOIN ' . $p . 't_meta_fields mf ON mf.pk_i_id = im.fk_i_field_id'
            . ' WHERE im.fk_i_item_id IN (' . implode(', ', array_fill(0, count($itemIds), '?')) . ')'
            . ' ORDER BY ' . self::formOrderSql(),
            $itemIds
        );
    }

    /**
     * One listing's stored values keyed by field id, with s_meta merged in and a date range's
     * two ends under one value. Hidden fields are left out unless $withHidden is true.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forItem(int $itemId, bool $withHidden = false): array
    {
        if ($itemId <= 0) {
            return [];
        }
        try {
            $rows = Db::stringifyRows(self::valuesOf([$itemId]));
        } catch (\mindstellar\database\DbException $e) {
            error_log('Custom field values of listing ' . $itemId . ' could not be read: ' . $e->getMessage());

            return [];
        }
        $locale = self::locale();
        $out    = [];
        foreach ($withHidden ? $rows : self::visibleToPublic($rows) as $row) {
            unset($row['fk_i_item_id']);
            $row = self::extend($row, $locale);
            $id  = (int) $row['pk_i_id'];
            if ($row['e_type'] === 'DATEINTERVAL') {
                $range                     = is_array($out[$id]['s_value'] ?? null) ? $out[$id]['s_value'] : [];
                $range[$row['s_multi']]    = $row['s_value'];
                $row['s_value']            = $range;
            }
            $out[$id] = $row;
        }

        return $out;
    }

    /**
     * The rows visitors may see: a field ticked "hide from visitors" is dropped.
     *
     * @param array<int,array<string,mixed>> $rows rows carrying s_meta
     *
     * @return array<int,array<string,mixed>>
     */
    public static function visibleToPublic(array $rows): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => !self::isHidden($row)));
    }

    /**
     * Whether a field is hidden from visitors. Only the poster form and the admin show it.
     *
     * @param array<string,mixed> $field a field row carrying s_meta
     */
    public static function isHidden(array $field): bool
    {
        $meta = is_string($field['s_meta'] ?? null) && $field['s_meta'] !== '' ? json_decode($field['s_meta'], true) : null;

        return is_array($meta) && !empty($meta[self::HIDDEN]);
    }

    /**
     * The b_searchable value to store: a field hidden from visitors is never searchable.
     */
    public static function searchable(bool $wanted, bool $hidden): int
    {
        return $wanted && !$hidden ? 1 : 0;
    }

    /**
     * A field row with its s_meta JSON merged in and its name in $locale when it has one.
     *
     * @param array<string,mixed> $field
     *
     * @return array<string,mixed>
     */
    public static function extend(array $field, string $locale): array
    {
        if (!empty($field['s_meta'])) {
            $meta = json_decode((string) $field['s_meta'], true);
            if (is_array($meta)) {
                $field = array_merge($field, $meta);
            }
        }
        if (!empty($field['locale'][$locale]['s_name'])) {
            $field['s_name'] = $field['locale'][$locale]['s_name'];
        } elseif (isset($field['s_name'])) {
            $field['locale'][$locale]['s_name'] = $field['s_name'];
        }

        return $field;
    }

    /** The locale field names are shown in: the admin's in the admin, else the visitor's. */
    public static function locale(): string
    {
        return defined('OC_ADMIN') && OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();
    }

    /**
     * The fields that apply to a category, honouring inheritance: loose fields and the fields
     * of forms assigned to the category or any ancestor, once each, in form order (the position
     * set inside the form, not the library position). A field in several forms takes its place
     * and section from the first form. Search and the post form both read this order. Cached in
     * FieldGroup::CACHE_GROUP under the category generation, so a write to the field, form or
     * link tables must drop that group.
     *
     * @return array<int,array<string,mixed>> t_meta_fields rows with cf_group_name, cf_group_position and cf_field_position
     * @throws \mindstellar\database\DbException
     */
    public static function forCategory(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }
        $key = 'category:' . $categoryId . ':' . CacheGroup::generation('category');

        return CacheGroup::remember(\FieldGroup::CACHE_GROUP, $key, static fn (): ?array => self::loadForCategory($categoryId)) ?? [];
    }

    /**
     * @return array<int,array<string,mixed>>|null null when the category path cannot be read, so it is not cached
     * @throws \mindstellar\database\DbException
     */
    private static function loadForCategory(int $categoryId): ?array
    {
        $path = self::categoryPath($categoryId);
        if ($path === []) {
            return null;
        }
        $in = implode(', ', array_fill(0, count($path), '?'));
        $p  = DB_TABLE_PREFIX;

        $rows = Db::stringifyRows(Db::select(
            'SELECT mf.*, NULL AS cf_group_name, 0 AS cf_group_position, mf.i_position AS cf_field_position'
            . ' FROM ' . $p . 't_meta_fields mf, ' . $p . 't_meta_categories mc'
            . ' WHERE mc.fk_i_category_id IN (' . $in . ') AND mf.pk_i_id = mc.fk_i_field_id'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $p . 't_meta_group_fields gfx WHERE gfx.fk_i_field_id = mf.pk_i_id)'
            . ' UNION '
            . 'SELECT mf.*, g.s_name AS cf_group_name, g.i_position AS cf_group_position, gf.i_position AS cf_field_position'
            . ' FROM ' . $p . 't_meta_fields mf'
            . ' JOIN ' . $p . 't_meta_group_fields gf ON gf.fk_i_field_id = mf.pk_i_id'
            . ' JOIN ' . $p . 't_meta_group g ON gf.fk_i_group_id = g.pk_i_id'
            . ' JOIN ' . $p . 't_meta_group_categories gc ON gc.fk_i_group_id = g.pk_i_id'
            . ' WHERE gc.fk_i_category_id IN (' . $in . ')'
            . ' ORDER BY cf_group_position ASC, cf_field_position ASC, pk_i_id ASC',
            array_merge($path, $path)
        ));

        // A field in several forms keeps its first place only.
        $fields = [];
        foreach ($rows as $row) {
            $fields[$row['pk_i_id']] ??= $row;
        }

        return array_values($fields);
    }

    /**
     * The category and its ancestors, leaf first, from the page's enabled-category rows with the
     * in-request cache and from the small cached parent map otherwise. A parent cycle stops at the first repeat.
     *
     * @return int[]
     */
    public static function categoryPath(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }
        if (!\mindstellar\cache\CacheManager::isShared()) {
            $enabled = [];
            foreach (\mindstellar\category\CategoryQuery::enabledRows() ?? [] as $row) {
                $enabled[(int) $row['pk_i_id']] = (int) $row['fk_i_parent_id'];
            }
            $path = self::walk($categoryId, $enabled);
            if ($path !== null) {
                return $path;
            }
        }

        $parents = CacheGroup::remember('category', 'parents', static function () {
            try {
                $rows = Db::select('SELECT pk_i_id, fk_i_parent_id FROM ' . DB_TABLE_PREFIX . 't_category');
            } catch (\mindstellar\database\DbException $e) {
                return null;
            }

            return array_map('intval', array_column($rows, 'fk_i_parent_id', 'pk_i_id'));
        }) ?? [];

        return self::walk($categoryId, $parents, false) ?? [];
    }

    /**
     * Follow $parents up from $categoryId. Null when $strict and an id on the way is not in the map.
     *
     * @param array<int,int> $parents
     *
     * @return int[]|null
     */
    private static function walk(int $categoryId, array $parents, bool $strict = true): ?array
    {
        $path    = [];
        $current = $categoryId;
        while ($current > 0 && count($path) < 100 && !in_array($current, $path, true)) {
            if ($strict && !isset($parents[$current])) {
                return null;
            }
            $path[]  = $current;
            $current = $parents[$current] ?? 0;
        }

        return $path;
    }

    /**
     * The member fields of several forms, each form's in its own order.
     *
     * @param int[] $groupIds
     *
     * @return array<int,array<int,array<string,mixed>>> group id => t_meta_fields rows
     * @throws \mindstellar\database\DbException
     */
    public static function groupFields(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $p    = DB_TABLE_PREFIX;
        $rows = Db::select(
            'SELECT mf.*, gf.fk_i_group_id AS group_link_id FROM ' . $p . 't_meta_fields mf'
            . ' JOIN ' . $p . 't_meta_group_fields gf ON gf.fk_i_field_id = mf.pk_i_id'
            . ' WHERE gf.fk_i_group_id IN (' . implode(', ', array_fill(0, count($groupIds), '?')) . ')'
            . ' ORDER BY gf.fk_i_group_id ASC, gf.i_position ASC',
            array_values($groupIds)
        );
        $out = [];
        foreach (Db::stringifyRows($rows) as $row) {
            $group = (int) $row['group_link_id'];
            unset($row['group_link_id']);
            $out[$group][] = $row;
        }

        return $out;
    }

    /**
     * Up to ten distinct values of a field on live listings that start with $prefix, which is
     * matched literally.
     *
     * @return array<int,array<string,mixed>> rows with value
     * @throws \mindstellar\database\DbException
     */
    public static function suggest(int $fieldId, string $prefix): array
    {
        $like = QueryBuilder::escapeLike($prefix) . '%';

        return Db::select(
            'SELECT DISTINCT m.s_value AS value FROM ' . DB_TABLE_PREFIX . 't_item_meta m'
            . ' JOIN ' . DB_TABLE_PREFIX . 't_item i ON i.pk_i_id = m.fk_i_item_id'
            . ' WHERE m.fk_i_field_id = ? AND m.s_value LIKE ?'
            . ' AND i.b_active = 1 AND i.b_enabled = 1 AND i.b_spam = 0'
            . ' ORDER BY m.s_value LIMIT 10',
            array($fieldId, $like)
        );
    }
}
