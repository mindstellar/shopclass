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
 * A page token as handed to a page: the token and when it stops working. Immutable.
 */
final class PageToken
{
    public function __construct(private string $token, private int $expiresAt)
    {
    }

    public function token(): string
    {
        return $this->token;
    }

    /**
     * Unix time the token stops working.
     */
    public function expiresAt(): int
    {
        return $this->expiresAt;
    }
}
