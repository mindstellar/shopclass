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

/**
 * The web user's signed sign-in cookie as the browser sent it: the user id and the
 * RememberMe token. Unchecked; only the same-site session mode reads it, and verifies it
 * first. Immutable.
 */
final class SignInCookie
{
    public function __construct(private string $userId, private string $secret)
    {
    }

    /**
     * The cookie from the request's cookie values, or null when either half is missing.
     *
     * @param array<string,mixed> $values cookie name => value
     */
    public static function from(array $values): ?self
    {
        $id     = $values['oc_userId'] ?? '';
        $secret = $values['oc_userSecret'] ?? '';
        if (!is_string($id) || !is_string($secret) || !ctype_digit($id) || $secret === '') {
            return null;
        }

        return new self($id, $secret);
    }

    public function userId(): int
    {
        return (int) $this->userId;
    }

    /**
     * The id as the cookie carries it, which the signature covers.
     */
    public function rawUserId(): string
    {
        return $this->userId;
    }

    public function secret(): string
    {
        return $this->secret;
    }
}
