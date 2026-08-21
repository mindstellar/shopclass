<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins PluginAjaxFile::resolve(), the guard on the `custom` ajax action.
 *
 * That action ends in require_once, so the two directions both matter: what a
 * working plugin asks for still resolves, and everything that would execute a
 * file the plugin never meant to run does not.  Usage: php tests/plugin-ajax-file.php
 */

require_once __DIR__ . '/../oc-includes/osclass/classes/security/PluginAjaxFile.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\security\PluginAjaxFile;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

// A throwaway tree shaped like oc-content/plugins: one plugin with an ajax
// endpoint, the non-PHP files a real plugin ships beside it, and a sibling
// directory outside the root to aim at.
$base = sys_get_temp_dir() . '/osc-ajaxfile-' . getmypid();
@mkdir($base . '/plugins/demo/admin', 0777, true);
@mkdir($base . '/outside', 0777, true);
$root = $base . '/plugins/';

file_put_contents($root . 'demo/ajax.php', '<?php // endpoint');
file_put_contents($root . 'demo/admin/ajax.php', '<?php // admin endpoint');
file_put_contents($root . 'demo/README.md', 'not code');
file_put_contents($root . 'demo/composer.lock', '{}');
file_put_contents($base . '/outside/evil.php', '<?php // outside the root');

harness_section('accepts what a working plugin asks for');
pin(
    'plugin ajax endpoint resolves',
    realpath($root . 'demo/ajax.php'),
    PluginAjaxFile::resolve('demo/ajax.php', $root)
);
pin(
    'nested endpoint resolves',
    realpath($root . 'demo/admin/ajax.php'),
    PluginAjaxFile::resolve('demo/admin/ajax.php', $root)
);
pin(
    'leading slash tolerated',
    realpath($root . 'demo/ajax.php'),
    PluginAjaxFile::resolve('/demo/ajax.php', $root)
);
// The extension test is case-insensitive, so a plugin shipping Ajax.PHP is not
// locked out. Asserted against a file that really carries that spelling --
// naming it on a case-sensitive filesystem otherwise fails at realpath() and
// would pass for the wrong reason.
file_put_contents($root . 'demo/Upper.PHP', '<?php // endpoint');
pin(
    'uppercase extension accepted',
    realpath($root . 'demo/Upper.PHP'),
    PluginAjaxFile::resolve('demo/Upper.PHP', $root)
);

harness_section('refuses files the plugin never meant to execute');
pin('markdown rejected', null, PluginAjaxFile::resolve('demo/README.md', $root));
pin('lockfile rejected', null, PluginAjaxFile::resolve('demo/composer.lock', $root));
pin('extensionless rejected', null, PluginAjaxFile::resolve('demo/ajax', $root));
pin('missing file rejected', null, PluginAjaxFile::resolve('demo/nope.php', $root));
pin('directory rejected', null, PluginAjaxFile::resolve('demo', $root));
pin('empty rejected', null, PluginAjaxFile::resolve('', $root));

harness_section('refuses anything landing outside the plugins directory');
pin('traversal rejected', null, PluginAjaxFile::resolve('../outside/evil.php', $root));
pin('deep traversal rejected', null, PluginAjaxFile::resolve('demo/../../outside/evil.php', $root));
pin('absolute path rejected', null, PluginAjaxFile::resolve($base . '/outside/evil.php', $root));
pin('NUL byte rejected', null, PluginAjaxFile::resolve("demo/ajax.php\0.md", $root));

if (function_exists('symlink')) {
    @symlink($base . '/outside/evil.php', $root . 'demo/link.php');
    if (is_link($root . 'demo/link.php')) {
        // The old '../' string check could not see this: the path contains no
        // traversal at all, the symlink does the escaping.
        pin('symlink out of root rejected', null, PluginAjaxFile::resolve('demo/link.php', $root));
    }
}

// tidy up
@unlink($root . 'demo/link.php');
@unlink($root . 'demo/ajax.php');
@unlink($root . 'demo/Upper.PHP');
@unlink($root . 'demo/admin/ajax.php');
@unlink($root . 'demo/README.md');
@unlink($root . 'demo/composer.lock');
@unlink($base . '/outside/evil.php');
@rmdir($root . 'demo/admin');
@rmdir($root . 'demo');
@rmdir($base . '/plugins');
@rmdir($base . '/outside');
@rmdir($base);

exit(harness_result());
