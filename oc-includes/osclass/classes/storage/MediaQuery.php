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

namespace mindstellar\storage;

use mindstellar\database\Db;

/**
 * Media library reads: every upload in t_resource, listing photos included, as one list,
 * and the titles of what owns them.
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
        $rows = Db::select('SELECT DISTINCT s_owner_type FROM ' . DB_TABLE_PREFIX . 't_resource WHERE s_owner_type <> ? ORDER BY s_owner_type', array(\ItemResource::OWNER));

        return array_map(static fn (array $row): string => (string) $row['s_owner_type'], $rows);
    }

    /**
     * A page of media rows and the total, newest first.
     *
     * @param string $type   'all', 'item', 'unattached' (the owner was deleted), or a t_resource owner type
     * @param string $search words in the file name, the listing title, or the user's name or e-mail
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     * @throws \mindstellar\database\DbException
     */
    public static function page(string $type, int $iPage, int $perPage, string $search = ''): array
    {
        $resT   = DB_TABLE_PREFIX . 't_resource';
        $offset = max(0, ($iPage - 1) * $perPage);

        $columns = 'r.pk_i_id AS id, r.i_owner_id AS owner_id, r.s_owner_type AS owner_type,'
            . ' r.s_name, r.s_extension, r.s_content_type, r.s_path, r.s_storage, r.dt_created AS dt, r.s_base_name';
        $base    = "SELECT IF(r.s_owner_type = ?, 'item', 'resource') AS src, $columns FROM $resT r";

        $params = array(\ItemResource::OWNER);
        $where  = array();
        if ($type === 'unattached') {
            $where[] = self::unattachedSql();
        } elseif ($type !== 'all') {
            $where[]  = 'r.s_owner_type = ?';
            $params[] = $type;
        }
        $search = trim($search);
        if ($search !== '') {
            $like     = '%' . addcslashes($search, '%_\\') . '%';
            $p        = DB_TABLE_PREFIX;
            $where[]  = "(r.s_name LIKE ?"
                . " OR (r.s_owner_type = 'item' AND r.i_owner_id IN (SELECT fk_i_item_id FROM {$p}t_item_description WHERE s_title LIKE ?))"
                . " OR (r.s_owner_type = 'user' AND r.i_owner_id IN (SELECT pk_i_id FROM {$p}t_user WHERE s_name LIKE ? OR s_email LIKE ?)))";
            array_push($params, $like, $like, $like, $like);
        }
        if ($where !== array()) {
            $base .= ' WHERE ' . implode(' AND ', $where);
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
     * Files whose listing, user or page no longer exists. Library and settings uploads have
     * no owner row and are never counted here.
     *
     * @return string a WHERE condition on the alias r
     */
    private static function unattachedSql(): string
    {
        $p   = DB_TABLE_PREFIX;
        $out = array();
        foreach (array('item' => array('t_item', 'pk_i_id'), 'user' => array('t_user', 'pk_i_id'), 'page' => array('t_pages', 'pk_i_id')) as $owner => [$table, $key]) {
            $out[] = "(r.s_owner_type = '{$owner}' AND NOT EXISTS (SELECT 1 FROM {$p}{$table} o WHERE o.{$key} = r.i_owner_id))";
        }

        return '(' . implode(' OR ', $out) . ')';
    }

    /**
     * Counts for the summary line: files by owner type, by storage, and how many are unattached.
     *
     * @return array{total:int,owners:array<string,int>,storage:array<string,int>,unattached:int}
     * @throws \mindstellar\database\DbException
     */
    public static function summary(): array
    {
        $resT    = DB_TABLE_PREFIX . 't_resource';
        $owners  = array();
        $storage = array();
        foreach (Db::select("SELECT s_owner_type AS k, COUNT(*) AS c FROM $resT GROUP BY s_owner_type") as $row) {
            $owners[(string) $row['k']] = (int) $row['c'];
        }
        foreach (Db::select("SELECT s_storage AS k, COUNT(*) AS c FROM $resT GROUP BY s_storage") as $row) {
            $storage[(string) $row['k']] = (int) $row['c'];
        }

        return array(
            'total'      => array_sum($owners),
            'owners'     => $owners,
            'storage'    => $storage,
            'unattached' => (int) Db::scalar("SELECT COUNT(*) FROM $resT r WHERE " . self::unattachedSql()),
        );
    }

    /**
     * Ids of up to $limit unattached files, as "src:id" picks the delete path takes.
     *
     * @return string[]
     * @throws \mindstellar\database\DbException
     */
    public static function unattachedPicks(int $limit): array
    {
        $rows = Db::select(
            "SELECT IF(r.s_owner_type = ?, 'item', 'resource') AS src, r.pk_i_id AS id FROM " . DB_TABLE_PREFIX
            . 't_resource r WHERE ' . self::unattachedSql() . ' LIMIT ' . max(1, $limit),
            array(\ItemResource::OWNER)
        );

        return array_map(static fn (array $r): string => $r['src'] . ':' . (int) $r['id'], $rows);
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
