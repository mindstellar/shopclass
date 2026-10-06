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

namespace mindstellar\apiaccess;

/**
 * The outcome of checking a token: the credential, or a refusal that says whether the token
 * named a key that exists (a stale or wrong key) or none at all (a guess or garbage). An
 * access token with a good signature that is expired or no longer fits its user is genuine:
 * nobody guessed it, so it is not counted as a failure.
 */
final class KeyCheck
{
    private function __construct(private ?Credential $credential, private bool $known, private bool $genuine = false, private bool $expired = false)
    {
    }

    /**
     * A signed access token that cannot be used: expired, or its user changed since.
     */
    public static function stale(bool $expired): self
    {
        return new self(null, false, true, $expired);
    }

    /** Whether a refused token was signed by this site, so not a guess. */
    public function genuine(): bool
    {
        return $this->genuine;
    }

    /** Whether a genuine token was refused only for being past its expiry. */
    public function expired(): bool
    {
        return $this->expired;
    }

    public static function accepted(Credential $credential): self
    {
        return new self($credential, true);
    }

    public static function refused(bool $known): self
    {
        return new self(null, $known);
    }

    public function credential(): ?Credential
    {
        return $this->credential;
    }

    /** Whether the token's key id belongs to a stored key. */
    public function known(): bool
    {
        return $this->known;
    }
}
