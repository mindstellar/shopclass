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

namespace mindstellar\api\auth;

use mindstellar\auth\AuthStamp;
use mindstellar\security\SignedPayload;

/**
 * Page tokens for the same-site session mode: `scs_<SignedPayload>`, signed with the
 * install's key and stored nowhere. A page token is the CSRF half of a session call; the
 * signed-in cookie is the other half, so neither works alone.
 *
 * The payload names the user and the fingerprint of their sign-out stamp,
 * as access tokens do, so a password change or signing out of all devices ends every page
 * token at once.
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

    public function __construct(private int $ttl = self::TTL)
    {
    }

    /**
     * A page token for this user.
     *
     * @param array<string,mixed> $user the t_user row
     */
    public function issue(array $user): PageToken
    {
        // SignedPayload stamps the expiry from time(), so the answer counts from it too.
        $expiresAt = time() + $this->ttl;
        $token = self::PREFIX . SignedPayload::pack(self::PURPOSE, [
            'sub' => (int) $user['pk_i_id'],
            'st'  => AuthStamp::fingerprint($user),
        ], $this->ttl);

        return new PageToken($token, $expiresAt);
    }

    /**
     * A page token for the user signed in on this web request, or null when nobody is signed in
     * with the sign-in cookie (an older session-only sign-in cannot make session calls).
     */
    public function forWebUser(): ?PageToken
    {
        if ((string) \Cookie::newInstance()->get_value('oc_userId') === '' || !osc_is_web_user_logged_in()) {
            return null;
        }
        $user = osc_resolve_web_user();

        return is_array($user) && isset($user['pk_i_id']) ? $this->issue($user) : null;
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
        if (!str_starts_with($token, self::PREFIX)) {
            return self::REFUSED;
        }
        $opened = SignedPayload::open(self::PURPOSE, substr($token, strlen(self::PREFIX)));
        $data   = $opened['data'] ?? null;
        if ($data === null || !is_int($data['sub'] ?? null) || !is_string($data['st'] ?? null)
            || $data['sub'] !== (int) ($user['pk_i_id'] ?? 0)
            || !hash_equals(AuthStamp::fingerprint($user), $data['st'])
        ) {
            return self::REFUSED;
        }

        return $opened['expired'] ? self::EXPIRED : self::VALID;
    }
}
