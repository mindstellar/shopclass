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
 * Pins that deleting a directory never deletes what a symlink points at.
 *
 * This is written from a real loss. A theme directory was a symlink to a checkout
 * elsewhere on the machine; deleting the theme from the admin emptied that checkout,
 * `.git` and all. The version control was the only reason it was recoverable.
 *
 * FileSystem::remove() did test is_link() before recursing, so the bug was subtler than
 * a missing check: **is_link() resolves through a trailing slash and answers false**,
 * while is_dir() still answers true. The theme delete passes `themes/<name>/`, with the
 * slash, so the link was never recognised and the directory branch walked into it.
 *
 * What is pinned:
 *
 *  - a symlink is removed as itself, with and without a trailing slash;
 *  - what it pointed at is untouched, including a dot-directory like .git;
 *  - a symlink *inside* a directory being deleted goes the same way;
 *  - deleting an ordinary directory still works, or this would be a safe no-op.
 *
 * DB-free. Usage:  php tests/delete-follows-no-symlink.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/utility/FileSystem.php';

use mindstellar\utility\FileSystem;

$root = sys_get_temp_dir() . '/osc-symlink-delete-' . getmypid() . '/';

/** A directory standing in for the checkout that must survive. */
$makeTarget = static function (string $name) use ($root) {
    $dir = $root . $name . '/';
    mkdir($dir . '.git', 0777, true);
    file_put_contents($dir . 'index.php', '<?php // the theme');
    file_put_contents($dir . '.git/HEAD', "ref: refs/heads/main\n");

    return $dir;
};

$rm = static function (string $dir) use (&$rm) {
    if (!is_dir($dir) && !is_link($dir)) {
        return;
    }
    if (is_link(rtrim($dir, '/'))) {
        @unlink(rtrim($dir, '/'));

        return;
    }
    foreach (array_diff(scandir($dir) ?: array(), array('.', '..')) as $entry) {
        $path = rtrim($dir, '/') . '/' . $entry;
        is_link($path) ? @unlink($path) : (is_dir($path) ? $rm($path) : @unlink($path));
    }
    @rmdir($dir);
};

$fs = new FileSystem();

harness_section('A symlink to a directory is removed as itself');

// The shape that caused the loss: the caller appends a separator, as the theme delete does.
$rm(rtrim($root, '/'));
mkdir($root, 0777, true);
$target = $makeTarget('checkout-a');
symlink(rtrim($target, '/'), $root . 'link-a');

$fs->deleteDir($root . 'link-a/');   // <- trailing slash, exactly as the admin passes it
check('the link is gone', !is_link($root . 'link-a') && !file_exists($root . 'link-a'));
check('the checkout it pointed at survives', is_dir($target));
check('its files survive', file_exists($target . 'index.php'));
check('its .git survives', file_exists($target . '.git/HEAD'));

harness_section('And with no trailing slash');

$target2 = $makeTarget('checkout-b');
symlink(rtrim($target2, '/'), $root . 'link-b');

$fs->deleteDir($root . 'link-b');
check('the link is gone', !is_link($root . 'link-b') && !file_exists($root . 'link-b'));
check('the checkout survives', is_dir($target2) && file_exists($target2 . '.git/HEAD'));

harness_section('A symlink inside a directory being deleted');

$target3 = $makeTarget('checkout-c');
mkdir($root . 'wrapper/inner', 0777, true);
file_put_contents($root . 'wrapper/own.txt', 'mine');
symlink(rtrim($target3, '/'), $root . 'wrapper/inner/nested-link');

$fs->deleteDir($root . 'wrapper/');
check('the wrapper is gone', !is_dir($root . 'wrapper'));
check('what the nested link pointed at survives', is_dir($target3));
check('including its .git', file_exists($target3 . '.git/HEAD'));

harness_section('An ordinary directory is still deleted');

mkdir($root . 'plain/sub', 0777, true);
file_put_contents($root . 'plain/a.txt', 'x');
file_put_contents($root . 'plain/sub/b.txt', 'y');

check('deleteDir reports success', $fs->deleteDir($root . 'plain/') === true);
check('and the directory is gone', !is_dir($root . 'plain'));

harness_section('The guards that were already there still hold');

check('a relative parent hop is refused', $fs->deleteDir($root . '../etc') === false);
check('a path that is not a directory is refused', $fs->deleteDir($root . 'no-such-thing') === false);

$rm(rtrim($root, '/'));

exit(harness_result());
