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
 * osc_admin_data_table() draws a list screen's table from DataTable output: sort marks, raw
 * titles and cells, the status badge, screen callbacks, the empty row and the phone stack.
 *
 * Usage:  php tests/admin-data-table.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

$GLOBALS['filter_calls'] = array();

/** Records each call and adds the class a plugin would. */
function osc_apply_filter($hook, $content = '', ...$args)
{
    if ($hook === 'datatable_user_class') {
        $GLOBALS['filter_calls'][] = $args;
        $content[]                 = 'status-border';
    }

    return $content;
}

require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

function data_table_html(array $aData, array $opts = array()): string
{
    ob_start();
    osc_admin_data_table($aData, $opts);

    return (string)ob_get_clean();
}

/** Collapse whitespace and drop what sits between tags, so markup compares by content. */
function squeeze(string $html): string
{
    $html = (string)preg_replace('/\s+/', ' ', $html);

    return trim((string)preg_replace(array('/>\s+/', '/\s+</'), array('>', '<'), $html));
}

$checkAll = '<input id="check_all" type="checkbox" aria-label="Select all"/>';
$sortLink = '<a href="?sort=date&amp;direction=asc">Date</a>';
$aData    = array(
    'aColumns' => array('bulkactions' => $checkAll, 'status' => 'Status', 'name' => 'Name', 'date' => $sortLink),
    'aRows'    => array(
        array(
            'bulkactions' => '<input type="checkbox" name="id[]" value="7"/>',
            'status'      => 'Active',
            'name'        => '<a href="?id=7">Sam</a><ul class="actions"><li><a href="?edit=7">Edit</a></li></ul>',
            'date'        => '2026-11-09',
        ),
    ),
);

harness_section('sorting');
$html = data_table_html($aData, array('sort' => 'date', 'direction' => 'desc'));
check('the sorted column is marked descending', str_contains($html, '<th scope="col" class="col-date sorting_desc">'), $html);
check('no other column is marked', substr_count($html, 'sorting_') === 1, $html);
$html = data_table_html($aData, array('sort' => 'name', 'direction' => 'asc'));
check('the sorted column is marked ascending', str_contains($html, '<th scope="col" class="col-name sorting_asc">'), $html);
check('no direction reads as ascending', str_contains(data_table_html($aData, array('sort' => 'name')), 'col-name sorting_asc'));
check('no sort marks nothing', !str_contains(data_table_html($aData), 'sorting_'));
check('an array sort marks nothing', !str_contains(data_table_html($aData, array('sort' => array('name'))), 'sorting_'));

harness_section('raw output');
$html = data_table_html($aData);
check('the select-all header is intact', str_contains($html, '<th scope="col" class="col-bulkactions">' . $checkAll . '</th>'), $html);
check('the sort link is intact', str_contains($html, '<th scope="col" class="col-date">' . $sortLink . '</th>'), $html);
check('the row checkbox is intact', str_contains($html, '<td class="col-bulkactions" data-col-name="Bulkactions"><input type="checkbox" name="id[]" value="7"/></td>'), $html);
check('row actions are intact', str_contains($html, '<ul class="actions"><li><a href="?edit=7">Edit</a></li></ul>'), $html);
check('the phone label comes from the title without its link', str_contains($html, 'class="col-date" data-col-name="Date">2026-11-09<'), $html);
check('no HTML comments', !str_contains($html, '<!--'));

harness_section('hostile column key');
$key  = 'x" onclick="alert(1)';
$html = data_table_html(array('aColumns' => array($key => 'Plugin'), 'aRows' => array(array($key => 'v'))));
check('the key cannot break out of col-*', !str_contains($html, 'onclick="alert'), $html);
check('th and td both carry it escaped', substr_count($html, 'class="col-x&quot; onclick=&quot;alert(1)') === 2, $html);

harness_section('cells');
$html = data_table_html($aData);
$stated = data_table_html($aData, array('row_class' => static fn ($key, $row) => array('other', 'status-blocked')));
check('the badge carries the state from the row class', str_contains($stated, '<span class="osc-status status-blocked">Active</span>'), $stated);
$border = data_table_html($aData, array('row_class' => static fn ($key, $row) => 'status-border'));
check('status-border is not a state', str_contains($border, '<span class="osc-status">Active</span>'), $border);
check('status is a badge', str_contains($html, '<td class="col-status" data-col-name="Status"><span class="osc-status">Active</span></td>'), $html);
$short = $aData;
unset($short['aRows'][0]['status']);
$html = data_table_html($short);
check('a row without a value gets an empty cell, in column order', str_contains($html, '<td class="col-status" data-col-name="Status"></td><td class="col-name"'), $html);
$reordered = array('aColumns' => array('a' => 'A', 'b' => 'B'), 'aRows' => array(array('b' => '2', 'a' => '1')));
$html      = data_table_html($reordered);
check('cells follow the columns', strpos($html, 'col-a" data-col-name="A">1') < strpos($html, 'col-b" data-col-name="B">2'), $html);

$seen = array();
$html = data_table_html($aData, array('cell' => static function (string $k, $v, int $key) use (&$seen) {
    $seen[] = array($k, $key);

    return $k === 'name' ? '<b>custom</b>' : ($k === 'date' ? '' : null);
}));
check('cell overrides one column', str_contains($html, 'data-col-name="Name"><b>custom</b></td>'), $html);
check('an empty string blanks the cell', str_contains($html, 'data-col-name="Date"></td>'), $html);
check('null falls back to the status badge', str_contains($html, '<span class="osc-status">Active</span>'), $html);
check('null falls back to the value', str_contains($html, 'name="id[]" value="7"'), $html);
check('cell gets the column and row key', in_array(array('status', 0), $seen, true));

