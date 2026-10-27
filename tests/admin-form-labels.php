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
 * Admin form rows tie their label to the control, select-all boxes are named, and the photo
 * uploader has a control the keyboard can reach.
 *
 * DB-free source check.  Usage:  php tests/admin-form-labels.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$theme = 'oc-admin/themes/modern/';
$files = [
    $theme . 'users/frm.php', $theme . 'users/index.php', $theme . 'fields/iframe.php',
    $theme . 'fields/group_iframe.php', $theme . 'comments/frm.php', $theme . 'tools/backup.php',
    $theme . 'languages/frm.php', $theme . 'billing/wallet.php', $theme . 'settings/keywordBlock_frm.php',
];

$controlPattern = '/<input\b|<select\b|<textarea\b|osc_admin_(?:text|select|number|textarea|checkbox|field)\(|[A-Za-z]Form::/';

$unlabelled = [];
foreach ($files as $file) {
    $src = (string) file_get_contents(ABS_PATH . $file);
    preg_match_all('/osc_admin_form_row_open\((.*?)\)\s*;?\s*\?>(.*?)osc_admin_form_row_close/s', $src, $rows, PREG_SET_ORDER);
    foreach ($rows as $row) {
        $args     = $row[1];
        $hasLabel = !preg_match("/^\s*(''|\"\")\s*(,|$)/", $args) || str_contains($args, 'label_html');
        if (!$hasLabel || !preg_match($controlPattern, $row[2]) || str_contains($args, "'for'")) {
            continue;
        }
        $unlabelled[] = $file . ': ' . trim(preg_replace('/\s+/', ' ', $args));
    }
}
pin('every labelled row with a control passes for', [], $unlabelled);

$bare = [];
$dirs = [ABS_PATH . 'oc-includes/osclass/classes/datatables', ABS_PATH . 'oc-admin/themes/modern'];
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($f->getPathname());
        if (preg_match_all('/<input[^>]*id="check_all"[^>]*>/', $src, $m)) {
            foreach ($m[0] as $tag) {
                if (!str_contains($tag, 'aria-label')) {
                    $bare[] = substr($f->getPathname(), strlen(ABS_PATH)) . ': ' . $tag;
                }
            }
        }
    }
}
pin('every select-all checkbox has aria-label', [], $bare);

$items = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/datatables/ItemsDataTable.php');
pin('the listing thumbnail link is named', true, (bool) preg_match('/listing-thumb.*?aria-label=/s', $items));

$form = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/form/ItemForm.php');
preg_match('/<input type="file" class="osc-uploader-input".*$/m', $form, $input);
pin('the uploader file input exists', true, isset($input[0]));
pin('the uploader file input is not hidden from the keyboard', false, isset($input[0]) && preg_match('/\shidden\b/', $input[0]) === 1);

$scss = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/scss/pages-listing.scss');
pin('the wide comment box shrinks to its column', true, (bool) preg_match('/\.input-description-wide textarea\s*\{[^}]*max-width:\s*100%/', $scss));

exit(harness_result());
