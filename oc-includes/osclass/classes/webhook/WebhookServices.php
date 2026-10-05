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

namespace mindstellar\webhook;

use mindstellar\security\AddressGuard;
use mindstellar\utility\Clock;
use mindstellar\utility\SystemClock;

/**
 * The webhook services, built on first use and shared after. site() is the one for this
 * request; tests build their own with a fixed clock.
 */
final class WebhookServices
{
    private static ?self $site = null;

    /** @var array<string,object> service name => instance */
    private array $built = [];

    /**
     * @param bool $allowPrivate whether endpoints on private addresses may be used
     */
    public function __construct(private Clock $clock, private bool $allowPrivate = false)
    {
    }

    public static function site(): self
    {
        return self::$site ??= new self(new SystemClock(), (string) osc_get_preference('api_webhooks_allow_private', WebhookEndpointStore::SECTION) === '1');
    }

    /**
     * Forget the site's services, so the next site() reads the settings again.
     */
    public static function reset(): void
    {
        self::$site = null;
    }

    public function endpoints(): WebhookEndpointStore
    {
        return $this->once(__FUNCTION__, static fn (): WebhookEndpointStore => new WebhookEndpointStore());
    }

    /**
     * The webhook events, plugin events included.
     */
    public function events(): Events
    {
        return $this->once(__FUNCTION__, static fn (): Events => Events::fromHooks());
    }

    /**
     * Queues events for the endpoints that want them.
     */
    public function dispatcher(): Dispatcher
    {
        return $this->once(__FUNCTION__, fn (): Dispatcher => new Dispatcher($this->endpoints(), $this->events(), $this->clock, [Dispatcher::class, 'enqueue']));
    }

    /**
     * Sends the queued deliveries.
     */
    public function delivery(): Delivery
    {
        return $this->once(__FUNCTION__, fn (): Delivery => new Delivery(
            $this->endpoints(),
            new CurlTransport(),
            $this->guard(),
            $this->clock,
            [Delivery::class, 'mailPaused']
        ));
    }

    /**
     * The endpoint rules the admin screen and `/admin/webhooks` share.
     */
    public function service(): WebhookService
    {
        return $this->once(__FUNCTION__, fn (): WebhookService => new WebhookService(
            $this->endpoints(),
            $this->events(),
            $this->guard(),
            $this->dispatcher(),
            $this->clock
        ));
    }

    /**
     * Where a webhook may be sent: public addresses only, unless the site allows private ones.
     */
    private function guard(): AddressGuard
    {
        return $this->once(__FUNCTION__, fn (): AddressGuard => new AddressGuard(null, $this->allowPrivate));
    }

    /**
     * @template T of object
     *
     * @param \Closure(): T $make
     *
     * @return T
     */
    private function once(string $name, \Closure $make): object
    {
        return $this->built[$name] ??= $make();
    }
}
