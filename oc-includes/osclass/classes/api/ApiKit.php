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

use mindstellar\api\serializer\Links;
use mindstellar\api\serializer\ViewContext;
use mindstellar\listing\ListingQuery;

/**
 * What a plugin handler may use of core's API services, through ApiCall::kit(): listings,
 * the site's links and the caller's view context.
 */
final class ApiKit
{
    public function __construct(private ApiServices $services)
    {
    }

    /**
     * The view context for this call: the caller, version, `locale`, `fields` and `include`.
     *
     * @param string   $object   listing, user or category
     * @param string[] $members  the object's top-level members
     * @param string[] $includes the names `include=` may switch on
     *
     * @throws ProblemException 400 or 422 for an unknown field, include or locale
     *
     * @api
     */
    public function context(ApiCall $call, string $object, array $members, array $includes = []): ViewContext
    {
        return $this->services->context($call->request(), $call->credential(), $object, $members, $includes);
    }

    /**
     * One listing in the context's view, or null when there is none or the caller may not see
     * it. A hidden one (pending, disabled, spam) is seen only by its owner and by admin keys with `admin:listings`.
     *
     * @api
     *
     * @return array<string,mixed>|null
     */
    public function listing(ApiCall $call, int $id, ViewContext $context): ?array
    {
        $reader = $this->services->listingReader();
        $item   = $reader->row($id, static fn (array $row): ?array => $call->visibleListing($row));

        return $item === null ? null : $reader->view($item, $context);
    }

    /**
     * Listings by id in the context's view, in the order given. Missing ones and those the
     * caller may not see are left out.
     *
     * @api
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     */
    public function listingsById(ApiCall $call, array $ids, ViewContext $context): array
    {
        $ids   = array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        $found = (new ListingQuery())->findMany($ids);
        $rows  = [];
        foreach ($ids as $id) {
            if ($call->canViewListing($found[$id] ?? null)) {
                $rows[] = $found[$id];
            }
        }
        if ($rows === []) {
            return [];
        }
        $reader = $this->services->listingReader();

        return $reader->many($reader->extend($rows, $context), $context);
    }

    /** @api */
    public function links(): Links
    {
        return $this->services->links();
    }
}
