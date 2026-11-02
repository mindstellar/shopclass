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
use mindstellar\api\read\Page;
use mindstellar\api\Response;
use mindstellar\user\AddressBook;

/**
 * The signed-in user's address book at `/account/addresses`, the default first. Every call reads
 * and writes the caller's own book; another user's address id is a 404.
 */
final class AddressesController
{
    private AddressBook $book;

    public function __construct(private ApiServices $api)
    {
        $this->book = new AddressBook();
    }

    public function index(ApiCall $call): Response
    {
        return Page::whole($this->book->all($call->userId()), $this->api->links(), $call);
    }

    public function create(ApiCall $call): Response
    {
        $input = $call->input();
        $saved = $this->book->add($call->userId(), $input, ($input['default'] ?? false) === true);

        return $this->api->created($call, $saved, 'account/addresses/' . $saved['id']);
    }

    public function update(ApiCall $call): Response
    {
        return Response::ok($this->book->update($call->userId(), (string) $call->arg('address'), $call->input()));
    }

    public function delete(ApiCall $call): Response
    {
        $this->book->remove($call->userId(), (string) $call->arg('address'));

        return Response::noContent();
    }

    public function makeDefault(ApiCall $call): Response
    {
        $this->book->makeDefault($call->userId(), (string) $call->arg('address'));

        return Page::whole($this->book->all($call->userId()), $this->api->links(), $call);
    }
}
