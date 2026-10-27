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
 * The admin shell: the login wrapper tells a phone to use its own width, building the menu
 * with an order to ship leaves the admin theme's helpers to the theme, and a save button
 * keeps a visible focus ring.
 * Usage: php tests/admin-login-page.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;

$admin = api_admin_boot('osc_admin_login_page');

seed_locale($admin);
seed_currency($admin);
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'site_mode' => 'shop'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
foreach (['_e' => 'echo $key;', '_n' => 'return $key;'] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($key, $plural = "", $n = 0) { ' . $body . ' }');
    }
}
foreach ([
    'osc_admin_base_url'                => 'return "https://example.test/oc-admin/" . (func_get_args()[0] ?? false ? "index.php" : "");',
    'osc_current_admin_locale'          => 'return "en_US";',
    'osc_is_moderator'                  => 'return false;',
    'osc_page_title'                    => 'return "Shop";',
    'osc_current_admin_theme_styles_url' => 'return "https://example.test/main.css";',
    'osc_assets_url'                    => 'return "https://example.test/assets/";',
    'osc_show_flash_message'            => 'return null;',
    'osc_admin_base_path'               => 'return "/";',
] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$a) { ' . $body . ' }');
    }
}
require_once ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';

harness_section('the menu leaves the theme helpers alone');
$order = OrderStore::create($tom, 'cod', 10000000, 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Tee']);
Billing::markPaid($order, null);
$menu = new AdminMenu();
$menu->init();
pin('one order waits', 1, $menu->get_array_menu()['billing']['count'] ?? 0);
check('building the menu does not load the core page head', !function_exists('osc_admin_page_head'));

harness_section('the login wrapper');
$form = sys_get_temp_dir() . '/osc-login-form-' . getmypid() . '.php';
file_put_contents($form, '');
View::getInstance()->_exportVariableToView('login_admin_form', $form);
View::getInstance()->_exportVariableToView('login_admin_url', 'https://example.test/');
View::getInstance()->_exportVariableToView('login_admin_title', 'Shop');
View::getInstance()->_exportVariableToView('login_admin_image', 'https://example.test/logo.png');
if (!function_exists('osc_run_hook')) {
    function osc_run_hook($hook)
    {
    }
}
if (!defined('OSCLASS_VERSION')) {
    define('OSCLASS_VERSION', '7.1.0');
}
ob_start();
include ABS_PATH . 'oc-admin/gui/main.php';
$html = ob_get_clean();
unlink($form);
check('the login page has a viewport meta', str_contains($html, '<meta name="viewport" content="width=device-width, initial-scale=1">'));

harness_section('the save button focus ring');
$scss = file_get_contents(ABS_PATH . 'oc-admin/themes/modern/scss/ui-elements.scss');
preg_match('/\n\.btn-submit \{(.*?)\n\}/s', $scss, $block);
check('.btn-submit sets its focus ring colour', str_contains($block[1] ?? '', 'btn-focus-shadow-rgb'));
harness_section('the core page head');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
ob_start();
osc_admin_page_head('Stats', [], ['actions_html' => static function () {
    echo '<b>range</b>';
}]);
check('the core fallback prints a screen\'s own controls', str_contains(ob_get_clean(), '<b>range</b>'));
exit(harness_result());
