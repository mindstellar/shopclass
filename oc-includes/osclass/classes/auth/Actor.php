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

namespace mindstellar\auth;

/**
 * Who is acting, as a controller hands it to a service: a signed-in user, an admin, both, or a
 * guest, with their address and, for a guest listing, the edit secret they sent.
 */
final class Actor
{
    private ?int $userId;
    private ?int $adminId;

    public function __construct(?int $userId, ?int $adminId, private string $ip = '', private string $secret = '')
    {
        $this->userId  = $userId !== null && $userId > 0 ? $userId : null;
        $this->adminId = $adminId !== null ? max(0, $adminId) : null;
    }

    public static function guest(string $ip = '', string $secret = ''): self
    {
        return new self(null, null, $ip, $secret);
    }

    public static function user(int $userId, string $ip = ''): self
    {
        return new self($userId, null, $ip);
    }

    /**
     * An admin, or admin rights with no admin signed in (0), as for an import or the CLI.
     */
    public static function admin(int $adminId, string $ip = ''): self
    {
        return new self(null, $adminId, $ip);
    }

    /**
     * The signed-in admin, or the signed-in user, from this request's address.
     */
    public static function fromSession(bool $admin): self
    {
        $ip = (string) \Params::getServerParam('REMOTE_ADDR');

        return $admin ? self::admin((int) osc_logged_admin_id(), $ip) : new self((int) osc_logged_user_id(), null, $ip);
    }

    /**
     * The same actor, sending a guest listing's edit secret.
     */
    public function withSecret(string $secret): self
    {
        $copy         = clone $this;
        $copy->secret = $secret;

        return $copy;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function adminId(): ?int
    {
        return $this->adminId;
    }

    public function isAdmin(): bool
    {
        return $this->adminId !== null;
    }

    public function isGuest(): bool
    {
        return $this->userId === null && $this->adminId === null;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * 'admin' or 'user', as the action log records who did something.
     */
    public function logRole(): string
    {
        return $this->isAdmin() ? 'admin' : 'user';
    }

    /**
     * The id the action log records with logRole(); 0 for a guest.
     */
    public function logId(): int
    {
        return (int) ($this->isAdmin() ? $this->adminId : $this->userId);
    }
}
