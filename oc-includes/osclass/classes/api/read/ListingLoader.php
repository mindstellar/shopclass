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

/**
 * Loads what a page of listings refers to in a fixed number of queries, whatever the page
 * size: one each for photos, sellers and custom field values, each only when the response
 * includes it.
 */
final class ListingLoader
{
    public function __construct(private CategoryCatalog $categories)
    {
    }

    public static function fromSite(): self
    {
        return new self(CategoryCatalog::fromSite());
    }

    public function categories(): CategoryCatalog
    {
        return $this->categories;
    }

    /**
     * @param array<int,array<string,mixed>> $items   extended listing rows
     * @param array<string,bool>             $lookups which of seller, photos, fields to load
     */
    public function load(array $items, array $lookups = ['seller' => true, 'photos' => true, 'fields' => false]): ListingRelations
    {
        $ids     = [];
        $userIds = [];
        $codes   = [];
        foreach ($items as $item) {
            $ids[] = (int) $item['pk_i_id'];
            if (!empty($item['fk_i_user_id'])) {
                $userIds[] = (int) $item['fk_i_user_id'];
            }
            if (!empty($item['fk_c_currency_code'])) {
                $codes[strtoupper((string) $item['fk_c_currency_code'])] = true;
            }
        }
        if ($ids === []) {
            return new ListingRelations($this->categories);
        }
        $currencies = [];
        foreach (array_keys($codes) as $code) {
            $row = \Currency::newInstance()->findByPrimaryKey($code);
            if (is_array($row)) {
                $currencies[$code] = $row;
            }
        }

        return new ListingRelations(
            $this->categories,
            ($lookups['photos'] ?? false) ? $this->photos($ids) : [],
            ($lookups['seller'] ?? false) ? $this->users(array_values(array_unique($userIds))) : [],
            ($lookups['fields'] ?? false) ? $this->fieldValues($ids) : [],
            $currencies
        );
    }

    /**
     * Every listing's photos, in upload order.
     *
     * @param int[] $ids
     *
     * @return array<int,array<int,array<string,mixed>>> item id => t_item_resource rows
     */
    public function photos(array $ids): array
    {
        try {
            $rows = osc_db_stringify_rows(osc_db_table(DB_TABLE_PREFIX . 't_item_resource')->whereIn('fk_i_item_id', $ids)->orderBy('pk_i_id')->get());
        } catch (\mindstellar\database\DbException $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['fk_i_item_id']][] = $row;
        }

        return $out;
    }

    /**
     * Enabled, active users by id: the sellers a page links to.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     */
    private function users(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            $rows = osc_db_table(DB_TABLE_PREFIX . 't_user')
                ->select('pk_i_id', 's_name', 's_username')
                ->whereIn('pk_i_id', $ids)
                ->where('b_enabled', 1)
                ->where('b_active', 1)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return [];
        }

        return array_column(osc_db_stringify_rows($rows), null, 'pk_i_id');
    }

    /**
     * Custom field values of every listing, joined to their field, in form order.
     *
     * @param int[] $ids
     *
     * @return array<int,array<int,array<string,mixed>>> item id => rows
     */
    private function fieldValues(array $ids): array
    {
        // Aliased join with column aliases the builder cannot express; the ids are bound.
        $p   = DB_TABLE_PREFIX;
        $sql = 'SELECT im.fk_i_item_id, mf.pk_i_id, im.s_value, im.s_multi, mf.s_name, mf.s_slug, mf.e_type, mf.s_meta'
            . ' FROM ' . $p . 't_item_meta im'
            . ' INNER JOIN ' . $p . 't_meta_fields mf ON mf.pk_i_id = im.fk_i_field_id'
            . ' WHERE im.fk_i_item_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
            . ' ORDER BY mf.i_position ASC, mf.pk_i_id ASC';
        try {
            $rows = osc_db_stringify_rows(osc_db_select($sql, $ids));
        } catch (\mindstellar\database\DbException $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['fk_i_item_id']][] = $row;
        }

        return $out;
    }
}
