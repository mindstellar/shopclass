<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\security;

/**
 * A rate limit on any key: an API key, an account, a token. ActionThrottle limits by
 * address; this limits by what the caller names.
 *
 * One counter row per key per fixed window, so a busy key costs one row, not one per
 * request. The key is stored hashed, so a secret passed as the key is never written.
 * Like ActionThrottle it fails open: a counter that cannot be reached allows the request.
 */
final class RateLimit
{
    /**
     * Count one request for $key and say whether it is within the limit.
     *
     * @param string $context what is limited, e.g. 'myplugin_api'; at most 40 characters
     * @param string $key     who is limited
     * @param int    $max     requests allowed in each window; <= 0 allows all, uncounted
     * @param int    $windowSeconds
     * @param bool   $failOpen what to answer when the counter cannot be reached: allow, or
     *                         refuse where a missed count would let guessing through
     *
     * @return bool false when this request is over the limit
     */
    public static function hit(string $context, string $key, int $max, int $windowSeconds = 60, bool $failOpen = true): bool
    {
        if ($max <= 0) {
            return true;
        }
        $hits = self::increment($context, $key, $windowSeconds, $failOpen);

        return $hits === null ? $failOpen : $hits <= $max;
    }

    /**
     * Count one request for $key and return the count in the current window, in one query.
     *
     * @param string $context as for hit()
     * @param string $key
     * @param int    $windowSeconds
     * @param bool   $failOpen only changes how an unreachable counter is logged
     *
     * @return int|null null when the counter cannot be reached
     */
    public static function increment(string $context, string $key, int $windowSeconds = 60, bool $failOpen = true): ?int
    {
        $windowSeconds = max(1, $windowSeconds);
        $now           = time();
        $window        = $now - ($now % $windowSeconds);
        $bucket        = self::bucket($context, $key, $windowSeconds);

        // LAST_INSERT_ID(expr) hands the new count back with the insert; a fresh row reports 0.
        $sql = 'INSERT INTO ' . DB_TABLE_PREFIX . 't_rate_counter (s_bucket, i_window, i_expires, i_count) VALUES (?, ?, ?, 1)'
            . ' ON DUPLICATE KEY UPDATE i_count = LAST_INSERT_ID(i_count + 1)';
        $params = array($bucket, $window, $window + $windowSeconds);

        try {
            try {
                $count = osc_db_insert_id($sql, $params);
            } catch (\Throwable $e) {
                // A burst on one key can deadlock its own row; one retry settles that.
                $count = osc_db_insert_id($sql, $params);
            }
        } catch (\Throwable $e) {
            FailOpen::log('RateLimit', 'the request', $e, $failOpen);

            return null;
        }

        return max(1, $count);
    }

    /**
     * How many requests $key has made in the current window, without counting one.
     *
     * @param string $context as passed to hit()
     * @param string $key
     * @param int    $windowSeconds as passed to hit()
     *
     * @return int|null null when the counter cannot be reached
     */
    public static function count(string $context, string $key, int $windowSeconds = 60): ?int
    {
        $windowSeconds = max(1, $windowSeconds);
        $now           = time();
        try {
            return (int) osc_db_scalar(
                'SELECT i_count FROM ' . DB_TABLE_PREFIX . 't_rate_counter WHERE s_bucket = ? AND i_window = ?',
                array(self::bucket($context, $key, $windowSeconds), $now - ($now % $windowSeconds))
            );
        } catch (\Throwable $e) {
            FailOpen::log('RateLimit', 'the request', $e);

            return null;
        }
    }

    /**
     * count() for several keys of one context, in one query.
     *
     * @param string   $context
     * @param string[] $keys
     * @param int      $windowSeconds
     *
     * @return array<string,int>|null key => requests so far; null when the counter cannot be reached
     */
    public static function countMany(string $context, array $keys, int $windowSeconds = 60): ?array
    {
        $windowSeconds = max(1, $windowSeconds);
        $now           = time();
        $buckets       = array();
        foreach ($keys as $key) {
            $buckets[self::bucket($context, (string) $key, $windowSeconds)] = (string) $key;
        }
        $counts = array_fill_keys(array_values($buckets), 0);
        if ($buckets === array()) {
            return $counts;
        }
        try {
            $rows = osc_db_select(
                'SELECT s_bucket, i_count FROM ' . DB_TABLE_PREFIX . 't_rate_counter WHERE i_window = ? AND s_bucket IN ('
                . implode(', ', array_fill(0, count($buckets), '?')) . ')',
                array_merge(array($now - ($now % $windowSeconds)), array_keys($buckets))
            );
        } catch (\Throwable $e) {
            FailOpen::log('RateLimit', 'the request', $e);

            return null;
        }
        foreach ($rows as $row) {
            $counts[$buckets[$row['s_bucket']]] = (int) $row['i_count'];
        }

        return $counts;
    }

    /**
     * @param string $context
     * @param string $key
     * @param int    $windowSeconds
     *
     * @return string
     */
    private static function bucket(string $context, string $key, int $windowSeconds): string
    {
        return substr($context, 0, 40) . ':' . $windowSeconds . ':' . sha1($key);
    }

    /**
     * Drop the windows that have ended, $batch rows at a time so no single delete holds the
     * table for long. Called from the hourly cron.
     *
     * @param int $batch     rows per delete
     * @param int $maxRounds stop after this many batches; the next run carries on
     *
     * @return int rows removed
     */
    public static function prune(int $batch = 5000, int $maxRounds = 100): int
    {
        $batch   = max(1, $batch);
        $sql     = 'DELETE FROM ' . DB_TABLE_PREFIX . 't_rate_counter WHERE i_expires < ? LIMIT ' . $batch;
        $removed = 0;
        try {
            for ($round = 0; $round < $maxRounds; $round++) {
                $rows     = (int) osc_db_execute($sql, array(time()));
                $removed += $rows;
                if ($rows < $batch) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            FailOpen::log('RateLimit', 'the request', $e);
        }

        return $removed;
    }
}
