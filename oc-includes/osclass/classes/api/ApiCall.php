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

namespace mindstellar\api;

use mindstellar\api\serializer\ViewContext;
use mindstellar\apiaccess\Credential;
use mindstellar\auth\Actor;
use mindstellar\listing\ListingPolicy;

/**
 * One call to an endpoint, as its handler gets it: the request, who is calling, the values
 * the path matched, and what the route's prepare step returned.
 */
final class ApiCall
{
    /**
     * @param array<string,string>     $args     the path's {name} values
     * @param mixed                    $prepared what the route's prepare step returned
     * @param (\Closure(): ApiKit)|null $kit      builds the services kit()
     */
    public function __construct(
        private Request $request,
        private Credential $credential,
        private array $args = [],
        private mixed $prepared = null,
        private ?\Closure $kit = null
    ) {
    }

    /** @api */
    public function request(): Request
    {
        return $this->request;
    }

    /** @api */
    public function credential(): Credential
    {
        return $this->credential;
    }

    /**
     * The calling user's id; 0 when the credential has none.
     *
     * @api
     */
    public function userId(): int
    {
        return (int) $this->credential->userId();
    }

    /**
     * The caller as an actor for core services; an admin key holding $scope acts as an admin.
     *
     * @api
     */
    public function actor(string $scope = ''): Actor
    {
        return $this->credential->actor($this->request->ip(), $scope);
    }

    /**
     * The caller as a listing viewer: an admin key with the listings scope sees every listing.
     *
     * @api
     */
    public function listingActor(): Actor
    {
        return $this->actor(ViewContext::LISTINGS_SCOPE);
    }

    /**
     * Whether the caller may see this listing row; false for a missing row.
     *
     * @api
     *
     * @param array<string,mixed>|null $row
     */
    public function canViewListing(?array $row): bool
    {
        return $row !== null && ListingPolicy::canView($row, $this->listingActor());
    }

    /**
     * $row when the caller may see it, else null.
     *
     * @api
     *
     * @param array<string,mixed>|null $row
     *
     * @return array<string,mixed>|null
     */
    public function visibleListing(?array $row): ?array
    {
        return $this->canViewListing($row) ? $row : null;
    }

    /**
     * @api
     *
     * @return array<string,string>
     */
    public function args(): array
    {
        return $this->args;
    }

    /**
     * A {name} value from the path, or null when the route has none by that name.
     *
     * @api
     */
    public function arg(string $name): ?string
    {
        return $this->args[$name] ?? null;
    }

    /**
     * A {name} value as a row id, or 0 when it is missing or not digits, which finds no row.
     *
     * @api
     */
    public function intArg(string $name = 'id'): int
    {
        return RowId::parse($this->args[$name] ?? '') ?? 0;
    }

    /**
     * The decoded JSON body, or [] when there is none.
     *
     * @api
     *
     * @return array<mixed>
     * @throws ProblemException 413, 415 or 400 when a body was sent that cannot be used
     */
    public function input(): array
    {
        return $this->request->input();
    }

    /**
     * What the route's prepare step returned; null when it has none.
     */
    public function prepared(): mixed
    {
        return $this->prepared;
    }

    /**
     * Core's API services a plugin handler may use.
     *
     * @api
     * @throws \LogicException when the call was built without them, as in a unit test
     */
    public function kit(): ApiKit
    {
        if ($this->kit === null) {
            throw new \LogicException('This call has no service kit.');
        }

        return ($this->kit)();
    }
}
