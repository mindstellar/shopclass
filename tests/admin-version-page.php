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
 * The Version page names the version once: the page heading carries it, the changelog
 * box does not repeat it.
 * Usage: php tests/admin-version-page.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';

define('OC_ADMIN', true);
define('OSCLASS_VERSION', '9.9.9');
$GLOBALS['pageArgs'] = null;
foreach ([
    'osc_admin_page'               => '$GLOBALS["pageArgs"] = func_get_arg(0);',
    'osc_current_admin_theme_path' => '',
    'osc_add_filter'               => '',
    'osc_base_url'                 => 'return "https://example.test/";',
] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$a) { ' . $body . ' }');
    }
}

ob_start();
include ABS_PATH . 'oc-admin/themes/modern/tools/version.php';
$html = ob_get_clean();

harness_section('the version page');
check('the page heading carries the version', is_callable($GLOBALS['pageArgs']['section'] ?? null) && str_contains(($GLOBALS['pageArgs']['section'])(), '9.9.9'));
check('the changelog box adds no second heading with it', !str_contains($html, 'Shopclass 9.9.9'));
check('the changelog is drawn', str_contains($html, '<h3>') || str_contains($html, '<h4>'));
exit(harness_result());
