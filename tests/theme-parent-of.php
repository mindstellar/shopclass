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
 * Pins the real WebThemes::parentOf() against theme folders written to disk.
 *
 * DB-free.  Usage:  php tests/theme-parent-of.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

function osc_themes_path()
{
    return $GLOBALS['themesDir'];
}

$GLOBALS['themesDir'] = sys_get_temp_dir() . '/osc-theme-parent-' . bin2hex(random_bytes(4)) . '/';
$themes = array(
    'child'    => "<?php\n/*\nTheme Name: Child\nParent Theme: storefront\n*/\n",
    'parent'   => "<?php\n/*\nTheme Name: Parent\n*/\n",
    'selfish'  => "<?php\n/*\nTheme Name: Selfish\nParent Theme: selfish\n*/\n",
    'pathy'    => "<?php\n/*\nTheme Name: Pathy\nParent Theme: ../evil\n*/\n",
    'noheader' => "<?php\n// no header here\n",
);
foreach ($themes as $slug => $code) {
    mkdir($GLOBALS['themesDir'] . $slug, 0777, true);
    file_put_contents($GLOBALS['themesDir'] . $slug . '/index.php', $code);
}

$web = new WebThemes();
harness_section('WebThemes::parentOf');
pin('a header names the parent', 'storefront', $web->parentOf('child'));
pin('a theme without the field has none', '', $web->parentOf('parent'));
pin('a theme without a header has none', '', $web->parentOf('noheader'));
pin('a theme cannot be its own parent', '', $web->parentOf('selfish'));
pin('a parent carrying a path is refused', '', $web->parentOf('pathy'));
pin('a missing theme has none', '', $web->parentOf('nope'));

foreach (array_keys($themes) as $slug) {
    unlink($GLOBALS['themesDir'] . $slug . '/index.php');
    rmdir($GLOBALS['themesDir'] . $slug);
}
rmdir($GLOBALS['themesDir']);

exit(harness_result());

/* file end: ./tests/theme-parent-of.php */
