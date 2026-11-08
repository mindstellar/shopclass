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
 * CategoryTable: rows, counts and summary for the admin Categories table.
 *
 * DB-free.  Usage: php tests/category-table.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\admin\CategoryTable;

function ct_node(int $id, string $name, int $listings, array $kids = array(), bool $on = true, int $days = 60): array
{
    return array(
        'pk_i_id' => $id, 's_name' => $name, 'i_num_items' => $listings, 'i_expiration_days' => $days,
        'b_enabled' => $on ? 1 : 0, 'b_price_enabled' => 1, 'categories' => $kids,
    );
}

// Vehicles(30) > Cars(19) > Car Parts(5) > Tyres(2); Vehicles > Bikes(3); Property(8, off)
$tree = array(
    ct_node(1, 'Vehicles', 30, array(
        ct_node(2, 'Cars', 19, array(ct_node(3, 'Car Parts', 5, array(ct_node(4, 'Tyres', 2))))),
        ct_node(5, 'Bikes', 3),
    )),
    ct_node(6, 'Property', 8, array(), false, 0),
);

harness_section('Rows');
$t    = new CategoryTable($tree);
$rows = $t->rows();
pin('pre-order ids', array(1, 2, 3, 4, 5, 6), array_column($rows, 'id'));
pin('depths', array(1, 2, 3, 4, 2, 1), array_column($rows, 'depth'));
pin('parents, 0 = top', array(0, 1, 2, 3, 1, 0), array_column($rows, 'parent'));
pin('direct children', array(2, 1, 1, 0, 0, 0), array_column($rows, 'subs'));
pin('all levels below', array(4, 2, 1, 0, 0, 0), array_column($rows, 'below'));
pin('heights', array(4, 3, 2, 1, 1, 1), array_column($rows, 'height'));
pin('listings copied, never summed', array(30, 19, 5, 2, 3, 8), array_column($rows, 'listings'));
pin('expiry and name copied', array(60, 'Vehicles', false), array($rows[0]['expiry'], $rows[0]['name'], $rows[5]['enabled']));
pin('no limit: nothing deep, children allowed', array(false, true), array(in_array(true, array_column($rows, 'deep'), true), !in_array(false, array_column($rows, 'canAddChild'), true)));
pin('no limit reported as null', null, $t->depthLimit());

harness_section('Counts and summary');
pin('counts', array('all' => 6, 'on' => 5, 'off' => 1), $t->counts());
pin('summary', array('categories' => 6, 'groups' => 2, 'listings' => 38), $t->summary());

harness_section('Depth limit');
$lim = new CategoryTable($tree, 3);
$r   = array_column($lim->rows(), null, 'id');
pin('level 4 is deep', true, $r[4]['deep']);
pin('level 3 is not deep', false, $r[3]['deep']);
pin('level 3 cannot take a child', false, $r[3]['canAddChild']);
pin('level 2 can take a child', true, $r[2]['canAddChild']);
pin('limit is kept', 3, $lim->depthLimit());

harness_section('Empty and big');
$e = new CategoryTable(array());
pin('empty rows', array(), $e->rows());
pin('empty counts', array('all' => 0, 'on' => 0, 'off' => 0), $e->counts());
pin('empty summary', array('categories' => 0, 'groups' => 0, 'listings' => 0), $e->summary());

$big = array();
$id  = 0;
for ($a = 0; $a < 5; $a++) {
    $l2 = array();
    for ($b = 0; $b < 6; $b++) {
        $l3 = array();
        for ($c = 0; $c < 7; $c++) {
            $l4 = array();
            for ($d = 0; $d < 7; $d++) {
                $l4[] = ct_node(++$id + 10000, 'n', 1);
            }
            $l3[] = ct_node(++$id + 10000, 'n', 1, $l4);
        }
        $l2[] = ct_node(++$id + 10000, 'n', 1, $l3);
    }
    $big[] = ct_node(++$id + 10000, 'n', 1, $l2);
}
$start = microtime(true);
$bt    = new CategoryTable($big);
$ms    = (microtime(true) - $start) * 1000;
fwrite(STDERR, sprintf("  %d nodes in %.1f ms\n", count($bt->rows()), $ms));
check('1500-node tree builds in under 50 ms (' . count($bt->rows()) . ' rows, ' . round($ms, 1) . ' ms)', count($bt->rows()) > 1400 && $ms < 50);

exit(harness_result());
