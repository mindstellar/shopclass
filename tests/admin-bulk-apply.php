<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * BulkAction::apply() — the one loop behind all 21 admin bulk actions.
 *
 * Twenty-one copies of this loop had drifted apart. Two counted the rows submitted rather
 * than the rows changed, so selecting two listings and one stale id reported three
 * activated; two more said "changes have been made" instead of naming what changed; and all
 * five on the comments screen counted nothing at all. A count that lies is worse than no
 * count, so what this pins is mostly *what does not count*.
 *
 * DB-free.  Usage: php tests/admin-bulk-apply.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSC_DEBUG', false);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

/** Collects what the screen would have flashed. */
$GLOBALS['flashes'] = array();

function osc_add_flash_ok_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flashes'][] = $msg;
}

/** Core's plural picker, reduced to the rule the messages rely on. */
function _mn($one, $many, $n)
{
    return $n === 1 ? $one : $many;
}

use mindstellar\admin\BulkAction;

/** Put one selection on the wire. */
$select = static function (array $params): void {
    $_REQUEST = $params;
    $_GET     = $params;
    $_POST    = array();
    Params::init();
};

/** Run one bulk action and return [changed, flash]. */
$run = static function (array $params, callable $action, $one = '%d thing', $many = '%d things') use ($select) {
    $select($params);
    $GLOBALS['flashes'] = array();
    $changed            = BulkAction::apply($action, $one, $many);

    return array($changed, $GLOBALS['flashes']);
};

/** Succeeds for every id. */
$always = static function ($id) {
    return true;
};

harness_section('It counts what changed, not what was submitted');

[$n, $f] = $run(array('id' => array('1', '2', '3')), $always);
pin('three that all succeed count three', 3, $n);
pin('and the message says so', array('3 things'), $f);

// The bug this replaced: a stale id counted as a success.
[$n] = $run(array('id' => array('1', '2', '999999')), static function ($id) {
    return $id !== 999999;
});
pin('one that fails is not counted', 2, $n);

[$n] = $run(array('id' => array('1', '2')), static function ($id) {
    return false;
});
pin('none succeeding counts none', 0, $n);

// -1 is what ItemActions::activate() answers for "nothing to do", and -1 is truthy.
[$n] = $run(array('id' => array('1', '2')), static function ($id) {
    return -1;
});
pin('a truthy non-true answer still counts, so callers must compare', 2, $n);

harness_section('The message');

[$n, $f] = $run(array('id' => array('1')), $always, '%d listing has been enabled', '%d listings have been enabled');
pin('one row takes the singular', array('1 listing has been enabled'), $f);

[$n, $f] = $run(array('id' => array('1', '2')), $always, '%d listing has been enabled', '%d listings have been enabled');
pin('two take the plural', array('2 listings have been enabled'), $f);

[$n, $f] = $run(array('id' => array('1')), static function ($id) {
    return false;
}, '%d listing has been enabled', '%d listings have been enabled');
pin('nothing changed still reports, rather than going quiet', array('0 listings have been enabled'), $f);

harness_section('What is not a selection');

// A count of 0 proves nothing on its own -- a broken guard still iterates nothing and
// still tallies 0. What has to be pinned is that the action was never reached.
$calls = 0;
$count = static function ($id) use (&$calls) {
    $calls++;

    return true;
};

$calls   = 0;
[$n, $f] = $run(array(), $count);
pin('no id at all changes nothing', 0, $n);
pin('...and the action is never reached', 0, $calls);
pin('...and nothing is said, because nothing was asked for', array(), $f);

// `?id=5` instead of `?id[]=5`. This used to be a foreach over a string, and on the
// comments screen an uncaught TypeError.
$calls   = 0;
[$n, $f] = $run(array('id' => '5'), $count);
pin('a scalar id is an empty selection, not an error', 0, $n);
pin('...and the action is never reached for it either', 0, $calls);
pin('...and it stays quiet too', array(), $f);

harness_section('Ids are numeric row keys');

$seen = array();
[$n]  = $run(array('id' => array('7', '8')), static function ($id) use (&$seen) {
    $seen[] = array(gettype($id), $id);

    return true;
});
pin('each id reaches the action as an int', array(array('integer', 7), array('integer', 8)), $seen);

[$n] = $run(array('id' => array('1', '0', '-4', 'abc', '')), static function ($id) {
    return true;
});
pin('zero, negative, a word and an empty string are all dropped', 1, $n);

// The shape that made a concatenated IN(...) worth worrying about.
$reached = array();
[$n]     = $run(array('id' => array("1) OR 1=1 -- ")), static function ($id) use (&$reached) {
    $reached[] = $id;

    return true;
});
pin('an injection payload never reaches the action', array(1), $reached);

harness_section('One bad row does not abandon the rest');

$touched = array();
[$n]     = $run(array('id' => array('1', '2', '3')), static function ($id) use (&$touched) {
    $touched[] = $id;
    if ($id === 2) {
        throw new RuntimeException('that row is unusable');
    }

    return true;
});
pin('the loop carries on past a throw', array(1, 2, 3), $touched);
pin('...and the one that threw is not counted', 2, $n);

exit(harness_result());
