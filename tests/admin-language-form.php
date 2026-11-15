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
 * The Edit language screen draws a form that can be saved: it lost its submit button once.
 *
 * DB-free.  Usage: php tests/admin-language-form.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';

function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/index.php';
}
function osc_current_admin_theme_path($file = '')
{
}
function osc_add_hook($hook, $fn, $priority = 5)
{
}
function osc_run_hook($hook, ...$args)
{
}
function osc_add_filter($hook, $fn, $priority = 5)
{
}
function _e($text, $domain = 'core')
{
    echo $text;
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

View::getInstance()->_exportVariableToView('aLocale', array(
    'pk_c_code' => 'en_AU', 's_version' => '1.0', 's_name' => 'English (Australia)', 's_short_name' => 'English',
    's_description' => '', 's_direction' => 'ltr', 's_currency_format' => '', 'i_num_dec' => 2, 's_dec_point' => '.',
    's_thousands_sep' => ',', 's_date_format' => 'd/m/Y', 's_stop_words' => '', 'b_enabled' => 1, 'b_enabled_bo' => 1,
));
ob_start();
include ABS_PATH . 'oc-admin/themes/modern/languages/frm.php';
$html = (string) ob_get_clean();

$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
$xpath  = new DOMXPath($dom);
$submit = $xpath->query('//form[.//input[@name="action"][@value="edit_post"]]//button[@type="submit"] | //form[.//input[@name="action"][@value="edit_post"]]//input[@type="submit"]');
pin('the edit form has one submit button', 1, $submit === false ? 0 : $submit->length);
pin('...labelled Save changes', 'Save changes', $submit !== false && $submit->length ? trim($submit->item(0)->textContent ?: (string) $submit->item(0)->getAttribute('value')) : '');

exit(harness_result());
