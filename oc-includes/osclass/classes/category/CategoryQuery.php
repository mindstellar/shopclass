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

use mindstellar\database\Db;

/**
 * Category reads for admin: rows with their listing counts, enabled or not, and every
 * language's texts.
 */
final class CategoryQuery
{
    /**
     * Category rows with `i_num_items`: one, or all in display order.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rows(?int $id = null): array
    {
        $p   = DB_TABLE_PREFIX;
        $sql = 'SELECT c.*, s.i_num_items FROM ' . $p . 't_category c LEFT JOIN ' . $p . 't_category_stats s ON s.fk_i_category_id = c.pk_i_id';

        return Db::stringifyRows($id === null
            ? Db::select($sql . ' ORDER BY c.i_position ASC, c.pk_i_id ASC')
            : Db::select($sql . ' WHERE c.pk_i_id = ?', [$id]));
    }

    /**
     * Every language's texts of some categories, in one query.
     *
     * @param array<int,int|string> $ids
     *
     * @return array<int,array<int,array<string,mixed>>> category id => rows by locale
     */
    public function texts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = Db::stringifyRows(Db::table(DB_TABLE_PREFIX . 't_category_description')
            ->whereIn('fk_i_category_id', array_map('intval', $ids))
            ->orderBy('fk_c_locale_code')
            ->get());
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['fk_i_category_id']][] = $row;
        }

        return $out;
    }

    /**
     * The enabled categories reachable through enabled parents, parents first and in display
     * order. Each has `i_num_items` and a `locale` map of s_name, s_description and s_slug;
     * its own s_name, s_description and s_slug are $language's, else its first text's.
     * Cached with the category group.
     *
     * @return array<int,array<string,mixed>>
     */
    public function enabledTree(string $language): array
    {
        return \mindstellar\cache\CacheGroup::remember('category', 'enabled-tree:' . $language, fn (): ?array => $this->loadEnabledTree($language)) ?? [];
    }

    /**
     * The enabled categories joined to each language's text and their listing count, one row
     * per category and language, in display order. Category::listEnabled() reads these too.
     * Cached with the category group.
     *
     * @return array<int,array<string,mixed>>|null null when the query fails
     */
    public static function enabledRows(): ?array
    {
        return \mindstellar\cache\CacheGroup::remember('category', 'enabled-rows', static function (): ?array {
            $p = DB_TABLE_PREFIX;
            try {
                return Db::stringifyRows(Db::select(
                    'SELECT a.*, b.*, c.i_num_items FROM ' . $p . 't_category a'
                    . ' LEFT JOIN ' . $p . 't_category_description b ON a.pk_i_id = b.fk_i_category_id'
                    . ' LEFT JOIN ' . $p . 't_category_stats c ON a.pk_i_id = c.fk_i_category_id'
                    . " WHERE b.s_name != '' AND a.b_enabled = 1 ORDER BY a.i_position ASC, a.pk_i_id ASC"
                ));
            } catch (\mindstellar\database\DbException $e) {
                return null;
            }
        });
    }

    /**
     * @return array<int,array<string,mixed>>|null null when the query fails
     */
    private function loadEnabledTree(string $language): ?array
    {
        $rows = self::enabledRows();
        if ($rows === null) {
            return null;
        }
        $byId = [];
        foreach ($rows as $row) {
            $id   = (int) $row['pk_i_id'];
            $code = (string) $row['fk_c_locale_code'];
            $text = ['s_name' => $row['s_name'], 's_description' => $row['s_description'], 's_slug' => $row['s_slug']];
            if (!isset($byId[$id])) {
                unset($row['fk_i_category_id']);
                $byId[$id] = $row;
            }
            $byId[$id]['locale'][$code] = $text;
        }
        $children = [];
        foreach ($byId as $id => $row) {
            $children[$row['fk_i_parent_id'] === null ? 0 : (int) $row['fk_i_parent_id']][] = $id;
        }
        $out  = [];
        $walk = static function (int $parent) use (&$walk, &$out, $children, &$byId, $language): void {
            foreach ($children[$parent] ?? [] as $id) {
                $row = $byId[$id];
                if (isset($row['locale'][$language])) {
                    $row = array_merge($row, $row['locale'][$language]);
                }
                $row['fk_c_locale_code'] = $language;
                $out[]                   = $row;
                $walk($id);
            }
        };
        $walk(0);

        return $out;
    }
}
