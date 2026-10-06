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
 * The updater must find files it cannot write before it changes anything, so a server where
 * the web user does not own the files fails cleanly instead of half way.
 *
 * Usage: php tests/upgrade-unwritable.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';

use mindstellar\upgrade\Upgrade;

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    echo "skip: root can write everywhere\n";
    exit(0);
}

$base   = sys_get_temp_dir() . '/osc-unwritable-' . bin2hex(random_bytes(4));
$origin = $base . '/package';
$target = $base . '/site';
foreach (['/oc-includes/new', '/oc-content'] as $dir) {
    mkdir($origin . $dir, 0755, true);
}
mkdir($target . '/oc-includes', 0755, true);
mkdir($target . '/oc-content', 0755, true);
file_put_contents($origin . '/index.php', 'new');
file_put_contents($origin . '/oc-includes/a.php', 'new');
file_put_contents($origin . '/oc-includes/new/b.php', 'new');
file_put_contents($origin . '/oc-content/c.php', 'new');
file_put_contents($target . '/index.php', 'old');
file_put_contents($target . '/oc-includes/a.php', 'old');

pin('a writable site has nothing blocked', [], Upgrade::unwritable($origin, $target, ['oc-content']));

chmod($target . '/index.php', 0444);
chmod($target . '/oc-includes', 0555);
chmod($target . '/oc-content', 0555);
$blocked = Upgrade::unwritable($origin, $target, ['oc-content']);
sort($blocked);
pin('a read-only file and the folder a new file needs are blocked', [
    $target . '/index.php',
    $target . '/oc-includes',
], $blocked);
pin('a skipped folder is never checked', false, in_array($target . '/oc-content', $blocked, true));

chmod($target . '/oc-includes', 0755);
chmod($target . '/oc-content', 0755);
chmod($target . '/index.php', 0644);
(new mindstellar\utility\FileSystem())->remove($base);

exit(harness_result());
