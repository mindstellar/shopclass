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
 * One row of t_api_credential as a value. `owner` is null when the admin or user it belongs
 * to is gone or blocked; times are Unix timestamps. `authStamp` is the owner's sign-out stamp
 * when the row was issued, so a raised stamp ends it even if no sign-out action revoked it.
 */
final class StoredKey
{
    /**
     * @param string[] $scopes
     */
    public function __construct(
        private int $id,
        private string $kind,
        private string $tokenId,
        private string $secretHash,
        private string $name,
        private array $scopes,
        private ?KeyOwner $owner,
        private ?int $rateLimit = null,
        private bool $enabled = true,
        private ?int $expiresAt = null,
        private ?int $revokedAt = null,
        private ?int $lastUsedAt = null,
        private ?string $family = null,
        private ?int $createdAt = null,
        private string $lastIp = '',
        private ?int $authStamp = null
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function tokenId(): string
    {
        return $this->tokenId;
    }

    public function secretHash(): string
    {
        return $this->secretHash;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return string[]
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function owner(): ?KeyOwner
    {
        return $this->owner;
    }

    public function rateLimit(): ?int
    {
        return $this->rateLimit;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?int
    {
        return $this->revokedAt;
    }

    public function lastUsedAt(): ?int
    {
        return $this->lastUsedAt;
    }

    public function family(): ?string
    {
        return $this->family;
    }

    public function createdAt(): ?int
    {
        return $this->createdAt;
    }

    /**
     * The address of its last use, '' when never used.
     */
    public function lastIp(): string
    {
        return $this->lastIp;
    }

    /**
     * The owner's sign-out stamp when it was issued; null for a row from before stamps.
     */
    public function authStamp(): ?int
    {
        return $this->authStamp;
    }

    /**
     * Enabled, not revoked, not expired, its owner may still use it, and the owner has not
     * signed out everywhere since it was issued.
     */
    public function isUsableAt(int $now): bool
    {
        return $this->enabled && $this->revokedAt === null && $this->owner !== null
            && ($this->expiresAt === null || $this->expiresAt > $now)
            && ($this->authStamp === null || $this->owner->stamp() === null || $this->owner->stamp() === $this->authStamp);
    }
}
