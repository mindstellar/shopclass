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
     *
     * Admin rights with no admin signed in (admin 0) are kept for the command line, the admin
     * area, and scheduled jobs and cron (see runAsSystem()). Anywhere else, such as a plugin
     * calling new ItemActions(true) from a public page, the caller is the visitor, so the usual
     * sign-in, ban and moderation checks apply.
     */
    public static function fromSession(bool $admin): self
    {
        $ip = (string) \Params::getServerParam('REMOTE_ADDR');
        if (!$admin) {
            return new self((int) osc_logged_user_id(), null, $ip);
        }
        $adminId = (int) osc_logged_admin_id();
        if ($adminId > 0 || self::systemContext()) {
            return self::admin($adminId, $ip);
        }

        return new self(osc_logged_user_id() > 0 ? (int) osc_logged_user_id() : null, null, $ip);
    }

    /** How many runAsSystem() calls are running now. */
    private static int $system = 0;

    /** The server API this request runs under; a property so a test can stand in for a web request. */
    private static string $sapi = PHP_SAPI;

    /**
     * Run $work as the site itself: a scheduled job or cron task may act with admin rights
     * although no admin is signed in.
     *
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    public static function runAsSystem(callable $work): mixed
    {
        self::$system++;
        try {
            return $work();
        } finally {
            self::$system--;
        }
    }

    /**
     * Whether admin rights without a signed-in admin are expected here.
     */
    private static function systemContext(): bool
    {
        return self::$system > 0 || self::$sapi === 'cli' || (defined('OC_ADMIN') && OC_ADMIN === true);
    }

    /**
     * The visitor on a public page: the signed-in user, if any, from this request's address.
     */
    public static function visitor(string $secret = ''): self
    {
        return self::onPage(false, $secret);
    }

    /**
     * The visitor, or a signed-in admin, who may see and manage any listing.
     */
    public static function visitorOrAdmin(string $secret = ''): self
    {
        return self::onPage(true, $secret);
    }

    private static function onPage(bool $withAdmin, string $secret): self
    {
        return new self(
            osc_is_web_user_logged_in() ? (int) osc_logged_user_id() : null,
            $withAdmin && osc_is_admin_user_logged_in() ? (int) osc_logged_admin_id() : null,
            (string) \Params::getServerParam('REMOTE_ADDR'),
            $secret
        );
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

    /** @api */
    public function userId(): ?int
    {
        return $this->userId;
    }

    /** @api */
    public function adminId(): ?int
    {
        return $this->adminId;
    }

    /** @api */
    public function isAdmin(): bool
    {
        return $this->adminId !== null;
    }

    /** @api */
    public function isGuest(): bool
    {
        return $this->userId === null && $this->adminId === null;
    }

    /** @api */
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
