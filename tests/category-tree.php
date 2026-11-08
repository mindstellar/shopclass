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
 * CategoryTree: depth, ancestors, subtree, height, isInside and topMost on a plain map.
 *
 * DB-free.  Usage: php tests/category-tree.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\category\CategoryTree;

// 1 > 2 > 3 > 4, 2 > 5, 6 alone
$tree = new CategoryTree(array(1 => null, 2 => 1, 3 => 2, 4 => 3, 5 => 2, 6 => null));

harness_section('Levels');
pin('top level is depth 1', 1, $tree->depth(1));
pin('leaf at the bottom', 4, $tree->depth(4));
pin('ancestors, parent first', array(3, 2, 1), $tree->ancestors(4));
pin('top level has no ancestors', array(), $tree->ancestors(1));
pin('height of the root', 4, $tree->height(1));
pin('height of a leaf', 1, $tree->height(4));
pin('height of a middle node', 3, $tree->height(2));

harness_section('Subtree');
$sub = $tree->subtree(2);
pin('subtree holds self and all below', array(2, 3, 4, 5), (function () use ($sub) {
sort($sub);
return $sub;
})());
pin('deepest first: self is last', 2, end($sub));
pin('unknown id has an empty subtree', array(), $tree->subtree(99));
check('exists', $tree->exists(6) && !$tree->exists(99));
pin('children in map order', array(3, 5), $tree->children(2));
pin('top-level children', array(1, 6), $tree->children(null));
pin('parent of a child, and of the top', array(2, null), array($tree->parent(5), $tree->parent(1)));

harness_section('Inside and top-most');
check('4 is inside 1', $tree->isInside(4, 1));
check('1 is not inside 4', !$tree->isInside(1, 4));
check('a node is not inside itself', !$tree->isInside(2, 2));
pin('topMost drops the child, keeps order', array(2, 6), $tree->topMost(array(3, 2, 6)));
pin('topMost keeps unrelated siblings', array(3, 5), $tree->topMost(array(3, 5)));

harness_section('Bad data');
$loop = new CategoryTree(array(1 => 2, 2 => 3, 3 => 1));
pin('a parent loop ends', 2, count($loop->ancestors(1)));
pin('depth of a loop still returns', 3, $loop->depth(1));
pin('subtree of a loop ends', 3, count($loop->subtree(1)));
pin('height of a loop ends', 3, $loop->height(1));

exit(harness_result());
