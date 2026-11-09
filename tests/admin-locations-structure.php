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
 * The Locations screen sits on the shared admin body, sections, tables and toolbar, spaces its
 * blocks by gap, and keeps every hook location.js reads.
 *
 * Usage:  php tests/admin-locations-structure.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$theme = ABS_PATH . 'oc-admin/themes/modern/';
$page  = (string)file_get_contents($theme . 'settings/locations.php');
$list  = (string)file_get_contents($theme . 'settings/locations/list.php');
$data  = (string)file_get_contents($theme . 'settings/locations/data.php');
$prev  = (string)file_get_contents($theme . 'settings/locations/preview.php');
$scss  = (string)file_get_contents($theme . 'scss/pages-locations.scss');

/** Declarations of the top-level rule with exactly this selector, or null when it is gone. */
$rule = static function (string $selector) use ($scss): ?string {
    return preg_match('/^' . preg_quote($selector, '/') . ' \{\n(.*?)^\}/ms', $scss, $m) === 1 ? $m[1] : null;
};

foreach (array('list' => $list, 'data' => $data, 'preview' => $prev) as $name => $source) {
    pin($name . ': no hand-built table head', 0, substr_count($source, '<thead'));
    pin($name . ': one shared table', 1, substr_count($source, 'osc_admin_table('));
    check($name . ': table stays inside its scroller', str_contains($source, "'wrap'") && str_contains($source, 'loc-table-wrap'));
}

pin('page: one body opened', 1, substr_count($page, 'osc_admin_body_open('));
pin('page: and closed', 1, substr_count($page, 'osc_admin_body_close('));
check('page: the app div is the body', str_contains($page, "'class' => 'locations-app'") && !str_contains($page, '<div class="locations-app"'));
check('page: data attributes pass raw, the helper escapes them', str_contains($page, "'data-i18n' => json_encode(") && !str_contains($page, 'osc_esc_html(json_encode('));
check('page: tabs keep the attribute the script reads', str_contains($page, "'data-loc-tab'"));

pin('data: raw section titles are gone', 0, preg_match_all('/<h3[^>]*loc-section-title/', $data));
pin('data: two shared sections', 2, substr_count($data, 'osc_admin_section('));
pin('data: and both close', 2, substr_count($data, 'osc_admin_section_close('));
check('data: section classes stay', str_contains($data, "'loc-section loc-catalog'") && str_contains($data, "'loc-section loc-recalc'"));
check('data: no label points at a heading that moved', !str_contains($data, 'aria-labelledby="loc-catalog-title"') && !str_contains($data, 'aria-labelledby="loc-recalc-title"'));
foreach (array('loc-release', 'loc-outage', 'loc-recalc-form', 'data-loc-catalog-empty') as $hook) {
    check('data: keeps ' . $hook, str_contains($data, $hook));
}

check('list: toolbar comes from the helper', str_contains($list, "osc_admin_toolbar_open(array('class' => 'loc-toolbar'))") && !str_contains($list, '<div class="loc-toolbar"'));
foreach (array('"loc-head"', '"loc-body"', '"loc-head-actions"', '"loc-list"', 'id="check_all"', 'data-loc-form') as $hook) {
    check('list: keeps ' . $hook, str_contains($list, $hook));
}

foreach (array('.loc-head', '.loc-toolbar', '.loc-tablebar', '.loc-tabs') as $selector) {
    check($selector . ' has no block margin of its own', !preg_match('/margin-block/', (string)$rule($selector)));
}
check('the divider rule between sections is gone', !str_contains($scss, '.loc-section + .loc-section'));
check('the region stack leaves [hidden] alone', str_contains($scss, '.loc-list-region:not([hidden])') && str_contains($scss, '.loc-data-region:not([hidden])'));
check('the regions set no display of their own', !str_contains((string)$rule('.loc-list-region') . (string)$rule('.loc-data-region'), 'display:'));
check('the list stacks by gap', str_contains((string)$rule('.loc-list'), 'gap:'));
check('the data tab stacks by gap', str_contains((string)$rule('.loc-data'), 'gap:'));

exit(harness_result());
