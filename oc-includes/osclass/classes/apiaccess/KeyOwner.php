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
 * Who a stored credential acts for: an admin (maybe a moderator) or a user, with their
 * current sign-out stamp when it is known.
 */
final class KeyOwner
{
    private function __construct(private ?int $adminId, private ?int $userId, private bool $moderator, private ?int $stamp = null)
    {
    }

    public static function admin(int $id, bool $moderator = false, ?int $stamp = null): self
    {
        return new self($id, null, $moderator, $stamp);
    }

    public static function user(int $id, ?int $stamp = null): self
    {
        return new self(null, $id, false, $stamp);
    }

    public function isAdmin(): bool
    {
        return $this->adminId !== null;
    }

    public function isModerator(): bool
    {
        return $this->moderator;
    }

    public function adminId(): ?int
    {
        return $this->adminId;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    /**
     * The owner's sign-out stamp (AuthStamp) now, or null when not loaded.
     */
    public function stamp(): ?int
    {
        return $this->stamp;
    }
}
