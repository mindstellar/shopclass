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

$unnamed = [];
$rowBoxes = glob(ABS_PATH . 'oc-includes/osclass/classes/datatables/*DataTable.php') ?: [];
$rowBoxes[] = ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminLanguages.php';
$rowBoxes[] = ABS_PATH . $theme . 'settings/currencies.php';
foreach ($rowBoxes as $path) {
    $src = (string) file_get_contents($path);
    preg_match_all('/<input type="checkbox" name="(?:id|code)\[\]"[^;]*;/', $src, $found);
    foreach ($found[0] as $stmt) {
        if (!str_contains($stmt, 'aria-label')) {
            $unnamed[] = substr($path, strlen(ABS_PATH)) . ': ' . substr($stmt, 0, 70);
        }
    }
}
pin('every row checkbox has aria-label', [], $unnamed);

$ui = (string) file_get_contents(ABS_PATH . $theme . 'parts/ui.php');
preg_match('/data-osc-filter-for=.*?value=/s', $ui, $switchInput);
pin('the toolbar switch inputs are named', true, isset($switchInput[0]) && str_contains($switchInput[0], 'aria-label'));

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/utility/Sanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Field.php';
pin('a locale tab item is presentational', true, str_contains(\mindstellar\admin\ui\Field::localeTab('p', 'L', 'en_US'), 'role="presentation"'));

$logs = (string) file_get_contents(ABS_PATH . $theme . 'tools/logs.php');
pin('the logs section select is named', true, (bool) preg_match('/<select name="section"[^>]*aria-label=/', $logs));

$api = (string) file_get_contents(ABS_PATH . $theme . 'settings/api.php');
pin('the key expiry field names its control', true, (bool) preg_match("/'name'\s*=>\s*'key_expires',\s*'id'\s*=>\s*'key_expires'/", $api));

$uiosc = (string) file_get_contents(ABS_PATH . $theme . 'js/ui-osc.js');
pin('tab items are presentational in the script', true, str_contains($uiosc, "setAttribute('role', 'presentation')"));

require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Form.php';
$custom = static function (array $extra): string {
    ob_start();
    (new \mindstellar\admin\ui\Field(array_merge([
        'type' => 'custom', 'name' => 'k', 'label' => 'Expires',
        'render' => static function () {
            echo '<input id="k_id">';
        },
    ], $extra)))->render();

    return (string) ob_get_clean();
};
pin('a custom field with an id labels that control', true, str_contains($custom(['id' => 'k_id']), 'for="k_id"'));
pin('a custom field without an id labels nothing', false, str_contains($custom([]), ' for="'));

exit(harness_result());
