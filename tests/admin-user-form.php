<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the admin user form's rows against the row-declaration migration
 * (`admin: declare the form rows too`), which dropped the E-mail row's own field and
 * put the Cell phone field under the E-mail label instead, deleting the Cell phone row
 * outright. The break was silent on screen -- both rows still drew a text box -- and
 * only showed up as "Users -> Add" always failing with an invalid-email error.
 *
 * DB-free: the view is rendered directly, with no user row (the "add" screen's shape),
 * so nothing here needs a database.
 *
 * Usage: php tests/admin-user-form.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}

if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
    }
}
if (!function_exists('_e')) {
    function _e($key, $domain = 'core')
    {
        echo $key;
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/index.php';
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($index = false)
    {
        return 'https://example.test/index.php';
    }
}
if (!function_exists('osc_current_admin_theme_path')) {
    function osc_current_admin_theme_path($file = '')
    {
    }
}
if (!function_exists('osc_current_admin_theme_url')) {
    function osc_current_admin_theme_url($file = '')
    {
        return 'https://example.test/oc-admin/themes/modern/' . $file;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';

/** Render the add-user form the way the controller draws it for 'add'. */
function render_add_user_form(): string
{
    View::newInstance()->_exportVariableToView('user', null);
    View::newInstance()->_exportVariableToView('countries', array());
    View::newInstance()->_exportVariableToView('regions', array());
    View::newInstance()->_exportVariableToView('cities', array());
    View::newInstance()->_exportVariableToView('locales', array());

    ob_start();
    include ABS_PATH . 'oc-admin/themes/modern/users/frm.php';

    return (string)ob_get_clean();
}

/** Where the "form-row" enclosing the control named $name starts, or null if absent. */
function row_start(string $html, string $name): ?int
{
    $namePos = strpos($html, 'name="' . $name . '"');
    if ($namePos === false) {
        return null;
    }
    $rowStart = strrpos(substr($html, 0, $namePos), '<div class="form-row">');

    return $rowStart === false ? null : $rowStart;
}

/** The label of the "form-row" enclosing the control named $name, stripped of tags. */
function row_label(string $html, string $name): ?string
{
    $namePos  = strpos($html, 'name="' . $name . '"');
    $rowStart = row_start($html, $name);
    if ($namePos === false || $rowStart === null) {
        return null;
    }
    $labelStart = strpos($html, '<div class="form-label">', $rowStart);
    $labelEnd   = ($labelStart !== false) ? strpos($html, '</div>', $labelStart) : false;
    if ($labelStart === false || $labelEnd === false || $labelStart > $namePos) {
        return null;
    }

    return trim(strip_tags(substr($html, $labelStart + strlen('<div class="form-label">'), $labelEnd - $labelStart - strlen('<div class="form-label">'))));
}

$html = render_add_user_form();

harness_section('the e-mail row has its own field back');

check('an s_email input is drawn', strpos($html, 'name="s_email"') !== false, $html);
pin('labelled E-mail (required)', 'E-mail (required)', row_label($html, 's_email'));
// The bug put mobile_text() under the E-mail label instead of email_text(), so the
// e-mail row never carried an s_email input at all.
check(
    's_email is not sharing its row with s_phone_mobile',
    row_start($html, 's_email') !== null
        && row_start($html, 's_email') !== row_start($html, 's_phone_mobile'),
    $html
);

harness_section('the cell phone row is back');

check('an s_phone_mobile input is drawn', strpos($html, 'name="s_phone_mobile"') !== false, $html);
pin('labelled Cell phone', 'Cell phone', row_label($html, 's_phone_mobile'));

harness_section('the landline row is untouched');

check('an s_phone_land input is drawn', strpos($html, 'name="s_phone_land"') !== false, $html);
pin('still labelled Phone', 'Phone', row_label($html, 's_phone_land'));

exit(harness_result());
