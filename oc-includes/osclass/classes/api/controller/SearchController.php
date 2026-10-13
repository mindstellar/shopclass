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

namespace mindstellar\api\controller;

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\Response;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\search\SearchSuggestions;

/**
 * Suggestions while someone types: GET /search/suggest and GET /search/categories.
 */
final class SearchController
{
    public const DEFAULT_LIMIT = 5;

    public function __construct(private ApiServices $api)
    {
    }

    /**
     * Listings for a search box, each with its title, link and first thumbnail.
     */
    public function suggest(ApiCall $call): Response
    {
        $request = $call->request();
        $found   = SearchSuggestions::forQuery($request->queryString('q'), $request->queryInt('limit', self::DEFAULT_LIMIT));
        $context = $this->api->context($request, $call->credential(), 'listing', ListingSerializer::MEMBERS);
        $reader  = $this->api->listingReader();
        $shaped  = $found['listings'] === [] ? [] : $reader->many($reader->extend($found['listings'], $context), $context);

        return Response::ok([
            'listings' => array_map(static fn (array $l): array => [
                'id'        => $l['id'],
                'title'     => $l['title'] ?? '',
                'url'       => $l['url'] ?? '',
                'thumbnail' => $l['photos'][0]['thumbnail'] ?? null,
                'price'     => $l['price']['formatted'] ?? null,
                'place'     => self::place($l['location'] ?? []),
            ], $shaped),
            'searches' => $found['searches'],
        ]);
    }

    /**
     * "City, Region", whichever of the two the listing has, or null.
     *
     * @param array<string,mixed> $location
     */
    private static function place(array $location): ?string
    {
        $names = array_filter([$location['city']['name'] ?? null, $location['region']['name'] ?? null], static fn ($n) => is_string($n) && $n !== '');

        return $names === [] ? null : implode(', ', array_unique($names));
    }

    /**
     * Up to three categories for a new listing's title.
     */
    public function categories(ApiCall $call): Response
    {
        return Response::ok(['categories' => SearchSuggestions::categoriesForTitle($call->request()->queryString('title'))]);
    }
}
