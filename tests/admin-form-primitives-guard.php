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
 * Keeps the admin form primitives standardised once they were unified.
 *
 * Four regressions this pins, each of which was a real, silent breakage:
 *
 *  1. The modern theme once shipped its own osc_admin_form_row_open/close/checkbox in
 *     parts/ui.php. Being loaded first, that copy won and silently dropped id/style/
 *     controls_class that the field editor passed, so its show/hide rows never toggled.
 *     Core owns these now; the theme must not redefine them (it would shadow core again).
 *  2. A raw `<div class="form-row">` in a view is a row built by hand instead of through
 *     the helper, which is how the layout drift started. New ones are refused; the few
 *     genuinely bespoke blocks are named here with the reason they are exempt.
 *  3. The per-locale name/description editors were moved off Bootstrap nav-tabs onto the
 *     theme's own .osc-tab widget. A returning data-bs-toggle="tab" would split the tab
 *     UX in two again.
 *  4. A declared settings page has exactly one render-time extension point, and it is a
 *     filter over the field spec. Dropping the call, or downgrading it to a hook, takes
 *     every plugin that adjusts a field on someone else's page off the air in silence.
 *
 * DB-free: a filesystem scan, so a regression is caught the moment it is written.
 * Usage: php tests/admin-form-primitives-guard.php
 */

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$themeDir = __DIR__ . '/../oc-admin/themes/modern';
$core     = __DIR__ . '/../oc-includes/osclass/helpers/hAdminUi.php';

check('the modern theme directory is where it is expected', is_dir($themeDir));
check('core hAdminUi.php is where it is expected', is_file($core));

/* 1. The theme must not redefine the primitives core owns. */
$primitives = array(
    'osc_admin_form_row_open',
    'osc_admin_form_row_close',
    'osc_admin_checkbox',
    'osc_admin_tree_picker',
);
$themePhp = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $themePhp[] = $file->getPathname();
    }
}
foreach ($primitives as $fn) {
    $definedInTheme = null;
    foreach ($themePhp as $path) {
        if (preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', (string) file_get_contents($path))) {
            $definedInTheme = $path;
            break;
        }
    }
    check(
        $fn . '() is not redefined in the theme (core owns it)',
        $definedInTheme === null,
        (string) $definedInTheme
    );
    check(
        $fn . '() is defined in core',
        preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', (string) file_get_contents($core)) === 1
    );
}

/*
 * 2. No hand-written form row in a view -- every one goes through the helper now. If a block
 *    is genuinely not a label|control row, give it its own class rather than borrowing
 *    .form-row, and add it here with the reason. A leading * (a block-comment continuation
 *    line) is not markup and is skipped.
 */
$rawRowAllow = array();
$rawRowFound = array();
foreach ($themePhp as $path) {
    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        if (strpos($line, '<div class="form-row"') === false) {
            continue;
        }
        if (preg_match('/^\s*\*/', $line)) {
            continue; // a commented-out example, not rendered markup
        }
        $rel = str_replace($themeDir . '/', '', $path);
        $rawRowFound[$rel] = ($rawRowFound[$rel] ?? 0) + 1;
    }
}
foreach ($rawRowFound as $rel => $count) {
    check(
        'raw form-row in ' . $rel . ' is a known exception',
        isset($rawRowAllow[$rel]),
        'add it to the helper (osc_admin_form_row_open) or, if genuinely bespoke, to this test'
    );
}
foreach ($rawRowAllow as $rel => $expected) {
    check(
        'the exempt raw form-row in ' . $rel . ' is still there',
        ($rawRowFound[$rel] ?? 0) === $expected,
        'if it was converted, drop it from the allow-list'
    );
}

/* 3a. No admin view brings back a Bootstrap nav-tabs strip; tabs are .osc-tab (in-page,
 *     JS) or .osc-tabnav (URL navigation). The sidebar's nav-pills are a separate
 *     component and are not matched here. */
foreach ($themePhp as $path) {
    $rel = str_replace($themeDir . '/', '', $path);
    check(
        'no Bootstrap nav-tabs in ' . $rel,
        strpos((string) file_get_contents($path), 'nav nav-tabs') === false
    );
}

/* 3b. The multilang editors stay off Bootstrap tabs. */
$multilangForms = array(
    'oc-includes/osclass/classes/form/FieldForm.php',
    'oc-includes/osclass/classes/form/admin/Item.php',
    'oc-includes/osclass/classes/form/PageForm.php',
    'oc-includes/osclass/classes/form/UserForm.php',
);
foreach ($multilangForms as $rel) {
    $src = (string) file_get_contents(__DIR__ . '/../' . $rel);
    check(basename($rel) . ' has no Bootstrap tab toggle', strpos($src, 'data-bs-toggle="tab"') === false);
    check(basename($rel) . ' has no nav-tabs strip', strpos($src, 'nav nav-tabs') === false);
    check(basename($rel) . ' wires the shared tab widget', strpos($src, 'osc-tab') !== false);
}

/* 4. The one render-time extension point on a declared settings page. It has to stay a
 *    filter: a hook hands the listener a copy of the spec and its edits go nowhere.
 *    A substring scan sees the call site and nothing else -- that the filter actually runs
 *    per field and that its return value is used are pinned by the behavioural assertions
 *    in tests/admin-form-lifecycle-hooks.php. */
$declaredPageForm = (string) file_get_contents(
    __DIR__ . '/../oc-includes/osclass/classes/admin/ui/SettingsForm.php'
);
check(
    'the declared settings form still names admin_form_render_field as a filter',
    strpos($declaredPageForm, "osc_apply_filter('admin_form_render_field'") !== false,
    'the call site was removed, the hook renamed, or osc_apply_filter downgraded to osc_run_hook'
);

exit(harness_result());
