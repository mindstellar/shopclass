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

use mindstellar\auth\Actor;

/**
 * Who is calling: nobody, a public key, an API key (an admin's or a user's), a signed-in
 * user's access token, or a signed-in web user on the site's own pages. Immutable.
 */
final class Credential
{
    /**
     * @param string   $kind      a CredentialKind
     * @param string[] $scopes
     * @param int|null $id        its t_api_credential row
     * @param int|null $rateLimit requests a minute, when the key overrides the default
     * @param string|null $family the sign-in an access token belongs to, its refresh family
     */
    public function __construct(
        private string $kind,
        private array $scopes,
        private ?int $userId = null,
        private ?int $adminId = null,
        private ?int $id = null,
        private ?int $rateLimit = null,
        private string $label = '',
        private bool $moderator = false,
        private ?string $family = null
    ) {
        $this->scopes = array_values($scopes);
    }

    /**
     * No credential. It holds the public read scope only when the site allows anonymous reads.
     *
     * @param string[] $scopes
     */
    public static function anonymous(array $scopes = []): self
    {
        return new self(CredentialKind::ANONYMOUS, $scopes);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * @return string[]
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function adminId(): ?int
    {
        return $this->adminId;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function rateLimit(): ?int
    {
        return $this->rateLimit;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * The refresh family of a signed-in user's access token; null for any other credential.
     */
    public function family(): ?string
    {
        return $this->family;
    }

    /**
     * A signed-in user's access token, as opposed to a key.
     */
    public function isAccessToken(): bool
    {
        return $this->kind === CredentialKind::USER && $this->userId !== null;
    }

    /**
     * A signed-in web user calling from the site's own pages (cookie plus page token).
     */
    public function isSession(): bool
    {
        return $this->kind === CredentialKind::SESSION && $this->userId !== null;
    }

    public function isAnonymous(): bool
    {
        return $this->kind === CredentialKind::ANONYMOUS;
    }

    /**
     * An admin's API key. A public key made by an admin is not one.
     */
    public function isAdmin(): bool
    {
        return $this->kind === CredentialKind::KEY && $this->adminId !== null;
    }

    public function isModerator(): bool
    {
        return $this->isAdmin() && $this->moderator;
    }

    /**
     * A user's API key, access token or same-site session.
     */
    public function isUser(): bool
    {
        return in_array($this->kind, [CredentialKind::KEY, CredentialKind::USER, CredentialKind::SESSION], true) && $this->userId !== null;
    }

    public function has(string $scope): bool
    {
        return Scopes::implies($this->scopes, $scope);
    }

    /**
     * The caller as core services take it: the user it signs in, and the admin only when
     * the key holds $adminScope.
     *
     * @param string $ip         the request's address
     * @param string $adminScope the admin scope that grants admin rights here; '' for none
     */
    public function actor(string $ip, string $adminScope = ''): Actor
    {
        $admin = $adminScope !== '' && $this->isAdmin() && $this->has($adminScope) ? (int) $this->adminId : null;

        return new Actor($this->isUser() ? $this->userId : null, $admin, $ip);
    }
}
