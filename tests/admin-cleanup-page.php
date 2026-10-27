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
 * The Cleanup rules table: it stacks on a phone, each cell with its heading, and carries no
 * fixed width of its own.
 * DB-backed. Usage: php tests/admin-cleanup-page.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_admin_cleanup_page');

if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
foreach (['_m' => 'return $key;', '_e' => 'echo $key;'] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($key, $domain = "core") { ' . $body . ' }');
    }
}
foreach ([
    'osc_admin_base_url'       => 'return "https://example.test/oc-admin/index.php";',
    'osc_current_admin_locale' => 'return "en_US";',
    'osc_is_moderator'         => 'return false;',
    'osc_plugins_path'         => 'return ABS_PATH . "oc-content/plugins/";',
] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$a) { ' . $body . ' }');
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';

use mindstellar\admin\form\CleanupSettingsScreen;

CleanupSettingsScreen::register();
ob_start();
osc_admin_settings_form(CleanupSettingsScreen::PAGE_ID, CleanupSettingsScreen::formVars());
$html = ob_get_clean();

harness_section('the rules table');
check('the table is drawn', str_contains($html, 'Matching now'));
check('it stacks on a phone', (bool) preg_match('~<table class="table osc-table-stack">~', $html));
check('it carries no inline width', !str_contains($html, 'min-width'));
check('each cell has its heading', substr_count($html, 'data-col-name="Matching now"') >= 7);
exit(harness_result());
