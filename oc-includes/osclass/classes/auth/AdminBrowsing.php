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

namespace mindstellar\auth;

use Cookie;

/**
 * A signed cookie that tells the site's own API calls an admin is browsing, for previews only:
 * it grants no admin power. It carries the admin id, the sign-out stamp, the session and an expiry.
 */
final class AdminBrowsing
{
    public const COOKIE = 'oc_admin_browsing';

    private const PURPOSE = 'admin-browsing';

    /** Seconds a cookie stays valid; page requests renew it once less than half is left. */
    public const TTL = 3600;

    /** The session cookie core's Session class sets. */
    private const SESSION_COOKIE = 'osclass';

    /** @var array<string,bool> token => verified, so a request looks the admin up once */
    private static array $verified = [];

    private function __construct()
    {
    }

    /**
     * Set the cookie for this admin.
     *
     * @param array<string,mixed> $admin the t_admin row
     */
    public static function issue(array $admin, ?int $now = null): void
    {
        $now   = $now ?? time();
        $token = self::token($admin, $now);
        $_COOKIE[self::COOKIE] = $token;
        Cookie::write('oc_admin_browsing', $token, $now + self::TTL);
    }

    /**
     * The cookie value for this admin.
     *
     * @param array<string,mixed> $admin the t_admin row
     */
    public static function token(array $admin, ?int $now = null): string
    {
        return \mindstellar\security\SignedPayload::pack(
            self::PURPOSE,
            ['a' => (int) ($admin['pk_i_id'] ?? 0), 's' => AuthStamp::fingerprint($admin), 'h' => self::session()],
            self::TTL,
            0,
            $now
        );
    }

    /**
     * Drop the cookie.
     */
    public static function clear(): void
    {
        if (isset($_COOKIE[self::COOKIE])) {
            unset($_COOKIE[self::COOKIE]);
        }
        self::$verified = [];
        Cookie::write('oc_admin_browsing', '', time() - 3600);
    }

    /**
     * On a page request: renew the cookie while the admin session lives, and drop a cookie
     * the session no longer backs.
     *
     * @param int $adminId the signed-in admin, 0 when none
     */
    public static function keep(int $adminId, ?int $now = null): void
    {
        $now   = $now ?? time();
        $token = self::cookie();
        if ($adminId < 1) {
            if ($token !== '') {
                self::clear();
            }

            return;
        }
        $data = $token === '' ? null : \mindstellar\security\SignedPayload::unpack(self::PURPOSE, $token, $now + intdiv(self::TTL, 2));
        if ($data !== null && (int) ($data['a'] ?? 0) === $adminId && ($data['h'] ?? null) === self::session()) {
            return;
        }
        $admin = AdminStore::find($adminId, ['pk_i_id', AuthStamp::COLUMN]);
        if ($admin !== null) {
            self::issue($admin, $now);
        }
    }

    /**
     * Whether the request carries the cookie at all, checked or not.
     */
    public static function present(): bool
    {
        return self::cookie() !== '';
    }

    /**
     * Whether the request's cookie is genuine, unexpired and names an admin whose sign-out stamp
     * still matches. One admin lookup, only when the cookie is there.
     */
    public static function verified(?int $now = null): bool
    {
        $token = self::cookie();
        if ($token === '') {
            return false;
        }
        if (isset(self::$verified[$token])) {
            return self::$verified[$token];
        }
        $data = \mindstellar\security\SignedPayload::unpack(self::PURPOSE, $token, $now);
        $id   = (int) ($data['a'] ?? 0);
        $ok   = false;
        if ($id > 0 && is_string($data['s'] ?? null) && self::session() !== '' && ($data['h'] ?? null) === self::session()) {
            $admin = AdminStore::find($id, ['pk_i_id', AuthStamp::COLUMN]);
            $ok    = $admin !== null && hash_equals(AuthStamp::fingerprint($admin), $data['s']);
        }

        return self::$verified[$token] = $ok;
    }

    /**
     * Forget what verified() remembered, for tests.
     */
    public static function reset(): void
    {
        self::$verified = [];
    }

    /**
     * A fingerprint of the browser's session id, so the cookie is worthless with any other session.
     */
    private static function session(): string
    {
        $id = session_status() === PHP_SESSION_ACTIVE ? session_id() : ($_COOKIE[self::SESSION_COOKIE] ?? '');
        if (!is_string($id) || $id === '') {
            return '';
        }

        return substr(hash('sha256', 'admin-browsing|' . $id), 0, 16);
    }

    private static function cookie(): string
    {
        $token = $_COOKIE[self::COOKIE] ?? '';

        return is_string($token) ? $token : '';
    }
}
