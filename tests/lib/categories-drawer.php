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
 * Renders the Categories drawer (categories/iframe.php) without a database. One locale is
 * stubbed in; $vars are the values the controller exports (parent, subcategory_count, ...).
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__, 2) . '/');
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/stubs.php';

if (!function_exists('_e')) {
    function _e($key, $domain = 'core')
    {
        echo $key;
    }
}

foreach ([
    'osc_admin_base_url'       => 'return "https://example.test/oc-admin/index.php";',
    'osc_language'             => 'return "en_US";',
    'osc_current_admin_locale' => 'return "en_US";',
] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$a) { ' . $body . ' }');
    }
}
if (!class_exists('OSCLocale', false)) {
    eval('class OSCLocale { public static function getInstance() { return new self(); }
        public function listAllEnabled() { return [["pk_c_code" => "en_US", "s_name" => "English (US)"]]; } }');
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';

/**
 * @param array<string,mixed> $category A category row as editIframe() exports it
 * @param array<string,mixed> $vars     Other exported values (parent, subcategory_count, has_subcategories)
 */
function categories_drawer_html(array $category, array $vars = []): string
{
    $view = View::getInstance();
    $view->_exportVariableToView('category', $category);
    foreach (['parent' => null, 'subcategory_count' => 0, 'has_subcategories' => false] as $k => $default) {
        $view->_exportVariableToView($k, $vars[$k] ?? $default);
    }
    ob_start();
    require ABS_PATH . 'oc-admin/themes/modern/categories/iframe.php';

    return (string) ob_get_clean();
}
