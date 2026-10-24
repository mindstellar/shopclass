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

namespace mindstellar\pages;

use mindstellar\database\Db;
use mindstellar\routing\CoreRoutes;

/**
 * Static page reads for the page editor and the media library. The legacy Page model keeps
 * its own methods.
 */
final class PageQuery
{
    /**
     * A page's URL on this site; $locale is '' for the default language.
     */
    public static function siteUrl(int $id, string $slug, string $title, string $locale): string
    {
        if (osc_rewrite_enabled()) {
            $path = CoreRoutes::expand('page', ['PAGE_ID' => (string) $id, 'PAGE_SLUG' => $slug, 'PAGE_TITLE' => $title]);

            return osc_base_url() . ($locale !== '' ? $locale . '/' : '') . $path;
        }

        return (string) osc_core_url('page', ['id' => $id, 'lang' => $locale]);
    }

    /**
     * The highest i_order, or null for no pages.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function maxOrder(): ?int
    {
        $order = Db::scalar('SELECT MAX(i_order) AS o FROM ' . DB_TABLE_PREFIX . 't_pages');

        return $order === null ? null : (int) $order;
    }

    /**
     * Page internal names that start with $prefix.
     *
     * @return string[]
     * @throws \mindstellar\database\DbException
     */
    public static function internalNamesStartingWith(string $prefix): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['s_internal_name'],
            Db::table(DB_TABLE_PREFIX . 't_pages')->select('s_internal_name')->like('s_internal_name', $prefix, 'after')->get()
        );
    }

    /**
     * The public pages shown as links, in display order. System pages such as e-mail
     * templates are never read.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function linked(): array
    {
        return Db::stringifyRows(
            Db::table(DB_TABLE_PREFIX . 't_pages')
                ->where('b_indelible', 0)
                ->where('b_link', 1)
                ->orderBy('i_order', 'ASC')
                ->orderBy('pk_i_id', 'ASC')
                ->get()
        );
    }

    /**
     * Every public page, linked or not, in display order.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function listed(): array
    {
        return Db::stringifyRows(
            Db::table(DB_TABLE_PREFIX . 't_pages')
                ->where('b_indelible', 0)
                ->orderBy('i_order', 'ASC')
                ->orderBy('pk_i_id', 'ASC')
                ->get()
        );
    }

    /**
     * One public page by id (all digits) or by internal name, linked or not.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public function find(string $idOrSlug): ?array
    {
        if ($idOrSlug === '') {
            return null;
        }
        $query = Db::table(DB_TABLE_PREFIX . 't_pages')->where('b_indelible', 0);
        $query = ctype_digit($idOrSlug) ? $query->where('pk_i_id', (int) $idOrSlug) : $query->where('s_internal_name', $idOrSlug);
        $row   = $query->first();

        return $row === null ? null : Db::stringifyRow($row);
    }

    /**
     * The texts of pages, by page id and then locale code.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,array<string,mixed>>>
     * @throws \mindstellar\database\DbException
     */
    public function texts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (Db::stringifyRows(Db::table(DB_TABLE_PREFIX . 't_pages_description')->whereIn('fk_i_pages_id', $ids)->get()) as $row) {
            $out[(int) $row['fk_i_pages_id']][(string) $row['fk_c_locale_code']] = $row;
        }

        return $out;
    }
}
