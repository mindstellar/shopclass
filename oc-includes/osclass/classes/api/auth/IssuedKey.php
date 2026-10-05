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
 * A key just made. The token is shown once and never stored.
 */
final class IssuedKey
{
    /**
     * @param string[] $scopes
     */
    public function __construct(private int $id, private string $tokenId, private string $token, private array $scopes)
    {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function tokenId(): string
    {
        return $this->tokenId;
    }

    public function token(): string
    {
        return $this->token;
    }

    /**
     * @return string[]
     */
    public function scopes(): array
    {
        return $this->scopes;
    }
}
