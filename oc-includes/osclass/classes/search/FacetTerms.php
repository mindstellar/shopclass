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

namespace mindstellar\search;

use mindstellar\database\Db;
use mindstellar\database\DbException;

/**
 * t_facet_term: the (key, value) terms the rows of t_item_facet name, with a cache of their
 * ids for the request. Values are compared under the table's collation.
 */
final class FacetTerms
{
    public const TABLE = 't_facet_term';

    private const TERMS_PER_SELECT = 200;
    private const PRUNE_RANGE = 2000;

    /** @var array<string,array<int|string,int>> committed term ids by key and cut value, for this request */
    private static array $terms = [];

    private static float $request = 0.0;

    public static function tableName(): string
    {
        return DB_TABLE_PREFIX . self::TABLE;
    }

    public static function forget(): void
    {
        self::$terms = [];
    }

    /**
     * The term ids of the pairs that have one. Values are cut as rows are written and compared
     * under the table's collation, so 'red' finds the term of 'Red'. Reads only.
     *
     * @param array<string,array<int,int|string>> $pairs key => values (a number key asks for '')
     *
     * @return array<string,array<int|string,int>> key => value as asked => term id; unknown pairs left out
     * @throws DbException
     */
    public static function termIds(array $pairs): array
    {
        self::newRequest();
        $found  = [];
        $misses = [];
        foreach ($pairs as $key => $values) {
            foreach ($values as $value) {
                $cut = FacetIndex::cut((string) $value);
                if (isset(self::$terms[$key][$cut])) {
                    $found[$key][$value] = self::$terms[$key][$cut];
                } else {
                    $misses[$key . "\0" . $cut] = [(string) $key, $cut];
                }
            }
        }
        if ($misses === []) {
            return $found;
        }
        $ids = self::lookup(array_values($misses), false);
        self::remember($ids);

        return self::fill($found, $pairs, $ids);
    }

    /**
     * As termIds(), creating the terms that are missing. A term another transaction made after
     * this one's snapshot is returned too; a row naming it is written after the commit instead.
     *
     * @param array<string,array<int,int|string>> $pairs key => values
     *
     * @return array<string,array<int|string,int>> key => value as asked => term id
     * @throws DbException
     */
    public static function ensureTerms(array $pairs): array
    {
        return self::resolve($pairs)[0];
    }

