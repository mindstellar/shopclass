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
use mindstellar\api\Response;
use mindstellar\listing\WishlistService;

/**
 * The signed-in user's wishlist at `/account/wishlist`: listing ids, newest first. Every call
 * reads and writes the caller's own list only.
 */
final class WishlistController
{
    private WishlistService $wishlist;

    public function __construct()
    {
        $this->wishlist = new WishlistService();
    }

    public function show(ApiCall $call): Response
    {
        return Response::ok($this->document($this->wishlist->all($call->userId())));
    }

    public function replace(ApiCall $call): Response
    {
        $this->wishlist->replace($call->userId(), array_values((array) ($call->input()['ids'] ?? [])));

        return Response::noContent();
    }

    public function add(ApiCall $call): Response
    {
        $this->wishlist->add($call->userId(), $call->intArg('listing'));

        return Response::noContent();
    }

    public function remove(ApiCall $call): Response
    {
        $this->wishlist->remove($call->userId(), $call->intArg('listing'));

        return Response::noContent();
    }

    /**
     * @param int[] $ids
     *
     * @return array{ids:int[],count:int}
     */
    private function document(array $ids): array
    {
        return ['ids' => $ids, 'count' => count($ids)];
    }
}
