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
use mindstellar\billing\CartStore;

/**
 * The signed-in user's saved cart at `/account/cart`: variant ids and quantities only. Every
 * call reads and writes the caller's own cart; the owner comes from the credential.
 */
final class CartController
{
    public function show(ApiCall $call): Response
    {
        return Response::ok(CartStore::read((int) $call->userId()));
    }

    public function replace(ApiCall $call): Response
    {
        $lines = $call->input()['lines'] ?? array();
        CartStore::replace((int) $call->userId(), is_array($lines) ? $lines : array());

        return Response::noContent();
    }
}
