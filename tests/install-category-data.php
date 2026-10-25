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
 * Checks the installer's two starter category trees: unique ids, real top-level parents,
 * no repeated name under one parent, names that fit the column, and every name translatable.
 * Usage:  php tests/install-category-data.php
 */

require_once __DIR__ . '/lib/harness.php';

if (!function_exists('__')) {
    function __($s)
    {
        return $s;
    }
}

$dir = __DIR__ . '/../oc-includes/osclass/installer/data/';
$expect = array('classifieds' => array(13, 90), 'shop' => array(10, 40));

foreach ($expect as $type => [$tops, $children]) {
    harness_section('categories-' . $type);
    $file = $dir . 'categories-' . $type . '.php';
    check('data file exists', is_file($file));
    $rows = is_file($file) ? require $file : array();
    $ids  = array_column($rows, 'id');
    check('ids are unique', count($ids) === count(array_unique($ids)));

    $top = array_filter($rows, static fn (array $r): bool => $r['parent'] === null);
    check("$tops top categories", count($top) === $tops);
    check("$children children", count($rows) - count($top) === $children);
    check('top ids are 1..' . $tops, array_column($top, 'id') === range(1, $tops));

    $topIds = array_column($top, 'id');
    $badParent = array_filter($rows, static fn (array $r): bool => $r['parent'] !== null && !in_array($r['parent'], $topIds, true));
    check('every child hangs on a top category', $badParent === array());

    $seen = array();
    $dup  = 0;
    $long = 0;
    foreach ($rows as $r) {
        $k = ($r['parent'] ?? 0) . '|' . mb_strtolower($r['name']);
        $dup += isset($seen[$k]) ? 1 : 0;
        $seen[$k] = true;
        $long += (mb_strlen($r['name']) > 100 || trim($r['name']) === '') ? 1 : 0;
    }
    check('no repeated name under one parent', $dup === 0);
    check('names are 1-100 characters', $long === 0);

    $src = is_file($file) ? (string) file_get_contents($file) : '';
    check('every name goes through __()', substr_count($src, "'name' =>") === count($rows)
        && substr_count($src, "'name' => __(") === count($rows));
}

exit(harness_result());
