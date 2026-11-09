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
 * The theme's category depth limit: reading it, the move and create rules, and the stored copy
 * the API reads.
 *
 * DB-free.  Usage: php tests/category-depth.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

$state = array('declared' => false, 'api' => false, 'prefs' => array(), 'writes' => 0);

function osc_theme_supports(string $feature)
{
    global $state;

    return $feature === 'category-depth' ? $state['declared'] : false;
}

function osc_is_api_request(): bool
{
    global $state;

    return $state['api'];
}

function osc_get_preference($key, $section = 'osclass')
{
    global $state;

    return $state['prefs'][$key] ?? '';
}

function osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')
{
    global $state;
    $state['prefs'][$key] = $value;
    $state['writes']++;

    return true;
}

function osc_themes_path()
{
    return $GLOBALS['themes_path'] ?? '/nonexistent/';
}

function osc_add_hook($hook, $callback, $priority = 5)
{
    global $state;
    $state['hooks'][$hook][] = $callback;
}

use mindstellar\category\CategoryDepth;
use mindstellar\category\CategoryTree;

// 1-2-3-4-5 chain; 10-11-12; 20-21 (height 2); 40-41-42-43-44 chain
$tree = new CategoryTree(array(
    1 => null, 2 => 1, 3 => 2, 4 => 3, 5 => 4,
    10 => null, 11 => 10, 12 => 11,
    20 => null, 21 => 20,
    40 => null, 41 => 40, 42 => 41, 43 => 42, 44 => 43,
));

harness_section('reading the limit');
foreach (array(false, '3', 0, -1, true, array(3), 2.0) as $bad) {
    $state['declared'] = $bad;
    pin('not a limit: ' . harness_export($bad), null, CategoryDepth::limit());
}
$state['declared'] = 3;
pin('int 3', 3, CategoryDepth::limit());
$state['declared'] = false;
pin('nothing declared, everything allowed', true, CategoryDepth::allows($tree, 20, 44));
pin('nothing declared, child allowed', true, CategoryDepth::allowsChild($tree, 44));

harness_section('limit 3');
$state['declared'] = 3;
pin('child of a level-3 parent refused', false, CategoryDepth::allowsChild($tree, 12));
pin('child of a level-2 parent allowed', true, CategoryDepth::allowsChild($tree, 11));
pin('top-level child allowed', true, CategoryDepth::allowsChild($tree, null));
pin('height-2 subtree under level 2 refused', false, CategoryDepth::allows($tree, 20, 11));
pin('height-2 subtree under level 1 allowed', true, CategoryDepth::allows($tree, 20, 10));
pin('height-2 subtree to the top allowed', true, CategoryDepth::allows($tree, 21, null));

harness_section('a tree already too deep');
pin('level-5 leaf to another level-5 slot', true, CategoryDepth::allows($tree, 5, 43));
pin('level-5 leaf up', true, CategoryDepth::allows($tree, 5, 2));
pin('level-5 leaf to level 6 refused', false, CategoryDepth::allows($tree, 5, 44));
pin('deep chain to the top', true, CategoryDepth::allows($tree, 41, null));

harness_section('the API reads the stored copy');
$state['api']      = true;
$state['declared'] = 2;
pin('nothing stored, no limit', null, CategoryDepth::limit());
$state['prefs']['category_depth'] = '4';
pin('stored 4', 4, CategoryDepth::limit());
$state['prefs']['category_depth'] = 'x';
pin('junk stored', null, CategoryDepth::limit());
$state['prefs']['category_depth'] = '0';
pin('zero stored', null, CategoryDepth::limit());
$state['api'] = false;

harness_section('sync');
$state['prefs'] = array();
$state['writes'] = 0;
$state['declared'] = 3;
CategoryDepth::sync();
pin('stored', '3', $state['prefs']['category_depth'] ?? null);
CategoryDepth::sync();
pin('same value written once', 1, $state['writes']);
$state['declared'] = false;
CategoryDepth::sync();
pin('theme dropped the limit, stored empty', '', $state['prefs']['category_depth']);
pin('second write', 2, $state['writes']);
$state['api'] = true;
$state['declared'] = 5;
CategoryDepth::sync();
pin('no write on an API request', 2, $state['writes']);

