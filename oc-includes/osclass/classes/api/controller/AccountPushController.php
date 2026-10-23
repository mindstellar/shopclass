<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\api\controller;

use mindstellar\api\ApiCall;
use mindstellar\api\ProblemException;
use mindstellar\api\Response;
use mindstellar\api\serializer\Format;
use mindstellar\container\Container;
use mindstellar\push\PushSubscriptions;
use mindstellar\utility\Clock;

/**
 * The signed-in user's push endpoints at `/account/push-subscriptions`, kept by the same store as
 * the messages pages. An app registers its own endpoint; there is no list.
 */
final class AccountPushController
{
    private PushSubscriptions $subscriptions;

    private Clock $clock;

    public function __construct()
    {
        $this->subscriptions = Container::root()->get(PushSubscriptions::class);
        $this->clock         = Container::root()->get(Clock::class);
    }

    /**
     * `PUT account/push-subscriptions`: keep the endpoint, or renew it when it is already kept.
     */
    public function put(ApiCall $call): Response
    {
        $this->enabled();
        $userId   = $call->userId();
        $endpoint = (string) ($call->input()['endpoint'] ?? '');
        try {
            $browser = $this->subscriptions->add($userId, $endpoint, $call->request()->header('User-Agent'));
        } catch (\InvalidArgumentException $e) {
            throw ProblemException::field('/endpoint', 'format', $e->getMessage());
        }

        return Response::ok([
            'browser'    => $browser,
            'expires_at' => Format::timestamp($this->clock->now() + PushSubscriptions::TTL),
        ]);
    }

    /**
     * `DELETE account/push-subscriptions/{browser}`: 404 for a browser that is not the caller's.
     */
    public function delete(ApiCall $call): Response
    {
        $this->enabled();
        if ($this->subscriptions->removeEndpoint($call->userId() . ':' . (string) $call->arg('browser')) === 0) {
            throw ProblemException::notFound('No such browser.');
        }

        return Response::noContent();
    }

    /**
     * @throws ProblemException 404 when browser notifications are off on this site
     */
    private function enabled(): void
    {
        if (!osc_push_enabled()) {
            throw ProblemException::notFound('Browser notifications are switched off on this site.');
        }
    }
}
