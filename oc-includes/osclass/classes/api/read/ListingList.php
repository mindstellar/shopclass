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

namespace mindstellar\api\read;

use mindstellar\api\ApiServices;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\apiaccess\Credential;
use mindstellar\listing\ListingQuery;

/**
 * A page of listings in any status, newest first and paged by id, read through ListingQuery
 * rather than search. Behind `GET /admin/listings` and `GET /account/listings`.
 */
final class ListingList
{
    private ListingQuery $listings;

    public function __construct(private ApiServices $api, private ListingReader $reader)
    {
        $this->listings = new ListingQuery($api->clock());
    }

    /**
     * @param string   $path     the endpoint, below /api/v1/, for the page links and the cursor
     * @param string[] $statuses    names from ListingStatus::ALL; all when empty
     * @param int[]    $userIds     only these sellers; any when empty
     * @param int[]    $categoryIds only these categories; any when empty
     */
    public function run(Request $request, Credential $credential, string $path, array $statuses, array $userIds, array $categoryIds = [], string $title = ''): Response
    {
        $context = $this->api->context($request, $credential, 'listing', ListingSerializer::MEMBERS, ListingSerializer::INCLUDES);
        $pager   = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId(), ['list' => $path] + $request->query());

        return $pager->respond(
            fn (): array => $this->listings->newest($statuses, $userIds, $categoryIds, $title, $pager->afterId(), $pager->limit() + 1),
            fn (): int => $this->listings->count($statuses, $userIds, $categoryIds, $title),
            fn (array $items): array => $this->reader->many($this->reader->extend($items, $context), $context),
            $this->api->links(),
            $path,
            $request->query(),
            $request->version()
        );
    }
}
