<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\security;

/**
 * Stateless, HMAC-signed "remember me" tokens.
 *
 * The persistent login cookie carries only the account id and a signed token — nothing is
 * stored server-side, so there is no per-account secret to leak, rotate, or collide with the
 * password-reset / activation codes that also live in s_secret.
 *
 * A token binds the account id, an expiry, and the account's current password hash, all signed
 * with the install signing key ({@see SigningKey}). The password hash goes into the signed
 * message only, never into the cookie, so verification recomputes the signature from the stored
 * hash: changing the password (or any reset that rewrites it) silently invalidates every
 * outstanding remember-me cookie for that account — "log out everywhere", with no storage.
 *
 * Trade-off: revocation is all-or-nothing per account (there is no per-device series), which is
 * the deliberate cost of staying storage-free.
 */
class RememberMe
{
    /**
     * Payload layout version. Bump to invalidate every outstanding token on a format change.
     */
    private const VERSION = '1';

    /**
     * Issue a token for a freshly authenticated account.
     *
     * @param string     $context      'admin' or 'web' — domain separation so an admin token
     *                                 cannot be replayed as a web-user token (or the reverse)
     *                                 for the same id.
     * @param int|string $id           account primary key
     * @param string     $passwordHash the account's current s_password
     * @param int        $lifetime     seconds until the token expires
     *
     * @return string the value to store in the cookie
     */
    public static function issue($context, $id, $passwordHash, $lifetime)
    {
        $expires = time() + (int)$lifetime;

        return $expires . '.' . self::sign($context, (string)$id, $expires, (string)$passwordHash);
    }

    /**
     * Verify a token taken from the cookie against the account's current password hash.
     *
     * @param string     $context      must match the context the token was issued under
     * @param int|string $id           account primary key from the cookie
     * @param string     $token        the cookie value produced by {@see issue()}
     * @param string     $passwordHash the account's current s_password
     *
     * @return bool
     */
    public static function verify($context, $id, $token, $passwordHash)
    {
        if ($token === '' || strpos($token, '.') === false) {
            return false;
        }
        [$expires, $signature] = explode('.', $token, 2);
        if (!ctype_digit($expires) || (int)$expires < time()) {
            return false;
        }

        return hash_equals(
            self::sign($context, (string)$id, (int)$expires, (string)$passwordHash),
            $signature
        );
    }

    /**
     * HMAC-SHA256 over the token payload under the install signing key.
     *
     * @param string $context
     * @param string $id
     * @param int    $expires
     * @param string $passwordHash
     *
     * @return string
     */
    private static function sign($context, $id, $expires, $passwordHash)
    {
        $message = implode('|', array(self::VERSION, $context, $id, $expires, $passwordHash));

        return hash_hmac('sha256', $message, SigningKey::get());
    }
}
