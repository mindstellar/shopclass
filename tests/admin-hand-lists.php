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
 * The hand-built list screens draw through osc_admin_table() / osc_admin_data_table(): no
 * copied <thead>, the keys and wrapper ids the styles rely on, and the media row order.
 *
 * Usage:  php tests/admin-hand-lists.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

$dir   = ABS_PATH . 'oc-admin/themes/modern/';
$files = array(
    'media/index.php', 'messages/index.php', 'messages/thread.php', 'fields/submissions.php',
    'admins/index.php', 'languages/index.php', 'emails/index.php', 'settings/currencies.php',
);

harness_section('templates');
foreach ($files as $file) {
    $src = (string)file_get_contents($dir . $file);
    check($file . ' has no <thead', !str_contains($src, '<thead'));
    check($file . ' draws through a table helper', preg_match('/osc_admin_(data_)?table\(/', $src) === 1);
}
foreach (array('admins/index.php', 'languages/index.php', 'emails/index.php') as $file) {
    check($file . ' uses osc_admin_data_table', str_contains((string)file_get_contents($dir . $file), 'osc_admin_data_table('));
}
$src = (string)file_get_contents($dir . 'admins/index.php');
check('admins never use the email key', !str_contains($src, "'email'") && str_contains($src, "'mail'"));
check('emails keep the email-templates id', str_contains((string)file_get_contents($dir . 'emails/index.php'), "'wrap_id' => 'email-templates'"));
$src = (string)file_get_contents($dir . 'media/index.php');
check('media keeps media-table and media-grid', str_contains($src, "'wrap_id' => 'media-table'") && str_contains($src, "'media-grid'"));
$src = (string)file_get_contents($dir . 'settings/currencies.php');
check('currencies keep the Currency symbol phone label', str_contains($src, "'col_name' => __('Currency symbol')"));
$src = (string)file_get_contents($dir . 'fields/submissions.php');
check('submissions keep status-* row class and data-sub-id', str_contains($src, "'status-' . \$status") && str_contains($src, "'data-sub-id'"));
foreach (array('messages/index.php', 'messages/thread.php', 'fields/submissions.php') as $file) {
    check($file . ' stacks on a phone', str_contains((string)file_get_contents($dir . $file), "'stack' => true"));
}

harness_section('media row');
ob_start();
osc_admin_table(array(
    'bulkactions' => array('label_html' => '<input id="check_all" type="checkbox"/>', 'class' => 'col-bulkactions', 'col_name' => false),
    'preview'     => array('label' => 'Preview', 'class' => 'media-col-preview', 'td_class' => 'col-image'),
    'actions'     => array('label' => 'Actions', 'class' => 'text-end', 'td_class' => 'media-row-actions'),
), array(array('bulkactions' => array('html' => '<input type="checkbox"/>'), 'preview' => array('html' => 'img'), 'actions' => 'x')), array(
    'wrap'    => 'actions',
    'wrap_id' => 'media-table',
    'class'   => 'media-grid',
));
$html = (string)ob_get_clean();
check('bulk cell comes first, then the preview cell', preg_match('/col-bulkactions"><input[^>]*>.*media-col-preview col-image" data-col-name="Preview"/s', $html) === 1, $html);
check('wrapper and grid class are set', str_contains($html, 'id="media-table"') && str_contains($html, 'class="table media-grid"'), $html);

exit(harness_result());
