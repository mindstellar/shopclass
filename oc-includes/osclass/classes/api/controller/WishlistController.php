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
use mindstellar\listing\WishlistStore;

/**
 * The signed-in user's wishlist at `/account/wishlist`: listing ids, newest first. Every call
 * reads and writes the caller's own list only.
 */
final class WishlistController
{
    private WishlistStore $store;

    public function __construct()
    {
        $this->store = new WishlistStore();
    }

    public function show(ApiCall $call): Response
    {
        return Response::ok($this->document($this->store->all($call->userId())));
    }

    public function replace(ApiCall $call): Response
    {
        $this->store->replace($call->userId(), array_values((array) ($call->input()['ids'] ?? [])));

        return Response::noContent();
    }

    public function add(ApiCall $call): Response
    {
        $this->store->add($call->userId(), $call->intArg('listing'));

        return Response::noContent();
    }

    public function remove(ApiCall $call): Response
    {
        $this->store->remove($call->userId(), $call->intArg('listing'));

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
