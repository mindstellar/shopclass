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
 * An update replaces oc-includes and oc-admin whole, or leaves them as they were so the
 * file-by-file copy can run.
 *
 * Usage: php tests/upgrade-swap-folder.php
 */

define('OSCLASS_VERSION', '7.1.0');

require_once __DIR__ . '/lib/harness.php';
require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';

use mindstellar\upgrade\Osclass;
use mindstellar\upgrade\Upgrade;
use mindstellar\utility\FileSystem;

function osc_get_preference($key, $section = 'osclass')
{
    return '';
}

$base = sys_get_temp_dir() . '/osc-swap-' . bin2hex(random_bytes(4));
$put  = static function (string $file, string $body = 'x'): void {
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0755, true);
    }
    file_put_contents($file, $body);
};
$setup = static function (string $name) use ($base, $put): array {
    $origin = $base . '/' . $name . '/package';
    $target = $base . '/' . $name . '/site';
    $put($origin . '/oc-includes/a.php', 'new');
    $put($origin . '/oc-includes/sub/b.php', 'new');
    $put($target . '/oc-includes/a.php', 'old');
    $put($target . '/oc-includes/dropped.php', 'old');

    return [$origin, $target];
};

pin('7.1 to 7.2 starts a new line', true, Osclass::newMinor('7.1.4', '7.2.0'));
pin('a dev build to the next line too', true, Osclass::newMinor('7.1.0.dev2', '7.2.0'));
pin('7 to 8 too', true, Osclass::newMinor('7.9.1', '8.0.0'));
pin('a patch does not', false, Osclass::newMinor('7.1.0', '7.1.1'));
pin('rc to its stable does not', false, Osclass::newMinor('7.2.0.rc1', '7.2.0'));
pin('a reinstall does not', false, Osclass::newMinor('7.1.0', '7.1.0'));

$core = static fn (string $new): array => (new Osclass(['s_title' => 'Shopclass', 's_source_url' => 'https://github.com/x.zip', 's_new_version' => $new, 's_short_name' => 'osclass', 's_target_directory' => '/tmp/', 'a_filtered_files' => []]))->getSwapFolders();
pin('core swaps oc-admin and oc-includes on a new line', ['oc-admin', 'oc-includes'], $core('7.2.0'));
pin('core copies file by file on a patch', [], $core('7.1.1'));

[$origin, $target] = $setup('ok');
$put($target . '/oc-includes.old/stale.php');
$put($target . '/oc-includes.new/stale.php');
pin('the folder is swapped', true, Upgrade::swapFolder($origin, $target, 'oc-includes'));
pin('a changed file is new', 'new', file_get_contents($target . '/oc-includes/a.php'));
pin('an added file is there', true, is_file($target . '/oc-includes/sub/b.php'));
pin('a dropped file is gone', false, file_exists($target . '/oc-includes/dropped.php'));
pin('no .old folder is left', false, file_exists($target . '/oc-includes.old'));
pin('no .new folder is left', false, file_exists($target . '/oc-includes.new'));
pin('the package folder is used up, so sync skips it', false, file_exists($origin . '/oc-includes'));

[$origin, $target] = $setup('nolive');
(new FileSystem())->remove($target . '/oc-includes');
pin('a site without the folder is not swapped', false, Upgrade::swapFolder($origin, $target, 'oc-includes'));
pin('the package folder stays for sync', true, is_file($origin . '/oc-includes/a.php'));

[$origin, $target] = $setup('link');
rename($target . '/oc-includes', $base . '/link/real');
symlink($base . '/link/real', $target . '/oc-includes');
pin('a linked folder is not swapped', false, Upgrade::swapFolder($origin, $target, 'oc-includes'));
pin('the link target is untouched', 'old', file_get_contents($base . '/link/real/a.php'));

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    [$origin, $target] = $setup('locked');
    chmod($target, 0555);
    pin('a site that cannot rename is not swapped', false, Upgrade::swapFolder($origin, $target, 'oc-includes'));
    chmod($target, 0755);
    pin('the live folder is as it was', 'old', file_get_contents($target . '/oc-includes/a.php'));
    pin('the old file is still there', true, is_file($target . '/oc-includes/dropped.php'));
    pin('the package folder is whole for sync', true, is_file($origin . '/oc-includes/sub/b.php'));
    pin('no .new folder is left after a failure', false, file_exists($target . '/oc-includes.new'));
}

(new FileSystem())->remove($base);

exit(harness_result());
