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
 * Pins the variant and customiser events: their readers, the old argument lists a legacy
 * callback gets, typed and legacy listeners on one name, and the registries living in the
 * site-wide container.
 *
 * DB-free.  Usage: php tests/variant-events.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/Plugins.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\container\Container;
use mindstellar\event\Dispatcher;
use mindstellar\listing\event\VariantPriceChanged;
use mindstellar\listing\event\VariantStockChanged;
use mindstellar\widgets\event\CustomiserPublished;
use mindstellar\widgets\WidgetRegistry;

if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return '/plugins/';
    }
}

harness_reset_container();

harness_section('readers');
$stock = static fn (?int $from, ?int $to): VariantStockChanged => new VariantStockChanged(3, 9, $from, $to);
pin('a sold-out variant with stock is back', true, $stock(0, 5)->backInStock());
pin('stock no longer counted is back too', true, $stock(0, null)->backInStock());
pin('stock 4 to 5 is not back', false, $stock(4, 5)->backInStock());
pin('0 to 0 is not back', false, $stock(0, 0)->backInStock());
pin('ids and values read back', [3, 9, 1, 2], [$stock(1, 2)->variantId(), $stock(1, 2)->itemId(), $stock(1, 2)->from(), $stock(1, 2)->to()]);
pin('a lower price is a drop', true, (new VariantPriceChanged(1, 1, 500, 400))->dropped());
pin('a higher price is not', false, (new VariantPriceChanged(1, 1, 400, 500))->dropped());
pin('an unknown side is not', false, (new VariantPriceChanged(1, 1, null, 400))->dropped());

harness_section('old and typed listeners');
$seen = [];
Plugins::addHook('variant_price_changed', static function ($id, $item, $from, $to) use (&$seen): void {
    $seen[] = ['old', $id, $item, $from, $to];
}, 3);
Plugins::addHook('variant_price_changed', static function (VariantPriceChanged $e) use (&$seen): void {
    $seen[] = ['typed', $e->variantId(), $e->to()];
}, 7);
Container::root()->get(Dispatcher::class)->dispatch(new VariantPriceChanged(4, 8, 900, 800));
pin('the old callback gets the old arguments, by priority', [['old', 4, 8, 900, 800], ['typed', 4, 800]], $seen);

$themes = [];
Plugins::addHook('customiser_published', static function ($theme) use (&$themes): void {
    $themes[] = $theme;
});
Container::root()->get(Dispatcher::class)->dispatch(new CustomiserPublished('aisle'));
pin('customiser_published still gets the theme', ['aisle'], $themes);

harness_section('registries');
pin('a registry is the root container\'s object', true, WidgetRegistry::getInstance() === Container::root()->get(WidgetRegistry::class));
$old = Container::use(new Container());
pin('a swapped container has its own registry', false, WidgetRegistry::getInstance() === $old->get(WidgetRegistry::class));
Container::use($old);
pin('and the old one comes back', true, WidgetRegistry::getInstance() === $old->get(WidgetRegistry::class));

exit(harness_result());
