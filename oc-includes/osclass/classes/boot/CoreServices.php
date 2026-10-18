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

namespace mindstellar\boot;

use mindstellar\container\Container;
use mindstellar\event\Bridged;
use mindstellar\event\Dispatcher;
use mindstellar\event\DispatcherInterface;
use mindstellar\event\HookBridge;
use mindstellar\mail\CoreEmails;
use mindstellar\mail\event\MailComposing;

/**
 * The services core puts in the site-wide container at start-up. Plugins may add their own on init.
 */
final class CoreServices
{
    private function __construct()
    {
    }

    /**
     * Put the dispatcher and the hook bridge in $c, and give the bridge core's events.
     */
    public static function register(Container $c): void
    {
        $events = new Dispatcher($c);
        $bridge = new HookBridge($events);
        $c->instance(Dispatcher::class, $events);
        $c->instance(DispatcherInterface::class, $events);
        $c->instance(HookBridge::class, $bridge);
        $c->set(CoreEmails::class, static fn (Container $c): CoreEmails => new CoreEmails($c->get(DispatcherInterface::class)));
        foreach (self::events() as $event) {
            $bridge->own($event);
        }
    }

    /**
     * The site's dispatcher. Core's services are registered first when start-up has not done it.
     */
    public static function dispatcher(): DispatcherInterface
    {
        $c = Container::root();
        if (!$c->has(DispatcherInterface::class)) {
            self::register($c);
        }

        return $c->get(DispatcherInterface::class);
    }

    /**
     * The core events that own old hook names.
     *
     * @return list<class-string<Bridged>>
     */
    public static function events(): array
    {
        return [
            MailComposing::class,
        ];
    }
}
