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
 * The package-ci bundle runs on its own, with no core checkout beside it.
 *
 * The registry repos build the catalog from this bundle alone, so a core class the bundled
 * files use but the bundle leaves out is a fatal error there and nowhere here.
 *
 * Usage: php tests/package-ci-bundle.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

$dir = sys_get_temp_dir() . '/package-ci-bundle-' . getmypid();
foreach (file(ABS_PATH . 'tools/ci/package-ci-files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line[0] === '#') {
        continue;
    }
    [$src, $dest] = preg_split('/\s+/', trim($line));
    if (!is_file(ABS_PATH . $src)) {
        continue;
    }
    @mkdir(dirname($dir . '/' . $dest), 0777, true);
    copy(ABS_PATH . $src, $dir . '/' . $dest);
}

// The same classes build-catalog.php loads, read from the bundle in a fresh process.
$script = 'foreach (["Escape.php", "Markdown.php", "PackageManifest.php"] as $f) { require_once $f; }'
    . ' echo \mindstellar\market\Markdown::toHtml("# Title <b>x</b> [a](https://example.com)");';
$out = (string) shell_exec('cd ' . escapeshellarg($dir) . ' && ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');

harness_section('the bundle renders a README with nothing from core beside it');
check('the heading is rendered and its tags escaped', strpos($out, '<h1>Title &lt;b&gt;x&lt;/b&gt;') !== false);

array_map('unlink', glob($dir . '/*.*') ?: []);
foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
    array_map('unlink', glob($sub . '/*') ?: []);
    rmdir($sub);
}
@rmdir($dir);

exit(harness_result());
