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

use mindstellar\api\ApiCall;
use mindstellar\api\Response;
use mindstellar\api\serializer\Links;

/**
 * One page of a list as the API sends it: the rows, `meta` (total, limit, and truncated when offset
 * paging stops early) and `links` (self, next). Every list answer has this shape, and the links
 * never carry an `api_key`.
 */
final class Page
{
    /**
     * @param array<int,mixed> $items the shaped rows
     * @param int|null         $total null when it was not counted
     * @param string|null      $next  the next page's cursor
     * @param bool             $truncated more rows exist past the deepest page offset paging reaches
     */
    public function __construct(private array $items, private ?int $total, private int $limit, private ?string $next, private bool $truncated = false)
    {
    }

    /**
     * @param string              $path  the endpoint, below /api/v1/
     * @param array<string,mixed> $query the request's query
     */
    public function response(Links $links, string $path, array $query, ?string $version = null): Response
    {
        $rest = $query;
        unset($rest['cursor']);

        return Response::collection(
            $this->items,
            ['total' => $this->total, 'limit' => $this->limit] + ($this->truncated ? ['truncated' => true] : []),
            [
                'self' => $links->api(self::url($path, $query), $version),
                'next' => $this->next === null ? null : $links->api(self::url($path, $rest + ['cursor' => $this->next]), $version),
            ]
        );
    }

    /**
     * A list answered whole: the same envelope as a page, with `total` and `limit` the item
     * count and no `next`.
     *
     * @api
     *
     * @param array<int,mixed> $items the shaped rows
     */
    public static function whole(array $items, Links $links, ApiCall $call): Response
    {
        $items = array_values($items);

        return (new self($items, count($items), count($items), null))->response($links, $call->request()->routePath(), $call->request()->query(), $call->request()->version());
    }

    /**
     * A path with its query string, as links carry it.
     *
     * @param array<string,mixed> $query
     */
    public static function url(string $path, array $query): string
    {
        unset($query['api_key']);

        return $query === [] ? $path : $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
