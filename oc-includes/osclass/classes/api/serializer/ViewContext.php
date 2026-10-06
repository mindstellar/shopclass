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

namespace mindstellar\api\serializer;

use mindstellar\apiaccess\Credential;

/**
 * Who a resource is shaped for and how: the caller, the view it gets (public, owner or
 * admin), the locale, the sparse fieldset and the includes. The `api_listing`, `api_user`
 * and `api_category` filters receive it as their third argument.
 */
final class ViewContext
{
    public const PUBLIC = 'public';
    public const OWNER  = 'owner';
    public const ADMIN  = 'admin';

    public const VIEWS = [self::PUBLIC, self::OWNER, self::ADMIN];

    /** The scope that gives the admin view of a listing. */
    public const LISTINGS_SCOPE = 'admin:listings';
    /** The scope that gives the admin view of a user. */
    public const USERS_SCOPE = 'admin:users';
    /** The scope that gives the admin view of a category. */
    public const TAXONOMY_SCOPE = 'admin:taxonomy';

    /**
     * @param string[] $include
     */
    public function __construct(
        private Credential $viewer,
        private string $locale,
        private ?SparseFieldset $fields = null,
        private array $include = [],
        private string $view = self::PUBLIC
    ) {
        $this->include = array_values($include);
    }

    /**
     * The view this caller gets of a resource; see viewOf().
     */
    public function viewFor(?int $ownerId, string $adminScope): string
    {
        return self::viewOf($this->viewer, $ownerId, $adminScope);
    }

    /**
     * The view a caller gets of a resource: admin only for an admin key holding the
     * resource's admin scope (`admin:listings`, `admin:users`), owner for the user it
     * belongs to, public for everyone else.
     */
    public static function viewOf(Credential $viewer, ?int $ownerId, string $adminScope): string
    {
        if ($viewer->isAdmin() && in_array($adminScope, $viewer->scopes(), true)) {
            return self::ADMIN;
        }
        if ($ownerId !== null && $viewer->isUser() && $viewer->userId() === $ownerId) {
            return self::OWNER;
        }

        return self::PUBLIC;
    }

    public function withView(string $view): self
    {
        $copy       = clone $this;
        $copy->view = in_array($view, self::VIEWS, true) ? $view : self::PUBLIC;

        return $copy;
    }

    public function view(): string
    {
        return $this->view;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function fields(): ?SparseFieldset
    {
        return $this->fields;
    }

    /**
     * Whether the fieldset keeps a top-level member, so a serializer can skip building it.
     */
    public function wants(string $member): bool
    {
        return $this->fields === null || $this->fields->wants($member);
    }

    /**
     * @return string[]
     */
    public function include(): array
    {
        return $this->include;
    }

    public function includes(string $name): bool
    {
        return in_array($name, $this->include, true);
    }
}
