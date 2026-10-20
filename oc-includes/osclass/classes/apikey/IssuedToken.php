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

/**
 * A token just handed out: a key, a refresh token or a page token. The token is shown once
 * and stored, if at all, only as a hash. Fields a kind does not have are null.
 */
final class IssuedToken
{
    /**
     * @param string[] $scopes
     * @param int|null $expiresAt Unix time; null for a key that never expires
     * @param int|null $id        a key's t_api_credential row
     * @param string|null $tokenId a key's public token half
     * @param int|null $userId    a refresh token's user
     * @param string|null $family a refresh token's sign-in
     */
    public function __construct(
        private string $token,
        private ?int $expiresAt = null,
        private array $scopes = [],
        private ?int $id = null,
        private ?string $tokenId = null,
        private ?int $userId = null,
        private ?string $family = null
    ) {
    }

    public function token(): string
    {
        return $this->token;
    }

    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }

    /**
     * @return string[]
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tokenId(): ?string
    {
        return $this->tokenId;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function family(): ?string
    {
        return $this->family;
    }
}
