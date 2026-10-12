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

namespace mindstellar\api\identity;

use mindstellar\api\auth\MemoisedRows;
use mindstellar\auth\AuthStamp;

/**
 * The admin signed in to the admin panel in this browser, read from the PHP session for an admin
 * page token. The session is read once, without a lock, and never written or sent back.
 */
final class AdminSession
{
    /** The session cookie core's Session class sets. */
    private const COOKIE = 'osclass';

    private function __construct()
    {
    }

    /**
     * The admin row, when the session names an admin whose sign-out stamp still matches.
     *
     * @return array<string,mixed>|null
     */
    public static function admin(MemoisedRows $admins): ?array
    {
        $data  = self::read();
        $id    = (int) ($data['adminId'] ?? 0);
        $admin = $id > 0 ? $admins->find($id) : null;
        if ($admin === null || !isset($data['adminStamp']) || (int) $data['adminStamp'] !== AuthStamp::of($admin)) {
            return null;
        }

        return $admin;
    }

    /**
     * @return array<string,mixed>
     */
    private static function read(): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return $_SESSION;
        }
        $id = $_COOKIE[self::COOKIE] ?? '';
        if (session_status() !== PHP_SESSION_NONE || !is_string($id) || preg_match('/^[a-zA-Z0-9,\-]{22,256}$/', $id) !== 1) {
            return [];
        }
        session_id($id);
        // No cookie, no cache headers and no write: this answer must not change the browser's session.
        $started = @session_start(['read_and_close' => true, 'use_cookies' => 0, 'cache_limiter' => '', 'use_strict_mode' => 1]);
        $data    = $started && is_array($_SESSION ?? null) ? $_SESSION : [];
        $_SESSION = [];
        session_id('');

        return $data;
    }
}
