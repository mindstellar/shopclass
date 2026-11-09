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
 * The admin helper options the last screens need: table caption, colspan and row headers, panel
 * stack and count, toolbar class. A caller that uses none of them gets the same bytes as before.
 *
 * Usage:  php tests/admin-finish-helpers.php
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

function finish_table(array $columns, array $rows, array $opts = array()): string
{
    ob_start();
    osc_admin_table($columns, $rows, $opts);

    return (string)ob_get_clean();
}

function finish_panel(string $title, array $opts = array()): string
{
    ob_start();
    osc_admin_panel_open($title, $opts);
    osc_admin_panel_close();

    return (string)ob_get_clean();
}

function finish_toolbar(array $opts = array()): string
{
    ob_start();
    osc_admin_toolbar_open($opts);
    osc_admin_toolbar_close();

    return (string)ob_get_clean();
}

/** Columns a row fills, counting each colspan. */
function finish_row_width(string $row): int
{
    preg_match_all('/<t[dh](?:\s[^>]*)?>/', $row, $cells);
    $width = 0;
    foreach ($cells[0] as $cell) {
        $width += preg_match('/colspan="(\d+)"/', $cell, $m) === 1 ? (int)$m[1] : 1;
    }

    return $width;
}

$columns = array('a' => 'A', 'b' => 'B', 'c' => 'C');

harness_section('table caption');
$html = finish_table($columns, array(), array('caption' => 'Names <b> & "x"'));
check('the caption is escaped and visually hidden', str_contains($html, '<caption class="visually-hidden">Names &lt;b&gt; &amp; &quot;x&quot;</caption>'), $html);
check('the caption comes before the header', (int)strpos($html, '<caption') < (int)strpos($html, '<thead'), $html);
check('an empty caption prints nothing', !str_contains(finish_table($columns, array(), array('caption' => '')), '<caption'));

harness_section('table colspan');
$html = finish_table($columns, array(array('a' => array('html' => 'Total', 'colspan' => 2), 'b' => 'hidden', 'c' => 'end')));
check('colspan is printed on the cell', str_contains($html, '<td colspan="2" data-col-name="A">Total</td>'), $html);
check('the covered column is skipped', !str_contains($html, 'hidden'), $html);
check('the next column still prints', str_contains($html, '<td data-col-name="C">end</td>'), $html);
preg_match('/<tbody><tr>(.*?)<\/tr>/', $html, $row);
check('the row stays at the column count', finish_row_width($row[1] ?? '') === 3, $html);

$html = finish_table($columns, array(array('a' => 'x', 'b' => array('html' => 'wide', 'colspan' => 9), 'c' => 'hidden')));
check('a colspan past the end stops at the last column', str_contains($html, '<td colspan="2" data-col-name="B">wide</td>'), $html);
preg_match('/<tbody><tr>(.*?)<\/tr>/', $html, $row);
check('so the row is not too wide', finish_row_width($row[1] ?? '') === 3, $html);

foreach (array(1, 0, -2) as $span) {
    $html = finish_table($columns, array(array('a' => array('html' => 'x', 'colspan' => $span), 'b' => 'kept', 'c' => 'y')));
    check('colspan ' . $span . ' prints no attribute', !str_contains($html, 'colspan'), $html);
    check('colspan ' . $span . ' skips nothing', str_contains($html, '>kept</td>'), $html);
}

harness_section('table row header');
$html = finish_table(array('a' => array('label' => 'A', 'numeric' => true), 'b' => 'B'), array(array('a' => array('html' => 'Row', 'th' => true, 'class' => 'x'), 'b' => 'v')));
check('th prints a row header with the cell classes', str_contains($html, '<th scope="row" class="col-numeric x" data-col-name="A">Row</th>'), $html);
check('the next cell stays a td', str_contains($html, '<td data-col-name="B">v</td>'), $html);

$html = finish_table($columns, array(array('a' => array('html' => 'Total', 'th' => true, 'colspan' => 2), 'c' => 'v')));
check('th and colspan combine', str_contains($html, '<th scope="row" colspan="2" data-col-name="A">Total</th>'), $html);

harness_section('a plain table is unchanged');
$html = finish_table(array('n' => 'Name', 'q' => array('label' => 'Qty', 'numeric' => true)), array(array('n' => 'a', 'q' => 1)));
check('same bytes as before', $html === '<div class="table-responsive"><table class="table"><thead><tr><th scope="col">Name</th>'
    . '<th scope="col" class="col-numeric">Qty</th></tr></thead><tbody><tr><td data-col-name="Name">a</td>'
    . '<td class="col-numeric" data-col-name="Qty">1</td></tr></tbody></table></div>', $html);

harness_section('panel');
$html = finish_panel('Title <b>', array('stack' => true, 'class' => 'mine'));
check('stack adds the class', str_contains($html, '<div class="osc-panel osc-panel-stack mine">'), $html);
$html = finish_panel('Title <b>', array('count' => 1234));
check('count prints a badge inside the heading, the title still escaped', str_contains($html, '<h3>Title &lt;b&gt; <span class="osc-count">1,234</span></h3>'), $html);
$html = finish_panel('Title', array('count' => 0));
check('a zero count still shows, muted', str_contains($html, '<span class="osc-count is-zero">0</span></h3>'), $html);
check('a panel without a title has no badge', !str_contains(finish_panel('', array('count' => 3)), 'osc-count'));
check('a plain panel is the same bytes as before', finish_panel('Title <b>') === '<div class="osc-panel"><div class="osc-panel-head"><h3>Title &lt;b&gt;</h3></div>'
    . '<div class="osc-panel-body"></div></div>', finish_panel('Title <b>'));

harness_section('toolbar');
check('class joins the toolbar, escaped', finish_toolbar(array('class' => 'loc-toolbar "x"')) === '<div class="osc-toolbar osc-toolbar-between loc-toolbar &quot;x&quot;"></div>', finish_toolbar(array('class' => 'loc-toolbar "x"')));
check('a plain toolbar is the same bytes as before', finish_toolbar(array('align' => 'end')) === '<div class="osc-toolbar osc-toolbar-end"></div>', finish_toolbar(array('align' => 'end')));

harness_section('styles');
$scss      = ABS_PATH . 'oc-admin/themes/modern/scss/';
$structure = (string)file_get_contents($scss . '_structure.scss');
check('a stack panel body is a gap column', preg_match('/\.osc-panel-stack > \.osc-panel-body \{\s*display: flex;\s*flex-direction: column;\s*gap: var\(--osc-field-gap\);/', $structure) === 1);
check('an empty error list takes no gap', str_contains($structure, ':is(.osc-body, .osc-section-body) > #error_list:empty'));
check('the read width token exists', str_contains((string)file_get_contents($scss . '_brand.scss'), '--osc-read-width: 56rem;'));
preg_match('/^\.osc-panel-body \{.*?^\}/ms', (string)file_get_contents($scss . 'widget-box.scss'), $plain);
check('a plain panel body stays block flow', ($plain[0] ?? '') !== '' && !str_contains($plain[0], 'display:'));

exit(harness_result());
