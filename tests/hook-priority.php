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
 * Pins hook priority. Any integer is a valid priority, negative included, and the
 * callbacks run lowest first. Before 6.4 a hardcoded 0-10 loop stored anything
 * outside that range and never ran it.
 *
 * DB-free.  Usage: php tests/hook-priority.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/Plugins.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/datatables/abstract/DataTable.php';
require_once __DIR__ . '/lib/harness.php';

if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return ABS_PATH . 'oc-content/plugins/';
    }
}

/** Records the order callbacks fired in. */
$GLOBALS['order'] = array();

function hp_mark($tag)
{
    return static function () use ($tag) {
        $GLOBALS['order'][] = $tag;
    };
}

/** Concrete stand-in, so the abstract column logic can be exercised. */
class HookPriorityTable extends DataTable
{
    public function table($params = null)
    {
    }
}

harness_section('Actions');

$GLOBALS['order'] = array();
Plugins::addHook('hp_action', hp_mark('at20'), 20);
Plugins::addHook('hp_action', hp_mark('at5'), 5);
Plugins::addHook('hp_action', hp_mark('atminus1'), -1);
Plugins::addHook('hp_action', hp_mark('at0'), 0);
Plugins::runHook('hp_action');

pin(
    'a callback outside 0-10 runs, and every priority runs lowest first',
    array('atminus1', 'at0', 'at5', 'at20'),
    $GLOBALS['order']
);

$GLOBALS['order'] = array();
Plugins::addHook('hp_order', hp_mark('b'), 100);
Plugins::addHook('hp_order', hp_mark('a'), -100);
Plugins::runHook('hp_order');
pin('registration order does not decide, priority does', array('a', 'b'), $GLOBALS['order']);

$GLOBALS['order'] = array();
Plugins::addHook('hp_same', hp_mark('first'), 7);
Plugins::addHook('hp_same', hp_mark('second'), 7);
Plugins::runHook('hp_same');
pin('two callbacks at one priority keep registration order', array('first', 'second'), $GLOBALS['order']);

harness_section('Filters');

Plugins::addHook('hp_filter', static function ($v) {
    return $v . 'B';
}, 30);
Plugins::addHook('hp_filter', static function ($v) {
    return $v . 'A';
}, -5);
pin('a filter outside 0-10 runs, in priority order', 'xAB', Plugins::applyFilter('hp_filter', 'x'));

harness_section('Removing and duplicates');

$fn = hp_mark('removable');
Plugins::addHook('hp_remove', $fn, 42);
Plugins::removeHook('hp_remove', $fn);
$GLOBALS['order'] = array();
Plugins::runHook('hp_remove');
pin('removeHook reaches a callback registered outside 0-10', array(), $GLOBALS['order']);

$dup = hp_mark('dup');
Plugins::addHook('hp_dup', $dup, 42);
Plugins::addHook('hp_dup', $dup, 42);
$GLOBALS['order'] = array();
Plugins::runHook('hp_dup');
pin('the duplicate guard works outside 0-10 too', array('dup'), $GLOBALS['order']);

harness_section('Non-integer priority');

$GLOBALS['order'] = array();
Plugins::addHook('hp_cast', hp_mark('word'), 'high');
Plugins::addHook('hp_cast', hp_mark('five'), 5);
Plugins::runHook('hp_cast');
pin('a non-numeric priority lands at 0, so it runs first', array('word', 'five'), $GLOBALS['order']);

harness_section('DataTable columns');

$t = new HookPriorityTable();
$t->addColumn('c20', 'twenty', 20);
$t->addColumn('c0', 'zero', 0);
$t->addColumn('c5', 'five', 5);
pin(
    'a column at 0 or above 10 is rendered, in priority order',
    array('c0' => 'zero', 'c5' => 'five', 'c20' => 'twenty'),
    $t->sortedColumns()
);

$t->removeColumn('c20');
pin(
    'removeColumn reaches a column outside 1-10',
    array('c0' => 'zero', 'c5' => 'five'),
    $t->sortedColumns()
);

exit(harness_result());
