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
 * Pins the hook name => event class map core's bridge starts with: it equals the names every core
 * event class gives, start-up loads no event class nobody hooked, and old-style and typed callbacks
 * on a mapped name added before or after start-up still run with the same arguments.
 *
 * DB-free.  Usage: php tests/hook-bridge-lazy.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/Plugins.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\billing\event\CartShipping;
use mindstellar\billing\event\OrderCancelled;
use mindstellar\billing\event\OrderShipped;
use mindstellar\boot\CoreServices;
use mindstellar\container\Container;
use mindstellar\event\Bridged;
use mindstellar\event\Dispatcher;
use mindstellar\event\HookBridge;
use mindstellar\mail\event\MailComposing;
use mindstellar\messaging\event\MessageRead;

if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return ABS_PATH . 'oc-content/plugins/';
    }
}

// Declared on first use only, so the test can tell whether start-up reflected on it.
spl_autoload_register(static function (string $class): void {
    if ($class === 'HblReceipts') {
        final class HblReceipts
        {
            /** @var list<string> */
            public static array $sent = [];

            public static function shipped($orderId, $userId = 0): void
            {
                self::$sent[] = "receipt:$orderId/$userId";
            }
        }
    }
});

harness_section('Start-up');

$heard = [];
Plugins::addHook('billing_order_cancelled', static function ($orderId, $userId) use (&$heard): void {
    $heard[] = "old-before:$orderId/$userId";
});
Plugins::addHook('message_read', static function (MessageRead $e) use (&$heard): void {
    $heard[] = 'typed-before:' . $e->conversationId();
});

$freeOver = static function ($amount) {
    return $amount;
};
Plugins::addHook('cart_free_over', $freeOver, 4);
Plugins::addHook('billing_order_shipped', [HblReceipts::class, 'shipped']);

$order = [];
Plugins::addHook('message_read', static function () use (&$order): void {
    $order[] = 'p8';
}, 8);
Plugins::addHook('message_read', static function () use (&$order): void {
    $order[] = 'p2';
}, 2);
$gone = static function ($problems) {
    return $problems;
};
Plugins::addHook('cart_problems', $gone);
Plugins::addHook('cart_item_add_after', $gone);

CoreServices::register(Container::root());
$bridge = HookBridge::active();
$events = Container::root()->get(Dispatcher::class);

// Before any other hook use, which would release every held callback at once.
check('a held callback is found by hasHook()', Plugins::hasHook('cart_item_add_after'));
Plugins::removeHook('cart_problems', $gone);
pin('and removed by removeHook()', [], Plugins::callbacks('cart_problems'));

check('start-up loads no event class that has no callback', !class_exists(CartShipping::class, false) && !class_exists(MailComposing::class, false));
check('a callback added before start-up loads nothing until its hook is used', !class_exists(HblReceipts::class, false) && !class_exists(OrderShipped::class, false));
pin('it is listed on its hook', [$freeOver], Plugins::callbacks('cart_free_over'));
$events->dispatch(new OrderShipped(5, 6));
pin('and runs when its event fires', ['receipt:5/6'], HblReceipts::$sent);
pin('a mapped name nobody hooked is still owned', CartShipping::class, $bridge->eventFor('cart_shipping_methods'));
check('callbacks added before start-up left the old store', !isset(Plugins::getActive()['billing_order_cancelled']) && !isset(Plugins::getActive()['message_read']));

Plugins::addHook('billing_order_cancelled', static function (OrderCancelled $e) use (&$heard): void {
    $heard[] = 'typed-after:' . $e->orderId() . '/' . $e->userId();
}, 6);
Plugins::addHook('message_read', static function ($conversationId, $readerId, $count) use (&$heard): void {
    $heard[] = "old-after:$conversationId/$readerId/$count";
}, 6);
Plugins::addHook('message_read', static function () use (&$order): void {
    $order[] = 'p5';
}, 5);
check('callbacks added after start-up go to the bridge too', !isset(Plugins::getActive()['billing_order_cancelled']) && Plugins::hasHook('message_read'));

$events->dispatch(new OrderCancelled(7, 3));
$events->dispatch(new MessageRead(11, 4, 2));
pin('old and typed callbacks get the same arguments before and after start-up', [
    'old-before:7/3',
    'typed-after:7/3',
    'typed-before:11',
    'old-after:11/4/2',
], $heard);

pin('held callbacks keep their priority among later ones', ['p2', 'p5', 'p8'], $order);

harness_section('The map');

$expected = [];
foreach (array_values(array_unique(CoreServices::EVENT_HOOKS)) as $class) {
    foreach ($class::legacyNames() as $name) {
        $expected[$name] = $class;
    }
}
pin('the map is every core event class\'s names, in order', $expected, CoreServices::EVENT_HOOKS);

$bridgedClasses = [];
foreach (harness_class_files() as $path => $source) {
    if (strpos($source, 'function legacyNames') === false || !preg_match('/^namespace\s+([^;]+);/m', $source, $ns)
        || !preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $cls)) {
        continue;
    }
    $class = $ns[1] . '\\' . $cls[1];
    if (is_subclass_of($class, Bridged::class) && !(new ReflectionClass($class))->isAbstract()) {
        $bridgedClasses[] = $class;
    }
}
sort($bridgedClasses);
$mapped = array_values(array_unique(CoreServices::EVENT_HOOKS));
sort($mapped);
pin('every core event class with old names is in the map', $bridgedClasses, $mapped);
$nested = [];
foreach ($mapped as $class) {
    foreach ($mapped as $other) {
        if ($class !== $other && is_a($class, $other, true)) {
            $nested[] = "$class < $other";
        }
    }
}
pin('no mapped event class extends another, so each one\'s held callbacks can be added on their own', [], $nested);
pin('events() lists the mapped classes', array_values(array_unique(CoreServices::EVENT_HOOKS)), CoreServices::events());

exit(harness_result());
