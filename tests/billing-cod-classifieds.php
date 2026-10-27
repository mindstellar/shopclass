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
 * A site that switched from shop to classifieds still knows cash on delivery, so old orders
 * keep their method, but checkout and credit purchases no longer offer it.
 * Usage: php tests/billing-cod-classifieds.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\PaymentGatewayRegistry;

$admin = api_admin_boot('osc_billing_cod_classifieds');
seed_currency($admin);
$setMode = static function (string $mode): void {
    foreach (['site_mode' => $mode, 'billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_cod_enabled' => '1', 'billing_offline_enabled' => '1', 'billing_offline_instructions' => 'Pay to account 123'] as $k => $v) {
        Preference::getInstance()->set($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
};

$setMode('classifieds');
$registry = PaymentGatewayRegistry::getInstance();
harness_section('classifieds mode');
pin('an old cash on delivery order still finds its gateway', true, $registry->get('cod') !== null);
pin('so it keeps its method name', true, $registry->get('cod')?->getName() !== null);
pin('checkout does not offer it', false, isset($registry->available('USD')['cod']));
pin('credit purchases do not offer it', false, isset(osc_billing_gateways()['cod']));
pin('bank transfer is still offered', true, isset(osc_billing_gateways()['offline']));

harness_section('shop mode');
$setMode('shop');
pin('checkout offers it again', true, isset($registry->available('USD')['cod']));

exit(harness_result());
