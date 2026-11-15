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
 * The price a listing row shows in shop mode: the lowest variant price, "From" when variant prices
 * differ, one variant's price when asked, the listing price when there are no variants.
 * Usage: php tests/item-variant-price.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_item_variant_price');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Tees', null, $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['language' => 'en_US', 'currency' => 'USD', 'site_mode' => 'shop'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

$micros = static fn (int $units): int => $units * 1000000;
$row    = static fn (string $size, int $price): array => ['options' => ['Size' => $size], 'fields' => ['i_stock' => 3, 'i_price' => $price]];
$show   = static function (int $id, ?int $variant = null): string {
    View::getInstance()->_exportVariableToView('item', Item::newInstance()->findByPrimaryKey($id));

    return osc_item_formatted_variant_price($variant);
};

$tee = seed_item($admin, $cat, $sue, 'Tee', 20.0);
[$s, $l] = VariantStore::saveSet($tee, [$row('S', $micros(15)), $row('L', $micros(26))]);
$same = seed_item($admin, $cat, $sue, 'Same', 20.0);
VariantStore::saveSet($same, [$row('S', $micros(15)), $row('L', $micros(15))]);
$plain = seed_item($admin, $cat, $sue, 'Plain', 20.0);

pin('differing variant prices show From the lowest', 'From 15 US Dollar', $show($tee));
pin('a price alert on one variant shows that variant', '26 US Dollar', $show($tee, $l));
pin('equal variant prices show one price', '15 US Dollar', $show($same));
pin('no variants shows the listing price', '20 US Dollar', $show($plain));
Preference::getInstance()->set('site_mode', 'classifieds');
scratchdb_forget_cache();
osc_reset_preferences();
pin('classifieds mode shows the listing price', '20 US Dollar', $show($tee));
