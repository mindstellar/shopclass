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
 * Form and settings screens sit in one admin body: each template opens it once and closes it, and
 * the pieces scripts and tests read are still there.
 *
 * Usage:  php tests/admin-body-forms.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$theme = ABS_PATH . 'oc-admin/themes/modern/';
$files = array(
    'admins/frm', 'billing/package', 'comments/frm', 'emails/frm', 'items/frm', 'items/settings',
    'languages/frm', 'messages/settings', 'messages/thread', 'pages/frm', 'plugins/configuration',
    'settings/advanced', 'settings/billing', 'settings/comments', 'settings/currency_form', 'settings/index',
    'settings/keywordBlock_frm', 'settings/mailserver', 'settings/media', 'settings/permalinks',
    'settings/searches', 'settings/storage', 'users/ban_frm', 'users/frm', 'users/settings',
);

harness_section('one body per screen');
foreach ($files as $file) {
    $src = (string)file_get_contents($theme . $file . '.php');
    pin("$file: opens one body", 1, substr_count($src, 'osc_admin_body_open('));
    pin("$file: closes it", 1, substr_count($src, 'osc_admin_body_close('));
    check("$file: opens it before it closes", (int)strpos($src, 'osc_admin_body_open(') < (int)strpos($src, 'osc_admin_body_close('));
    check("$file: no hand-built widget-box", !str_contains($src, 'widget-box'));
}

harness_section('pieces other code reads');
$read = static fn (string $file): string => (string)file_get_contents($theme . $file . '.php');
foreach (array('admins/frm', 'settings/advanced', 'settings/comments', 'settings/index', 'settings/keywordBlock_frm',
    'settings/mailserver', 'settings/searches', 'users/ban_frm', 'comments/frm', 'languages/frm') as $file) {
    if ($file === 'languages/frm') {
        check("$file: the form id is kept", str_contains($read($file), "'id' => 'language-form'"));
        continue;
    }
    check("$file: #error_list is kept", str_contains($read($file), 'id="error_list"'));
}
check('items/frm: #error_list is kept', str_contains($read('items/frm'), 'id="error_list"'));
check('items/frm: the body keeps its id', str_contains($read('items/frm'), "'id'    => 'adminItemForm'"));
check('items/frm: the mode and new flags are body attributes', str_contains($read('items/frm'), "'data-osc-mode' =>") && str_contains($read('items/frm'), "'data-osc-new' =>"));
check('items/frm: the section list is a panel', str_contains($read('items/frm'), "osc_admin_panel_open(__('On this page')"));
check('emails/frm: body id kept', str_contains($read('emails/frm'), "'id' => 'adminEmailForm'"));
check('pages/frm: body id kept', str_contains($read('pages/frm'), "'id' => 'adminPageForm'"));
check('languages/frm: col-lg-6 stays on the body', str_contains($read('languages/frm'), "'class' => 'col-lg-6'"));
foreach (array('admins/frm', 'users/ban_frm', 'settings/keywordBlock_frm') as $file) {
    check("$file: settings-user stays as a class", str_contains($read($file), "'class' => 'settings-user'"));
}

harness_section('spacing');
$scss = ABS_PATH . 'oc-admin/themes/modern/scss/';
$editor = (string)file_get_contents($scss . 'editor.scss');
check('the editor main column spaces its blocks by gap', (bool)preg_match('/^\.osc-editor-main \{[^}]*display: flex;[^}]*gap: 1rem;/m', $editor));
check('a section in it has no margin of its own', !preg_match('/\.osc-editor-main \.osc-section \{[^}]*margin/', $editor));
check('the unused editor group rule is gone', !str_contains($editor, '.osc-editor-group'));
$settings = (string)file_get_contents($scss . 'pages-settings.scss');
check('the lead line and two-step block carry no margin', !preg_match('/^\.settings-lead \{[^}]*margin/m', $settings) && !preg_match('/^\.two-factor \{\s*margin/m', $settings));

exit(harness_result());
