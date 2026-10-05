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

namespace mindstellar\api\write;

/**
 * A photo kept for a listing not made yet, behind its token.
 */
final class StagedPhoto
{
    /**
     * @param int $expiresAt Unix time the token stops working
     */
    public function __construct(private string $token, private string $path, private int $expiresAt)
    {
    }

    public function token(): string
    {
        return $this->token;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }
}
