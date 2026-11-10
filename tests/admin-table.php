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
 * osc_admin_table() draws a hand-built admin table: headers, cell forms, row attributes,
 * wrappers, the phone stack and the empty row. Plain values come out escaped.
 *
 * Usage:  php tests/admin-table.php
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
require_once __DIR__ . '/lib/admin-ui-stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

function table_html(array $columns, array $rows, array $opts = array()): string
{
    ob_start();
    osc_admin_table($columns, $rows, $opts);

    return (string)ob_get_clean();
}

/** Collapse whitespace and drop what sits between tags, so markup compares by content. */
harness_section('headers');
$html = table_html(array(
    'plain'  => 'Name <b>',
    'markup' => array('label_html' => '<input id="check_all" type="checkbox"/>'),
    'hidden' => array('label' => 'Actions', 'hide_label' => true),
    'sides'  => array('label' => 'Sides', 'numeric' => true, 'class' => 'both', 'sorted' => 'desc', 'td_class' => 'only-td'),
), array(array('plain' => 'a', 'markup' => 'b', 'hidden' => 'c', 'sides' => 'd')));
check('a plain label is escaped', str_contains($html, '<th scope="col">Name &lt;b&gt;</th>'), $html);
check('label_html is printed as given', str_contains($html, '<th scope="col"><input id="check_all" type="checkbox"/></th>'), $html);
check('hide_label keeps the label for screen readers', str_contains($html, '<th scope="col"><span class="visually-hidden">Actions</span></th>'), $html);
check('th gets numeric, class and the sort mark', str_contains($html, '<th scope="col" class="col-numeric both sorting_desc">Sides</th>'), $html);
check('td gets numeric, class and td_class', str_contains($html, '<td class="col-numeric both only-td" data-col-name="Sides">d</td>'), $html);

harness_section('cells');
$html = table_html(array(
    'text'   => 'Text',
    'zero'   => 'Zero',
    'html'   => 'Html',
    'render' => 'Render',
    'status' => 'Status',
    'none'   => 'None',
    'gone'   => 'Gone',
), array(array(
    'gone'   => null,
    'none'   => array('class' => 'blank'),
    'status' => array('status' => array('active', 'On <now>'), 'class' => 'st'),
    'render' => array('render' => static function () {
        echo '<em>drawn</em>';
    }),
    'html'   => array('html' => '<a href="#">link</a>', 'class' => 'h'),
    'zero'   => 0,
    'text'   => '<script>alert(1)</script>',
)));
check('a scalar is escaped', str_contains($html, '>&lt;script&gt;alert(1)&lt;/script&gt;</td>') && !str_contains($html, '<script>'), $html);
check('a zero still prints', str_contains($html, '<td data-col-name="Zero">0</td>'), $html);
check('an html cell is trusted and takes a class', str_contains($html, '<td class="h" data-col-name="Html"><a href="#">link</a></td>'), $html);
check('a render cell echoes in place', str_contains($html, '<td data-col-name="Render"><em>drawn</em></td>'), $html);
check('a status cell is the status pill, word escaped', str_contains($html, '<td class="st" data-col-name="Status"><span class="osc-status status-active">On &lt;now&gt;</span></td>'), $html);
check('a cell with only a class is empty', str_contains($html, '<td class="blank" data-col-name="None"></td>'), $html);
check('a null cell is empty', str_contains($html, '<td data-col-name="Gone"></td>'), $html);
preg_match_all('/data-col-name="(\w+)"/', $html, $names);
check('cells follow column order, not row order', $names[1] === array('Text', 'Zero', 'Html', 'Render', 'Status', 'None', 'Gone'), implode(',', $names[1]));

$html = table_html(array('a' => 'A', 'b' => 'B'), array(array('a' => '1')));
check('a missing cell is an empty td', str_contains($html, '<td data-col-name="A">1</td><td data-col-name="B"></td>'), $html);

harness_section('rows');
$html = table_html(array('a' => 'A'), array(
    array('a' => 'plain'),
    array('cells' => array('a' => 'spec'), 'class' => 'status-x', 'attrs' => array('data-sub-id' => '5"', 'hidden' => true, 'id' => false)),
));
check('a plain row has a bare tr', str_contains($html, '<tbody><tr><td data-col-name="A">plain</td></tr>'), $html);
check('the row form sets class and escaped attrs', str_contains($html, '<tr class="status-x" data-sub-id="5&quot;" hidden><td data-col-name="A">spec</td></tr>'), $html);
$html = table_html(array('a' => 'A'), array(array('cells' => array('a' => '1'), 'class' => 'one', 'attrs' => array('class' => 'two'))));
check('row class and a class in attrs merge', substr_count($html, '<tr class=') === 1 && str_contains($html, '<tr class="one two">'), $html);

harness_section('data-col-name');
$html = table_html(array(
    'label' => 'Shown & told',
    'named' => array('label' => 'Symbol', 'col_name' => 'Currency symbol'),
    'off'   => array('label' => 'Off', 'col_name' => false),
    'mark'  => array('label_html' => '<input/>'),
), array(array('label' => '1', 'named' => '2', 'off' => '3', 'mark' => '4')));
check('it defaults to the label, escaped', str_contains($html, '<td data-col-name="Shown &amp; told">1</td>'), $html);
check('col_name replaces it, the header keeps the label', str_contains($html, '<td data-col-name="Currency symbol">2</td>') && str_contains($html, '>Symbol</th>'), $html);
check('false leaves it off', str_contains($html, '<td>3</td>'), $html);
check('a markup-only header has none', str_contains($html, '<td>4</td>'), $html);

