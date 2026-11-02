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
 * The log line a limiter writes when it cannot reach its store and so allows (or refuses) the request.
 * Once per limiter per request, so a run of requests against a broken store cannot fill
 * the log.
 */
final class FailOpen
{
    /** @var array<string,bool> limiters that have logged this request */
    private static $logged = array();

    /**
     * @param string     $limiter e.g. 'RateLimit'
     * @param string     $allowed what it lets through, e.g. 'the request'
     * @param \Throwable $e
     * @param bool       $allow   false when the limiter refuses instead
     *
     * @return void
     */
    public static function log(string $limiter, string $allowed, \Throwable $e, bool $allow = true): void
    {
        if (isset(self::$logged[$limiter])) {
            return;
        }
        self::$logged[$limiter] = true;
        error_log($limiter . ' unavailable, ' . ($allow ? 'allowing ' : 'refusing ') . $allowed . ': ' . $e->getMessage());
    }
}
