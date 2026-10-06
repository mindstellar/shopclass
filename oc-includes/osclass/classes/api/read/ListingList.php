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
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT     = 100;

    private ListingQuery $listings;

    public function __construct(private ApiServices $api, private ListingReader $reader)
    {
        $this->listings = new ListingQuery($api->clock());
    }

    /**
     * @param string   $path     the endpoint, below /api/v1/, for the page links and the cursor
     * @param string[] $statuses names from ListingStatus::ALL; all when empty
     */
    public function run(Request $request, Credential $credential, string $path, array $statuses, ?int $userId, ?int $categoryId = null, string $title = ''): Response
    {
        $context = $this->api->context($request, $credential, 'listing', ListingSerializer::MEMBERS, ListingSerializer::INCLUDES);
        $pager   = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId('desc', self::DEFAULT_LIMIT, self::MAX_LIMIT), ['list' => $path] + $request->query());
        $total   = $pager->counts() ? $this->listings->count($statuses, $userId, $categoryId, $title) : null;
        $after   = $pager->after();
        $rows    = $this->listings->newest($statuses, $userId, $categoryId, $title, $after === null ? null : (int) $after[0], $pager->limit() + 1);
        $items   = $pager->page($rows);
        $items   = $items === [] ? [] : \Item::getInstance()->extendRows($items, $context->locale());

        return (new Page($this->reader->many($items, $context), $total, $pager->limit(), $pager->next($rows), $pager->truncated($rows)))
            ->response($this->api->links(), $path, $request->query());
    }
}