harness_section('table and wrappers');
$one  = array('a' => 'A');
$html = table_html($one, array());
check('the default wrapper is table-responsive', str_starts_with($html, '<div class="table-responsive"><table class="table"><thead>') && str_ends_with($html, '</tbody></table></div>'), $html);
$html = table_html($one, array(), array('wrap_id' => 'x"y'));
check('wrap_id goes on the wrapper, escaped', str_starts_with($html, '<div class="table-responsive" id="x&quot;y"><table'), $html);
$html = table_html($one, array(), array('wrap' => 'actions', 'wrap_id' => 'media-table'));
check('actions wraps it and adds the row actions node last', str_starts_with($html, '<div class="table-contains-actions" id="media-table"><table') && str_ends_with($html, '</table><div id="table-row-actions"></div></div>'), $html);
$html = table_html($one, array(), array('wrap' => false, 'wrap_id' => 'ignored'));
check('false has no wrapper and no wrap_id', str_starts_with($html, '<table') && str_ends_with($html, '</table>') && !str_contains($html, 'ignored'), $html);
$html = table_html($one, array(), array('class' => 'media-grid', 'id' => 't1', 'attrs' => array('data-x' => '1', 'class' => 'extra'), 'stack' => true));
check('class, stack, id and attrs land on the table', str_contains($html, '<table class="table media-grid osc-table-stack extra" id="t1" data-x="1"><thead>'), $html);
check('no stack class by default', !str_contains(table_html($one, array()), 'osc-table-stack'));
check('no HTML comment in the output', !str_contains($html, '<!--'), $html);

harness_section('empty');
$cols = array('a' => 'A', 'b' => 'B', 'c' => 'C');
$html = table_html($cols, array(), array('empty' => array('title' => 'None yet')));
check('one empty row spanning every column', substr_count($html, 'osc-table-empty-row') === 1 && str_contains($html, '<tr class="osc-table-empty-row"><td colspan="3">'), $html);
check('...with its title', str_contains($html, 'None yet'), $html);
check('no empty row when the option is left out', !str_contains(table_html($cols, array()), 'osc-table-empty-row'));
check('no empty row when there are rows', !str_contains(table_html($cols, array(array('a' => '1')), array('empty' => array('title' => 'None yet'))), 'osc-table-empty-row'));

harness_section('golden: billing/packages.php');
$package = array('pk_i_id' => 7, 's_name' => 'Starter & Co', 'b_enabled' => 1, 'i_credits' => 1200);
$money   = '$5.00';
$editUrl = 'https://example.test/oc-admin/index.php?page=billing&action=package&id=' . $package['pk_i_id'];
ob_start(); ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col" class="col-status">Status</th>
                    <th scope="col">Name</th>
                    <th scope="col" class="col-numeric">Price</th>
                    <th scope="col" class="col-numeric">Credits</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="col-status">
                            <?php osc_admin_status(
                                $package['b_enabled'] ? 'active' : 'inactive',
                                $package['b_enabled'] ? 'Enabled' : 'Disabled'
                            ); ?>
                        </td>
                        <td>
                            <a href="<?php echo osc_esc_html($editUrl); ?>">
                                <?php echo osc_esc_html($package['s_name']); ?>
                            </a>
                        </td>
                        <td class="col-numeric">
                            <?php echo osc_esc_html($money); ?>
                        </td>
                        <td class="col-numeric"><?php echo number_format((int) $package['i_credits']); ?></td>
                        <td>
                            <a class="btn btn-secondary btn-sm" href="<?php echo osc_esc_html($editUrl); ?>">
                                Edit
                            </a>
                            <button type="button" class="btn btn-danger btn-sm"
                                    data-osc-dialog-open="#package-delete-<?php echo (int) $package['pk_i_id']; ?>">
                                Delete
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
<?php
$old = (string)ob_get_clean();

$new = table_html(
    array(
        'status'  => array('label' => 'Status', 'class' => 'col-status', 'col_name' => false),
        'name'    => array('label' => 'Name', 'col_name' => false),
        'price'   => array('label' => 'Price', 'numeric' => true, 'col_name' => false),
        'credits' => array('label' => 'Credits', 'numeric' => true, 'col_name' => false),
        'actions' => array('label' => 'Actions', 'col_name' => false),
    ),
    array(array(
        'status'  => array('status' => array('active', 'Enabled')),
        'name'    => array('html' => '<a href="' . osc_esc_html($editUrl) . '">' . osc_esc_html($package['s_name']) . '</a>'),
        'price'   => $money,
        'credits' => number_format((int)$package['i_credits']),
        'actions' => array('render' => static function () use ($editUrl, $package) {
            echo '<a class="btn btn-secondary btn-sm" href="' . osc_esc_html($editUrl) . '">Edit</a> ';
            echo '<button type="button" class="btn btn-danger btn-sm" data-osc-dialog-open="#package-delete-' . (int)$package['pk_i_id'] . '">Delete</button>';
        }),
    ))
);
check('the header and a row match the hand-written markup', squeeze($old) === squeeze($new), squeeze($new));

exit(harness_result());
