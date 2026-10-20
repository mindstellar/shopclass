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
 * Pins WebThemes::loadThemeInfo(): a theme's header is read once per request, and read
 * again once its index.php changes.
 *
 * DB-free.  Usage:  php tests/theme-info.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

function osc_themes_path()
{
    return $GLOBALS['themesDir'];
}

$GLOBALS['themesDir'] = sys_get_temp_dir() . '/osc-theme-info-' . bin2hex(random_bytes(4)) . '/';
mkdir($GLOBALS['themesDir'] . 'demo', 0777, true);
$index = $GLOBALS['themesDir'] . 'demo/index.php';
file_put_contents($index, "<?php\n/*\nTheme Name: Demo\nVersion: 1.0\n*/\n");

$themes = new WebThemes();
harness_section('WebThemes::loadThemeInfo');
pin('the header is read', array('Demo', '1.0'), array_values(array_intersect_key($themes->loadThemeInfo('demo'), array('name' => 1, 'version' => 1))));

file_put_contents($index, "<?php\n/*\nTheme Name: Demo\nVersion: 2.0\n*/\n");
touch($index, filemtime($index));
pin('a second read in the same second keeps the first answer', '1.0', $themes->loadThemeInfo('demo')['version']);

touch($index, filemtime($index) + 5);
clearstatcache();
pin('a replaced file is read again', '2.0', $themes->loadThemeInfo('demo')['version']);
pin('a missing theme is false', false, $themes->loadThemeInfo('nope'));

unlink($index);
rmdir($GLOBALS['themesDir'] . 'demo');
rmdir($GLOBALS['themesDir']);

exit(harness_result());

/* file end: ./tests/theme-info.php */