harness_section('admin requests save the theme depth');
// functions.php cannot load on its own here, so its admin block is lifted out and run.
$src = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/functions.php');
$ok  = preg_match("/if \\(defined\\('OC_ADMIN'\\) && OC_ADMIN\\) \\{(.*?)\n\\}/s", $src, $m);
pin('functions.php has the admin-only block', 1, $ok);
define('OC_ADMIN', true);
$state['hooks'] = array();
eval($m[1] ?? '');
$synced = $state['hooks']['after_init_web_theme'] ?? array();
pin('after_init_web_theme runs CategoryDepth::sync', array(array(CategoryDepth::class, 'sync')), $synced);
$state['api']      = false;
$state['prefs']    = array();
$state['declared'] = 3;
foreach ($synced as $callback) {
    $callback();
}
pin('a theme declaring 3 stores 3', '3', $state['prefs']['category_depth'] ?? null);

harness_section('a theme switch stores the new limit');
$dir = sys_get_temp_dir() . '/cat-depth-' . getmypid();
@mkdir($dir, 0700, true);
file_put_contents($dir . '/child.php', "<?php\n// osc_add_theme_support('category-depth', 9);\nosc_add_theme_support( 'category-depth' , 2 );\n");
file_put_contents($dir . '/parent.php', "<?php\nosc_add_theme_support('category-depth', 4);\n");
file_put_contents($dir . '/runtime.php', "<?php\nosc_add_theme_support('category-depth', get_depth());\n");
file_put_contents($dir . '/none.php', "<?php\nosc_add_theme_support('menus', 3);\n");
pin('a literal is read, comments are not', 2, CategoryDepth::readFrom(array($dir . '/child.php', $dir . '/parent.php')));
pin('the parent fills in when the child is silent', 4, CategoryDepth::readFrom(array($dir . '/none.php', $dir . '/parent.php')));
pin('a value worked out at run time is not guessed', null, CategoryDepth::readFrom(array($dir . '/runtime.php')));
pin('a missing file means no limit', null, CategoryDepth::readFrom(array($dir . '/gone.php')));
foreach (array('kid' => "Parent Theme: base\n", 'base' => '') as $slug => $head) {
    @mkdir($dir . '/' . $slug, 0700, true);
    file_put_contents($dir . '/' . $slug . '/index.php', "<?php\n/*\nTheme Name: " . $slug . "\n" . $head . "*/\n");
}
file_put_contents($dir . '/base/functions.php', "<?php\nosc_add_theme_support('category-depth', 3);\n");
$GLOBALS['themes_path'] = $dir . '/';
$state['prefs']  = array('category_depth' => '');
$state['writes'] = 0;
CategoryDepth::activated('kid');
pin('activating a child theme stores its parent\'s limit', '3', $state['prefs']['category_depth']);
CategoryDepth::activated('kid');
pin('...and writes once', 1, $state['writes']);
CategoryDepth::activated('base');
pin('switching to the same limit writes nothing', 1, $state['writes']);
file_put_contents($dir . '/kid/functions.php', "<?php\n");
file_put_contents($dir . '/base/functions.php', "<?php\n");
CategoryDepth::activated('kid');
pin('a theme with no limit clears it', ['', 2], [$state['prefs']['category_depth'], $state['writes']]);
exec('rm -rf ' . escapeshellarg($dir));
$code = harness_code_only((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/functions.php'));
check('theme_activate runs CategoryDepth::activated on every request', preg_match("/^osc_add_hook\\('theme_activate', array\\(\\\\mindstellar\\\\category\\\\CategoryDepth::class, 'activated'\\)\\);$/m", $code) === 1);

exit(harness_result());
