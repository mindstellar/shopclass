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
 * Every admin DataTable with a status column puts it right after the bulk checkbox (or first
 * when there is no checkbox), and each row fills its cells in the same order as the columns.
 * Reads the class sources, so it is DB-free.  Usage: php tests/admin-datatable-status-column.php
 */

require_once __DIR__ . '/lib/harness.php';

/**
 * Column ids and row keys per method of one class source, in the order they are written.
 *
 * @return array{columns:array<string,list<string>>,rows:array<string,list<string>>}
 */
function datatable_layout(string $source): array
{
    $tokens  = array_values(array_filter(
        token_get_all($source),
        static fn ($t) => !is_array($t) || !in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)
    ));
    $text    = static fn ($t): string => is_array($t) ? $t[1] : $t;
    $literal = static fn ($t): ?string => is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING ? substr($t[1], 1, -1) : null;
    $columns = array();
    $assign  = array();
    $arrow   = array();
    $method  = '';
    foreach ($tokens as $i => $t) {
        if (is_array($t) && $t[0] === T_FUNCTION && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING) {
            $method = $tokens[$i + 1][1];
            continue;
        }
        // $this->addColumn('id', ...)
        if ($text($t) === 'addColumn' && $text($tokens[$i - 1]) === '->' && ($id = $literal($tokens[$i + 2] ?? null)) !== null) {
            $columns[$method][] = $id;
        }
        // $row['id'] = ...
        if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === '$row' && $text($tokens[$i + 1] ?? '') === '['
            && ($id = $literal($tokens[$i + 2] ?? null)) !== null && $text($tokens[$i + 3] ?? '') === ']'
            && $text($tokens[$i + 4] ?? '') === '=') {
            $assign[$method][] = $id;
        }
        // 'id' => ... (a row built as one array literal)
        if (($id = $literal($t)) !== null && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_ARROW) {
            $arrow[$method][] = $id;
        }
    }

    return array('columns' => $columns, 'rows' => $assign + $arrow);
}

/** Problems with one column list, empty when it follows the rule. */
function status_rule_problem(array $cols): string
{
    $bulk   = array_search('bulkactions', $cols, true);
    $status = array_search('status', $cols, true);
    $lead   = array_values(array_diff($cols, array('status-border')));
    if ($bulk !== false && $status !== $bulk + 1) {
        return 'status is not right after bulkactions';
    }
    if ($bulk === false && $lead[0] !== 'status') {
        return 'status is not the first column';
    }
    if (in_array('status-border', $cols, true) && $cols[0] !== 'status-border') {
        return 'status-border is not first';
    }

    return '';
}

harness_section('The rule check itself');
check('accepts bulkactions, status', status_rule_problem(array('bulkactions', 'status', 'name')) === '');
check('accepts status-border, bulkactions, status', status_rule_problem(array('status-border', 'bulkactions', 'status', 'name')) === '');
check('accepts status first with no checkbox', status_rule_problem(array('status', 'name')) === '');
check('rejects status before the checkbox', status_rule_problem(array('status-border', 'status', 'bulkactions', 'name')) !== '');
check('rejects status later with no checkbox', status_rule_problem(array('name', 'status')) !== '');

harness_section('Admin DataTables');
$checked = array();
foreach (glob(dirname(__DIR__) . '/oc-includes/osclass/classes/datatables/*DataTable.php') ?: array() as $file) {
    $class  = basename($file, '.php');
    $layout = datatable_layout((string) file_get_contents($file));
    foreach ($layout['columns'] as $method => $cols) {
        if (!in_array('status', $cols, true)) {
            continue;
        }
        $checked[] = $class;
        $problem   = status_rule_problem($cols);
        check("$class::$method puts Status first (after the checkbox)", $problem === '', $problem . ': ' . implode(', ', $cols));

        // The row method that fills these columns is the one whose keys cover the most of them.
        $best = array();
        foreach ($layout['rows'] as $keys) {
            $keys = array_values(array_unique(array_intersect($keys, $cols)));
            if (count($keys) > count($best)) {
                $best = $keys;
            }
        }
        pin("$class row keys follow the columns", array_values(array_intersect($cols, $best)), $best);
        check("$class rows fill the status and checkbox cells", in_array('status', $best, true)
            && (!in_array('bulkactions', $cols, true) || in_array('bulkactions', $best, true)));
    }
}
sort($checked);
pin(
    'the tables with a status column',
    array('CommentsDataTable', 'ItemsDataTable', 'PluginsDataTable', 'UsersDataTable'),
    $checked
);

exit(harness_result());
