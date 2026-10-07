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

/**
 * Static page reads for the page editor and the media library. The legacy Page model keeps
 * its own methods.
 */
final class PageQuery
{
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
}
