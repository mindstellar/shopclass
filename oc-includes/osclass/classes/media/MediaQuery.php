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

namespace mindstellar\media;

use mindstellar\database\Db;

/**
 * Media library reads: listing photos (t_item_resource) and other uploads (t_resource) as
 * one list, and the titles of what owns them.
 */
final class MediaQuery
{
    /** Owner type => [table, key column, title expression]. */
    private const OWNERS = [
        'item' => ['t_item_description', 'fk_i_item_id', 's_title'],
        'user' => ['t_user', 'pk_i_id', "COALESCE(NULLIF(s_name, ''), s_email)"],
        'page' => ['t_pages_description', 'fk_i_pages_id', 's_title'],
    ];

    /**
     * Distinct owner types in t_resource, unchecked.
     *
     * @return string[]
     * @throws \mindstellar\database\DbException
     */
    public static function ownerTypes(): array
    {
        $rows = Db::select('SELECT DISTINCT s_owner_type FROM ' . DB_TABLE_PREFIX . 't_resource ORDER BY s_owner_type');

        return array_map(static fn (array $row): string => (string) $row['s_owner_type'], $rows);
    }

    /**
     * A page of media rows and the total, newest first.
     *
     * @param string $type 'all', 'item', or a t_resource owner type
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     * @throws \mindstellar\database\DbException
     */
    public static function page(string $type, int $iPage, int $perPage): array
    {
        $itemT  = DB_TABLE_PREFIX . 't_item_resource';
        $resT   = DB_TABLE_PREFIX . 't_resource';
        $offset = max(0, ($iPage - 1) * $perPage);

        $itemSel = "SELECT 'item' AS src, pk_i_id AS id, fk_i_item_id AS owner_id, 'item' AS owner_type,"
            . " s_name, s_extension, s_content_type, s_path, s_storage, NULL AS dt FROM $itemT";
        $resSel  = "SELECT 'resource' AS src, pk_i_id AS id, i_owner_id AS owner_id, s_owner_type AS owner_type,"
            . " s_name, s_extension, s_content_type, s_path, s_storage, dt_created AS dt FROM $resT";

        $params = array();
        if ($type === 'item') {
            $base = $itemSel;
        } elseif ($type === 'all') {
            $base = "($itemSel) UNION ALL ($resSel)";
        } else {
            $base     = $resSel . ' WHERE s_owner_type = ?';
            $params[] = $type;
        }

        $total = (int) Db::scalar("SELECT COUNT(*) FROM ($base) AS m", $params);
        $rows  = Db::select(
            "SELECT * FROM ($base) AS m ORDER BY (dt IS NULL), dt DESC, id DESC"
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return array('rows' => $rows, 'total' => $total);
    }

    /**
     * Every page's id, title and text, for matching library uploads against page content.
     *
     * @return array<int,array<string,mixed>> id, title, text
     * @throws \mindstellar\database\DbException
     */
    public static function pageTexts(): array
    {
        return Db::select(
            'SELECT fk_i_pages_id AS id, s_title AS title, s_text AS text FROM ' . DB_TABLE_PREFIX . 't_pages_description'
        );
    }

    /**
     * Display titles by id for one owner type. Description tables hold a row per locale,
     * so the first non-empty value per id wins.
     *
     * @param string $type 'item', 'user' or 'page'
     * @param int[]  $ids
     *
     * @return array<int,string>
     * @throws \mindstellar\database\DbException
     */
    public static function ownerTitles(string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === array()) {
            return array();
        }
        [$table, $key, $title] = self::OWNERS[$type];
        $rows = Db::select(
            "SELECT {$key} AS k, {$title} AS v FROM " . DB_TABLE_PREFIX . $table . " WHERE {$key} IN (" . implode(',', $ids) . ')'
        );
        $out = array();
        foreach ($rows as $r) {
            $k = (int) $r['k'];
            if (!isset($out[$k]) && (string) $r['v'] !== '') {
                $out[$k] = (string) $r['v'];
            }
        }

        return $out;
    }

    /**
     * The description rows of a listing or page, every locale.
     *
     * @param string $type 'item' or 'page'
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function descriptions(string $type, int $id): array
    {
        if ($type !== 'item' && $type !== 'page') {
            throw new \InvalidArgumentException('Unknown owner type ' . $type . '.');
        }
        [$table, $key] = self::OWNERS[$type];

        return Db::table(DB_TABLE_PREFIX . $table)->where($key, $id)->get();
    }
}
