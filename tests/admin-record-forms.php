<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Record forms use the shared components and one set of save labels.
 *
 * Usage:  php tests/admin-record-forms.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

require_once __DIR__ . '/lib/admin-ui-stubs.php';

function osc_run_hook($hook, ...$args)
{
}
function osc_add_filter($hook, $fn, $priority = 5)
{
}

require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';

require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

function src(string $path): string
{
    return (string) file_get_contents(ABS_PATH . $path);
}

$theme = 'oc-admin/themes/modern/';
$forms = 'oc-includes/classes/admin/form/';

$gone = array(
    $theme . 'items/frm.php'                 => array('Update listing', 'Update product'),
    $theme . 'pages/frm.php'                 => array('Save page'),
    $theme . 'users/frm.php'                 => array('Update user', "'btn_text']   = __('Add new user')"),
    $theme . 'comments/frm.php'              => array('Update comment', "__('Add');"),
    $theme . 'settings/keywordBlock_frm.php' => array('Update keyword', "'btn_text']   = __('Add new keyword')"),
    $theme . 'settings/currency_form.php'    => array('Update currency'),
    $theme . 'billing/package.php'           => array('Save package'),
    $theme . 'plugins/configuration.php'     => array("__('Update')"),
    $forms . 'AdminAccountForm.php'          => array("__('Save')", "__('Add')", "'<hr/>'"),
    $forms . 'BanRuleForm.php'               => array('Update rule', "__('Save changes') : __('Add new ban rule')"),
    $forms . 'BillingSettingsScreen.php'     => array('Save settings', 'Save pricing', 'Save bank transfer', 'Save upgrade', 'Save limits', 'Save receipt', 'Save shop'),
    $forms . 'SitemapSettingsScreen.php'     => array('Save robots.txt'),
);
foreach ($gone as $file => $needles) {
    $text = src($file);
    foreach ($needles as $needle) {
        check($file . ' no longer has ' . $needle, strpos($text, $needle) === false);
    }
}

check('the widget form has no sublabel', strpos(src($theme . 'appearance/add_widget.php'), 'form-sublabel') === false);
check('the widget fields are stacked', substr_count(src($theme . 'appearance/add_widget.php'), "'layout' ") >= 3);

$users = src($theme . 'users/frm.php');
check('the user form shows its facts as one definition list', substr_count($users, 'osc_admin_definition(') === 1);
check('the alert dialog is the shared one', strpos($users, '<dialog') === false && strpos($users, 'osc_admin_confirm_dialog(') !== false);

check('the comment form has no "( <a" pair', strpos(src($theme . 'comments/frm.php'), '( <a') === false);
check('the comment form uses the form opener', strpos(src($theme . 'comments/frm.php'), 'osc_admin_form_open(') !== false);
preg_match_all("/action=status.*?'variant'/s", src($theme . 'comments/frm.php'), $statusLinks);
check('the comment Activate and Block links both carry the CSRF token', count($statusLinks[0]) === 2
    && count(array_filter($statusLinks[0], static fn (string $l): bool => str_contains($l, 'osc_csrf_token_url()'))) === 2, count($statusLinks[0]) . ' links');
check('the package form uses the form opener', strpos(src($theme . 'billing/package.php'), '<form') === false);

// One render: the alert dialog as the user form draws it.
ob_start();
osc_admin_confirm_dialog(array(
    'id'         => 'dialog-alert-delete',
    'title'      => 'Delete alert',
    'text'       => 'Sure?',
    'confirm'    => 'Delete',
    'confirm_id' => 'alert-delete-submit',
    'fields'     => array('page' => 'users', 'action' => 'delete_alerts', 'alert_id[]' => '', 'user_id' => 7),
));
$html = (string) ob_get_clean();
check('the dialog posts the alert id field the script fills', strpos($html, 'name="alert_id[]"') !== false);
check('the dialog confirm button keeps its id', strpos($html, 'id="alert-delete-submit"') !== false);

exit(harness_result());
