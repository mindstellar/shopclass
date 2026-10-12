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

namespace mindstellar\apikey;

use mindstellar\auth\AuthStamp;
use mindstellar\security\SignedPayload;
use mindstellar\utility\Clock;
use mindstellar\utility\SystemClock;

/**
 * Page tokens for the same-site session mode: `scs_<SignedPayload>`, signed with the install's
 * key and stored nowhere. A user's or an admin's payload names them and their sign-out stamp
 * fingerprint, so a password change or sign-out everywhere ends every page token; a guest's names nobody.
 */
final class PageTokens
{
    public const PREFIX = 'scs_';

    /** The only place a page token is read from: never the query string or the body. */
    public const HEADER = 'X-Shopclass-Token';

    /** Seconds a page token lives. */
    public const TTL = 7200;

    /** check(): the token is good. */
    public const VALID = 'valid';
    /** check(): a genuine token for this user, past its expiry. */
    public const EXPIRED = 'expired';
    /** check(): forged, damaged, another user's, or made before a password change. */
    public const REFUSED = 'refused';

    private const PURPOSE = 'api-session';

    private const GUEST_PURPOSE = 'api-guest';

    private const ADMIN_PURPOSE = 'api-admin';

    private Clock $clock;

    public function __construct(private int $ttl = self::TTL, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * A page token for this user.
     *
     * @param array<string,mixed> $user the t_user row
     */
    public function issue(array $user): IssuedToken
    {
        return $this->sign(self::PURPOSE, ['sub' => (int) $user['pk_i_id'], 'st' => AuthStamp::fingerprint($user)]);
    }

    /**
     * A page token for a visitor who is not signed in. It reads public data only.
     */
    public function issueGuest(): IssuedToken
    {
        return $this->sign(self::GUEST_PURPOSE, []);
    }

    /**
     * A page token for this admin, for the admin panel's own pages.
     *
     * @param array<string,mixed> $admin the t_admin row
     */
    public function issueAdmin(array $admin): IssuedToken
    {
        return $this->sign(self::ADMIN_PURPOSE, ['adm' => (int) $admin['pk_i_id'], 'st' => AuthStamp::fingerprint($admin)]);
    }

    /**
     * The token for this web request's page: the signed-in user's, or a guest's.
     */
    public function forPage(): IssuedToken
    {
        return $this->forWebUser() ?? $this->issueGuest();
    }

    /**
     * A page token for the user signed in on this web request, or null when nobody is signed in
     * with the sign-in cookie (an older session-only sign-in cannot make session calls).
     */
    public function forWebUser(): ?IssuedToken
    {
        if ((string) \Cookie::getInstance()->get_value('oc_userId') === '' || !osc_is_web_user_logged_in()) {
            return null;
        }
        $user = osc_resolve_web_user();

        return is_array($user) && isset($user['pk_i_id']) ? $this->issue($user) : null;
    }

    /**
     * A page token for the admin signed in on this admin page, or null when nobody is. An API call
     * has no admin session loaded, so it checks the token with AdminSession instead.
     */
    public function forAdmin(): ?IssuedToken
    {
        if (!osc_is_admin_user_logged_in()) {
            return null;
        }
        $admin = \Admin::getInstance()->findByPrimaryKey(osc_logged_admin_id());

        return is_array($admin) && isset($admin['pk_i_id']) ? $this->issueAdmin($admin) : null;
    }

    /**
     * Whether a token was made by this site for this user and their current password.
     *
     * @param array<string,mixed> $user the t_user row the sign-in cookie names
     *
     * @return string VALID, EXPIRED or REFUSED
     */
    public function check(string $token, array $user): string
    {
        return $this->checkSubject(self::PURPOSE, 'sub', $token, $user);
    }

    /**
     * Whether a token is a guest token this site made.
     *
     * @return string VALID, EXPIRED or REFUSED (also for a user's token)
     */
    public function checkGuest(string $token): string
    {
        $opened = $this->open(self::GUEST_PURPOSE, $token);
        if ($opened === null) {
            return self::REFUSED;
        }

        return $opened['expired'] ? self::EXPIRED : self::VALID;
    }

    /**
     * Whether a token is an admin token this site made, expired or not.
     */
    public function isAdminToken(string $token): bool
    {
        return $this->open(self::ADMIN_PURPOSE, $token) !== null;
    }

    /**
     * Whether an admin token was made for this admin and their current sign-out stamp.
     *
     * @param array<string,mixed> $admin the t_admin row the admin session names
     *
     * @return string VALID, EXPIRED or REFUSED
     */
    public function checkAdmin(string $token, array $admin): string
    {
        return $this->checkSubject(self::ADMIN_PURPOSE, 'adm', $token, $admin);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function sign(string $purpose, array $data): IssuedToken
    {
        $now = $this->clock->now();

        return new IssuedToken(self::PREFIX . SignedPayload::pack($purpose, $data, $this->ttl, 0, $now), $now + $this->ttl);
    }

    /**
     * @return array{data: array<string,mixed>, expired: bool}|null null for another purpose, a forgery or damage
     */
    private function open(string $purpose, string $token): ?array
    {
        return str_starts_with($token, self::PREFIX)
            ? SignedPayload::open($purpose, substr($token, strlen(self::PREFIX)), $this->clock->now())
            : null;
    }

    /**
     * Whether a token names this row, by its id under $key, and the row's current sign-out stamp.
     *
     * @param array<string,mixed> $row
     */
    private function checkSubject(string $purpose, string $key, string $token, array $row): string
    {
        $opened = $this->open($purpose, $token);
        $data   = $opened['data'] ?? null;
        if ($data === null || !is_int($data[$key] ?? null) || !is_string($data['st'] ?? null)
            || $data[$key] !== (int) ($row['pk_i_id'] ?? 0)
            || !hash_equals(AuthStamp::fingerprint($row), $data['st'])
        ) {
            return self::REFUSED;
        }

        return $opened['expired'] ? self::EXPIRED : self::VALID;
    }
}
