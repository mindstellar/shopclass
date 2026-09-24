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
     *
     * @return bool false when this request is over the limit
     */
    public static function hit(string $context, string $key, int $max, int $windowSeconds = 60): bool
    {
        if ($max <= 0) {
            return true;
        }
        $windowSeconds = max(1, $windowSeconds);
        $now           = time();
        $window        = $now - ($now % $windowSeconds);
        $bucket        = substr($context, 0, 40) . ':' . $windowSeconds . ':' . sha1($key);
        $table         = DB_TABLE_PREFIX . 't_rate_counter';

        $increment = static function () use ($table, $bucket, $window, $windowSeconds): void {
            osc_db_execute(
                'INSERT INTO ' . $table . ' (s_bucket, i_window, i_expires, i_count) VALUES (?, ?, ?, 1)'
                . ' ON DUPLICATE KEY UPDATE i_count = i_count + 1',
                array($bucket, $window, $window + $windowSeconds)
            );
        };

        try {
            try {
                $increment();
            } catch (\Throwable $e) {
                // A burst on one key can deadlock its own row; one retry settles that.
                $increment();
            }
            $hits = (int) osc_db_scalar(
                'SELECT i_count FROM ' . $table . ' WHERE s_bucket = ? AND i_window = ?',
                array($bucket, $window)
            );
        } catch (\Throwable $e) {
            self::unavailable($e);

            return true;
        }

        return $hits <= $max;
    }

    /**
     * Drop the windows that have ended. Called from the daily cron.
     *
     * @return int rows removed
     */
    public static function prune(): int
    {
        try {
            return (int) osc_db_execute(
                'DELETE FROM ' . DB_TABLE_PREFIX . 't_rate_counter WHERE i_expires < ?',
                array(time())
            );
        } catch (\Throwable $e) {
            self::unavailable($e);

            return 0;
        }
    }

    /**
     * The counter could not be reached, so the limit stands aside.
     *
     * @param \Throwable $e
     *
     * @return void
     */
    private static function unavailable(\Throwable $e): void
    {
        FailOpen::log('RateLimit', 'the request', $e);
    }
}
