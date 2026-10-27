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
 * Admin wording and settings by site mode: "Reported products" in shop mode, no goods-only
 * settings on a classifieds site, and widget sections named by label.
 * Usage: php tests/admin-mode-wording.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\admin\form\BillingSettingsScreen;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\settings\SettingsPageRegistry;

$admin = api_admin_boot('osc_admin_mode_wording');
$p     = DB_TABLE_PREFIX;

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';

seed_locale($admin);
seed_currency($admin);

$setMode = static function (string $mode): void {
    Preference::getInstance()->set('site_mode', $mode);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
foreach (['language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/' . ($index ? 'index.php' : '');
    }
}
if (!function_exists('osc_is_moderator')) {
    function osc_is_moderator()
    {
        return false;
    }
}
$gatewayIds = static function (): array {
    $registry = (new ReflectionClass(PaymentGatewayRegistry::class))->newInstanceWithoutConstructor();

    return array_keys($registry->all());
};
$shopFields = static function () {
    $registry = SettingsPageRegistry::getInstance();
    $entries  = new ReflectionProperty($registry, 'entries');
    $all      = $entries->getValue($registry);
    unset($all[BillingSettingsScreen::PAGE_SHOP]);
    $entries->setValue($registry, $all);
    BillingSettingsScreen::registerShop();

    return array_keys(SettingsPageRegistry::getInstance()->fields(BillingSettingsScreen::PAGE_SHOP));
};
$menuLabels = static function (): string {
    $menu = new AdminMenu();
    $menu->init();

    return (string) json_encode($menu->get_array_menu());
};

harness_section('classifieds');
$setMode('classifieds');
pin('cash on delivery stays registered for old orders', true, in_array('cod', $gatewayIds(), true));
pin('bank transfer stays', true, in_array('offline', $gatewayIds(), true));
pin('the Orders group has hold days and the prefix only', array('billing_hold_days', 'billing_order_prefix'), $shopFields());
check('reports say listings', str_contains($menuLabels(), 'Reported listings') && !str_contains($menuLabels(), 'Reported products'));

harness_section('shop');
$setMode('shop');
pin('cash on delivery is offered', true, in_array('cod', $gatewayIds(), true));
check('the card hold and open-order limit are settings', in_array('billing_open_hold_orders', $shopFields(), true));
check('reports say products', str_contains($menuLabels(), 'Reported products') && !str_contains($menuLabels(), 'Reported listings'));

harness_section('widget sections');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hWidgets.php';
if (!function_exists('osc_theme_supports')) {
    function osc_theme_supports($feature)
    {
        return array('footer' => array('label' => 'Footer', 'description' => 'Bottom of every page'));
    }
}
$admin->query("INSERT INTO {$p}t_widget (s_description, s_location, e_kind, s_content, i_order) VALUES ('old', 'home', 'HTML', '<p>x</p>', 0)");
foreach (array('_e' => 'echo $key;', '_n' => 'return $key;') as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($key, $plural = "", $n = 0) { ' . $body . ' }');
    }
}
foreach (array('osc_enqueue_script', 'osc_enqueue_style', 'osc_current_admin_theme_path', 'osc_register_script') as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() {}');
    }
}
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
ob_start();
include ABS_PATH . 'oc-admin/themes/modern/appearance/widgets.php';
$drawn = (string) ob_get_clean();
check('a section carries its label', str_contains($drawn, 'data-label="Footer"'));
check('a stored section the theme dropped is named Unused', str_contains($drawn, 'data-label="Unused: home"'));

exit(harness_result());
