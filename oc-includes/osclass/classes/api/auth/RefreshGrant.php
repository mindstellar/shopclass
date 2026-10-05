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

/**
 * A refresh token just handed out: whose, which sign-in, which scopes, and until when. The
 * token is shown once and stored only as a hash.
 */
final class RefreshGrant
{
    /**
     * @param string[] $scopes
     * @param int      $expiresAt Unix time
     */
    public function __construct(
        private int $userId,
        private string $family,
        private array $scopes,
        private string $token,
        private int $expiresAt
    ) {
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function family(): string
    {
        return $this->family;
    }

    /**
     * @return string[]
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }
}
