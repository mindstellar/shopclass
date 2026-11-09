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
 * The builder screens (fields, submissions, categories, customise, widgets, menus) draw their
 * dialogs, empty states and submission details with the shared helpers. Scripts bind to the
 * ids, so each one must still be there.
 *
 * Usage:  php tests/admin-builder-dialogs.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

require_once __DIR__ . '/lib/harness.php';

$dir = ABS_PATH . 'oc-admin/themes/modern/';
$src = static fn (string $f): string => (string)file_get_contents($dir . $f);

harness_section('confirm dialogs go through the helper');
$dialogs = array(
    'fields/index.php'       => array('deleteModal' => 'deleteSubmit', 'deleteGroupModal' => 'deleteGroupSubmit'),
    'fields/submissions.php' => array('deleteSubModal' => 'deleteSubSubmit', 'purgeSubModal' => 'purgeSubSubmit'),
    'appearance/customise.php' => array('cz-discard-dialog' => 'cz-discard-confirm', 'cz-stale-dialog' => 'cz-stale-confirm'),
    'categories/index.php'   => array('catConfirm' => 'catConfirmOk'),
);
foreach ($dialogs as $file => $map) {
    $code = $src($file);
    check("$file has no hand-built danger dialog", !str_contains($code, 'osc-dialog-danger'));
    foreach ($map as $id => $confirmId) {
        check(
            "$file: $id is drawn by the helper with confirm id $confirmId",
            (bool)preg_match("~osc_admin_confirm_dialog\\(array\\(\\s*'id'\\s*=> '$id',\\s*'form'\\s*=> false,~", $code)
            && str_contains($code, "'confirm_id' => '$confirmId'")
        );
    }
}
check('the data attributes scripts read are kept', str_contains($src('fields/index.php'), "'data-field-id' => ''")
    && str_contains($src('fields/index.php'), "'data-group-id' => ''")
    && str_contains($src('fields/submissions.php'), "'data-sub-id' => ''")
    && str_contains($src('fields/submissions.php'), "'data-form-id' => ''"));
check('customise keeps aria-labelledby and its title ids', str_contains($src('appearance/customise.php'), "'title_id'   => 'cz-discard-title'")
    && str_contains($src('appearance/customise.php'), "'title_id'   => 'cz-stale-title'")
    && substr_count($src('appearance/customise.php'), "'cancel'     => __('Keep editing')") === 2);
$cat = $src('categories/index.php');
foreach (array('catConfirmTitle', 'catConfirmFacts', 'catConfirmAside', 'catConfirmCancel', 'catConfirmOk') as $id) {
    check("categories keeps #$id", str_contains($cat, "'$id'") || str_contains($cat, "id=\"$id\""));
}
check('the other category dialogs stay hand-built', str_contains($cat, 'id="catMove"') && str_contains($cat, 'id="catExpiry"') && str_contains($cat, 'id="catSheet"'));

harness_section('the catConfirm dialog renders as it did');
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/stubs.php';
function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/index.php';
}
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
if (!function_exists('osc_add_hook')) {
    function osc_add_hook($hook, $fn, $priority = 5)
    {
    }
}
$html = '';
if (preg_match("~(osc_admin_confirm_dialog\\(array\\(\\s*'id'\\s*=> 'catConfirm'.*?\\)\\);)~s", $cat, $m)) {
    ob_start();
    eval($m[1]);
    $html = (string)ob_get_clean();
}
$flat = trim((string)preg_replace('~\s+~', ' ', (string)preg_replace('~>\s+<~', '><', $html)));
$old = '<dialog id="catConfirm" class="osc-dialog" aria-labelledby="catConfirmTitle" aria-describedby="catConfirmFacts">'
    . '<div class="osc-dialog-body"><p class="osc-dialog-title" id="catConfirmTitle"></p>'
    . '<ul class="osc-dialog-facts" id="catConfirmFacts"></ul>'
    . '<div class="cat-confirm-aside" id="catConfirmAside" hidden><p></p><button type="button" class="btn btn-dim btn-sm"></button></div></div>'
    . '<div class="osc-dialog-actions"><button type="button" class="btn btn-dim btn-sm" id="catConfirmCancel" data-osc-dialog-close>Cancel</button>'
    . '<button type="button" id="catConfirmOk" class="btn btn-submit btn-sm"></button></div></dialog>';
check('same elements, ids and classes as the old dialog', $flat === $old, $flat);

harness_section('empty states and details');
foreach (array('fields/index.php', 'appearance/widgets.php') as $file) {
    check("$file has no builder-empty", !str_contains($src($file), 'builder-empty'));
}
$f = $src('fields/index.php');
check('#forms-empty and #palette-empty come from osc_admin_empty', str_contains($f, "'id'    => 'forms-empty'")
    && (bool)preg_match("~'id'\\s*=> 'palette-empty',\\s*'tag'\\s*=> 'li'~", $f));
check('widgets and menus drop the plus icon from the drop hint', !str_contains($src('appearance/widgets.php'), 'js-widget-empty"<?php echo count($widgets) > 0 ? \' style="display:none"\' : \'\'; ?>><i')
    && !str_contains($src('appearance/menus.php'), 'id="menu-empty" hidden><i'));
$sub = $src('fields/submissions.php');
check('submission details use osc_admin_definition', str_contains($sub, 'osc_admin_definition(') && !str_contains($sub, '<dl'));

exit(harness_result());
