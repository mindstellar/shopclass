<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the service container: factories run once and are shared, instance() puts a fake in,
 * a class with a no-argument constructor is built without registering, anything else throws
 * NotFoundException, and Container::use() swaps the site-wide one. Also pins that start-up
 * registers the dispatcher and the hook bridge before any plugin loads.
 *
 * DB-free.  Usage: php tests/container.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\boot\Boot;
use mindstellar\boot\CoreServices;
use mindstellar\container\Container;
use mindstellar\container\ContainerInterface;
use mindstellar\container\NotFoundException;
use mindstellar\event\Dispatcher;
use mindstellar\event\DispatcherInterface;
use mindstellar\event\HookBridge;

final class CtPlain
{
    public static int $made = 0;

    public function __construct()
    {
        self::$made++;
    }
}

final class CtNeedsArg
{
    public function __construct(public CtPlain $plain)
    {
    }
}

final class CtPrivate
{
    private function __construct()
    {
    }
}

harness_section('Factories and instances');

$c     = new Container();
$calls = 0;
$c->set('greeting', static function (Container $given) use (&$calls, $c): string {
    $calls++;
    check('a factory is given the container it is in', $given === $c);

    return 'hello';
});
check('has() is true for a registered factory', $c->has('greeting'));
pin('nothing is built at set()', 0, $calls);
pin('get() returns what the factory made', 'hello', $c->get('greeting'));
$c->get('greeting');
pin('the factory runs once, then the result is shared', 1, $calls);

$c->set(CtNeedsArg::class, static fn (Container $c) => new CtNeedsArg($c->get(CtPlain::class)));
$a = $c->get(CtNeedsArg::class);
check('a factory can pull its own needs from the container', $a instanceof CtNeedsArg && $a->plain === $c->get(CtPlain::class));
check('the same object comes back each time', $c->get(CtNeedsArg::class) === $a);

$fake = new CtPlain();
$c->instance(CtPlain::class, $fake);
check('instance() puts an object already built in', $c->get(CtPlain::class) === $fake);
$c->set(CtPlain::class, static fn () => new CtPlain());
check('set() after a build replaces the shared object', $c->get(CtPlain::class) !== $fake);

harness_section('Building unregistered classes');

$c    = new Container();
$made = CtPlain::$made;
check('has() is true for a class with a no-argument constructor', $c->has(CtPlain::class));
pin('has() builds nothing', $made, CtPlain::$made);
$plain = $c->get(CtPlain::class);
check('get() builds it with new', $plain instanceof CtPlain);
check('and shares it', $c->get(CtPlain::class) === $plain);

foreach ([CtNeedsArg::class => 'a class whose constructor needs an argument', CtPrivate::class => 'a class with a private constructor', 'no.such.id' => 'an unknown id'] as $id => $what) {
    check("has() is false for $what", !$c->has($id));
    $thrown = null;
    try {
        $c->get($id);
    } catch (NotFoundException $e) {
        $thrown = $e;
    }
    check("get() throws NotFoundException for $what", $thrown !== null && strpos($thrown->getMessage(), $id) !== false);
}

$c->set('loop', static fn (Container $c) => $c->get('loop'));
$thrown = null;
try {
    $c->get('loop');
} catch (LogicException $e) {
    $thrown = $e;
}
check('a factory that needs itself throws instead of recursing', $thrown !== null && !$thrown instanceof NotFoundException);
check('a factory that threw can be retried, and throws the same way', (static function () use ($c): bool {
    try {
        $c->get('loop');
    } catch (LogicException $e) {
        return strpos($e->getMessage(), 'needs itself') !== false;
    }

    return false;
})());

harness_section('The site-wide container');

check('the container has the PSR-11 shape', (new Container()) instanceof ContainerInterface);
$root = Container::root();
check('root() returns the same container each time', Container::root() === $root);
$fresh    = new Container();
$previous = Container::use($fresh);
check('use() returns the container it replaces', $previous === $root);
check('use() makes the new one the root', Container::root() === $fresh);
Container::use($root);

harness_section('Start-up');

$c = new Container();
CoreServices::register($c);
check('the dispatcher is registered', $c->get(Dispatcher::class) instanceof Dispatcher);
check('the PSR-14 id gives the same dispatcher', $c->get(DispatcherInterface::class) === $c->get(Dispatcher::class));
check('the hook bridge is registered', $c->get(HookBridge::class) instanceof HookBridge);
Container::use(new Container());
check('without start-up there is no bridge, so hooks keep the old path', HookBridge::active() === null);
Container::use($c);
check('after start-up the bridge is the active one', HookBridge::active() === $c->get(HookBridge::class));
Container::use($root);

$load      = (string) file_get_contents(ABS_PATH . 'oc-load.php');
$helpersAt = strpos($load, 'foreach (Boot::HELPERS');
$servicesAt = strpos($load, 'Boot::services();');
$pluginsAt = strpos($load, 'Plugins::init();');
check(
    'oc-load.php registers the services after the helpers and before the plugins load',
    $helpersAt !== false && $servicesAt !== false && $pluginsAt !== false && $helpersAt < $servicesAt && $servicesAt < $pluginsAt
);
Boot::services();
check('Boot::services() fills the root container', Container::root()->get(HookBridge::class) instanceof HookBridge);

exit(harness_result());