    /**
     * ensureTerms(), with the ids this transaction's snapshot cannot see; a row naming one waits for the commit.
     *
     * @param array<string,array<int,int|string>> $pairs
     *
     * @return array{0:array<string,array<int|string,int>>,1:array<int,true>}
     * @throws DbException
     */
    public static function resolve(array $pairs): array
    {
        $found   = self::termIds($pairs);
        $missing = [];
        foreach ($pairs as $key => $values) {
            foreach ($values as $value) {
                if (!isset($found[$key][$value])) {
                    $cut = FacetIndex::cut((string) $value);
                    $missing[$key . "\0" . $cut] = [(string) $key, $cut];
                }
            }
        }
        if ($missing === []) {
            return [$found, []];
        }
        // Sorted, so two writers making the same terms lock them in one order.
        ksort($missing, SORT_STRING);
        $missing = array_values($missing);
        foreach (array_chunk($missing, self::TERMS_PER_SELECT) as $chunk) {
            Db::execute(
                'INSERT IGNORE INTO ' . self::tableName() . ' (s_key, s_value) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?)')),
                array_merge(...$chunk)
            );
        }
        $ids  = self::lookup($missing, false);
        $seen = [];
        foreach ($ids as [$key, $cut]) {
            $seen[$key . "\0" . $cut] = true;
        }
        $left   = array_values(array_filter($missing, static fn (array $pair): bool => !isset($seen[$pair[0] . "\0" . $pair[1]])));
        $unseen = [];
        if ($left !== []) {
            // A term another writer committed after this snapshot shows only to a locking read.
            foreach (self::lookup($left, true) as $row) {
                $ids[]           = $row;
                $unseen[$row[2]] = true;
            }
        }
        self::remember($ids);

        return [self::fill($found, $pairs, $ids), $unseen];
    }

    /**
     * Cache looked-up ids once the open transaction commits, so a rollback leaves none behind.
     *
     * @param array<int,array{0:string,1:string,2:int}> $ids
     */
    private static function remember(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        Db::afterCommit(static function () use ($ids): void {
            foreach ($ids as [$key, $cut, $id]) {
                self::$terms[$key][$cut] = $id;
            }
        });
    }

    /**
     * Delete the terms of these keys, which removes their rows too.
     *
     * @param string[] $keys
     *
     * @throws DbException
     */
    public static function drop(array $keys): void
    {
        Db::execute('DELETE FROM ' . self::tableName() . ' WHERE s_key IN (' . implode(', ', array_fill(0, count($keys), '?')) . ')', array_values($keys));
        foreach ($keys as $key) {
            unset(self::$terms[$key]);
        }
    }

    /**
     * Delete the terms no row names, as a rebuild leaves them. Only a rebuild calls this; a term
     * stays after its last listing changes, and no list reads the terms, so it costs only space.
     * It deletes in id ranges, so no statement holds locks for long.
     *
     * @return int the terms deleted
     * @throws DbException
     */
    public static function prune(): int
    {
        self::$terms = [];
        $max         = (int) Db::scalar('SELECT MAX(pk_i_id) FROM ' . self::tableName());
        $deleted     = 0;
        for ($from = 0; $from < $max; $from += self::PRUNE_RANGE) {
            $deleted += Db::execute(
                'DELETE t FROM ' . self::tableName() . ' t LEFT JOIN ' . FacetIndex::tableName() . ' f ON f.fk_i_term_id = t.pk_i_id'
                . ' WHERE t.pk_i_id > ? AND t.pk_i_id <= ? AND f.fk_i_term_id IS NULL',
                [$from, $from + self::PRUNE_RANGE]
            );
        }

        return $deleted;
    }

    /**
     * Term ids of [key, cut value] pairs, compared under the term table's collation.
     *
     * @param array<int,array{0:string,1:string}> $pairs
     *
     * @return array<int,array{0:string,1:string,2:int}> the asked key and value with the id, for those found
     * @throws DbException
     */
    private static function lookup(array $pairs, bool $lock): array
    {
        $found = [];
        foreach (array_chunk($pairs, self::TERMS_PER_SELECT) as $chunk) {
            $rows = [];
            foreach (array_keys($chunk) as $n) {
                $rows[] = $n === 0 ? 'SELECT 0 AS n, ? AS k, ? AS v' : 'SELECT ' . $n . ', ?, ?';
            }
            $result = Db::select(
                'SELECT q.n, t.pk_i_id FROM (' . implode(' UNION ALL ', $rows) . ') q JOIN ' . self::tableName() . ' t'
                . ' ON t.s_key = q.k AND t.s_value = q.v' . ($lock ? ' LOCK IN SHARE MODE' : ''),
                array_merge(...$chunk)
            );
            foreach ($result as $row) {
                $pair    = $chunk[(int) $row['n']];
                $found[] = [$pair[0], $pair[1], (int) $row['pk_i_id']];
            }
        }

        return $found;
    }

    /**
     * Add the looked-up ids to $found, keyed by each value as it was asked.
     *
     * @param array<string,array<int|string,int>>  $found
     * @param array<string,array<int,int|string>>  $pairs
     * @param array<int,array{0:string,1:string,2:int}> $ids
     *
     * @return array<string,array<int|string,int>>
     */
    private static function fill(array $found, array $pairs, array $ids): array
    {
        $byPair = [];
        foreach ($ids as [$key, $cut, $id]) {
            $byPair[$key . "\0" . $cut] = $id;
        }
        foreach ($pairs as $key => $values) {
            foreach ($values as $value) {
                $id = $byPair[$key . "\0" . FacetIndex::cut((string) $value)] ?? null;
                if ($id !== null && !isset($found[$key][$value])) {
                    $found[$key][$value] = $id;
                }
            }
        }

        return $found;
    }

    /**
     * Whether the request time moved since the last call, which drops the cache. A long-running
     * worker keeps statics between requests.
     */
    public static function newRequest(): bool
    {
        $now = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? 0.0);
        if ($now === self::$request) {
            return false;
        }
        self::$request = $now;
        self::$terms   = [];

        return true;
    }
}