harness_section('rows');
$html = data_table_html($aData, array(
    'row_class' => static fn ($key, $row) => array('one', 'two-' . $key),
    'row_attrs' => static fn ($key, $row) => array('id' => 'pkg-' . $key, 'data-market-item' => 'a"b'),
));
check('row_class takes an array', str_contains($html, '<tr class="one two-0" id="pkg-0" data-market-item="a&quot;b">'), $html);
$html = data_table_html($aData, array('row_class' => static fn ($key, $row) => 'plain'));
check('row_class takes a string', str_contains($html, '<tbody><tr class="plain">'), $html);
check('no row_class leaves the tr bare', str_contains(data_table_html($aData), '<tbody><tr>'));

$raw = array(array('fk_i_user_id' => 7));
data_table_html($aData, array(
    'row_class' => static fn ($key, $row) => osc_apply_filter('datatable_user_class', array(), $raw[$key], $row),
));
check('the filter fires once per row', count($GLOBALS['filter_calls']) === 1);
check('the filter gets the raw row, then the row', $GLOBALS['filter_calls'][0] === array($raw[0], $aData['aRows'][0]));

harness_section('wrapper');
$html = squeeze(data_table_html($aData, array('wrap_id' => 'email-templates', 'class' => 'plugins-table')));
check('the wrapper holds the table and row actions', str_starts_with($html, '<div class="table-contains-actions" id="email-templates"><table class="table plugins-table">') && str_ends_with($html, '</table><div id="table-row-actions"></div></div>'), $html);
check('no wrapper id by default', str_starts_with(data_table_html($aData), '<div class="table-contains-actions"><table'));

harness_section('empty');
$noRows = array('aColumns' => $aData['aColumns'], 'aRows' => array());
$html   = data_table_html($noRows, array('empty' => array('title' => 'No users yet')));
check('one empty row', substr_count($html, 'osc-table-empty-row') === 1, $html);
check('colspan is the column count', str_contains($html, 'colspan="4"'), $html);
check('its text shows', str_contains($html, 'No users yet'), $html);
check('no spec, no row', !str_contains(data_table_html($noRows), 'osc-table-empty-row'));
check('rows hide the empty row', !str_contains(data_table_html($aData, array('empty' => array('title' => 'x'))), 'osc-table-empty-row'));

harness_section('phone stack');
$noBulk = array('aColumns' => array('name' => 'Name'), 'aRows' => array(array('name' => 'x')));
check('off with bulk checkboxes', !str_contains(data_table_html($aData), 'osc-table-stack'));
check('on without them', str_contains(data_table_html($noBulk), '<table class="table osc-table-stack">'));
check('stack true overrides', str_contains(data_table_html($aData, array('stack' => true)), 'osc-table-stack'));
check('stack false overrides', !str_contains(data_table_html($noBulk, array('stack' => false)), 'osc-table-stack'));

harness_section('golden: users/index.php');
$columns = array(
    'bulkactions' => $checkAll,
    'status'      => 'Status',
    'email'       => '<a href="?sort=email&amp;direction=desc">E-mail</a>',
    'plugin_col'  => 'Plugin &amp; col',
);
$rows     = array(array(
    'bulkactions' => '<input type="checkbox" name="id[]" value="7"/>',
    'status'      => 'Active',
    'email'       => '<a href="?id=7">s@example.test</a>',
    'plugin_col'  => '<b>42</b>',
));
$aRawRows  = array(array('pk_i_id' => 7));
$sort      = 'email';
$direction = 'desc';

// The block users/index.php copied, as it stood.
ob_start();
?>
<div class="table-contains-actions">
    <table class="table" cellpadding="0" cellspacing="0">
        <thead>
        <tr>
            <?php foreach ($columns as $k => $v) {
                if ($direction === 'desc') {
                    echo '<th class="col-' . $k . ' ' . ($sort == $k ? ('sorting_desc') : '') . '">' . $v . '</th>';
                } else {
                    echo '<th class="col-' . $k . ' ' . ($sort == $k ? ('sorting_asc') : '') . '">' . $v . '</th>';
                }
            } ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $key => $row) { ?>
            <tr class="<?php echo implode(' ', osc_apply_filter('datatable_user_class', array(), $aRawRows[$key], $row)); ?>">
                <?php foreach ($row as $k => $v) { ?>
                    <td class="col-<?php echo $k; ?>" data-col-name="<?php echo osc_admin_col_name($columns, $k); ?>"><?php
                        echo $k === 'status' ? '<span class="osc-status">' . $v . '</span>' : $v;
                    ?></td>
                <?php } ?>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <div id="table-row-actions"></div>
</div>
<?php
$old = squeeze((string)ob_get_clean());
// Dropped on purpose: unread table attributes and the blank end of a class. th gains scope.
$old = str_replace(' cellpadding="0" cellspacing="0"', '', $old);
$old = str_replace(' ">', '">', $old);
$old = str_replace('<th class=', '<th scope="col" class=', $old);

$new = squeeze(data_table_html(array('aColumns' => $columns, 'aRows' => $rows), array(
    'sort'      => $sort,
    'direction' => $direction,
    'row_class' => static fn ($key, $row) => osc_apply_filter('datatable_user_class', array(), $aRawRows[$key], $row),
)));
check('the header and a row match the old template', $old === $new, $old . "\n" . $new);
check('the fixture covers the status and plugin row', str_contains($new, '<tr class="status-border">') && str_contains($new, 'col-plugin_col'));

exit(harness_result());
