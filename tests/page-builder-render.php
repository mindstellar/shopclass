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
 * Pins how core.page_builder renders when the active theme ships no
 * template-widgets.php.
 *
 * It used to call the theme's root header.php and footer.php. Neither is part of
 * core's view-filename contract: storefront has no root header.php at all, and
 * shopclass's carries site chrome only — the document head lives in each view.
 * So a page built from blocks shipped as a fragment with no <head>: no title, no
 * description, no canonical, no robots meta, on every theme. The blocks now go
 * through the theme's page.php, which the contract does guarantee.
 *
 * The theme layer is stubbed to record which view was asked for.
 *   php tests/page-builder-render.php
 */

$GLOBALS['rendered'] = array();
$GLOBALS['filters']  = array();
$GLOBALS['widgets']  = 'BLOCKS';

function osc_current_web_theme_path($file)
{
    $GLOBALS['rendered'][] = $file;
}
function osc_add_filter($hook, $fn, $priority = 5, $args = 1)
{
    $GLOBALS['filters'][$hook] = $fn;
}
function osc_apply_filter($hook, $content, ...$rest)
{
    return isset($GLOBALS['filters'][$hook]) ? ($GLOBALS['filters'][$hook])($content) : $content;
}
function osc_show_widgets($location)
{
    echo $GLOBALS['widgets'];
}
function osc_static_page_id()
{
    return 42;
}
function osc_static_page_title()
{
    return 'Example page';
}
function osc_esc_html($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
// Re-pointable, so one run can cover a theme with a canvas and one without.
$GLOBALS['themesPath'] = sys_get_temp_dir() . '/';
$GLOBALS['themeName']  = 'nonexistent-theme-' . getmypid();
function osc_themes_path()
{
    return $GLOBALS['themesPath'];
}
function osc_theme()
{
    return $GLOBALS['themeName'];
}

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/pages/PageTemplateRegistry.php';
require_once __DIR__ . '/../oc-includes/osclass/helpers/hPageTemplates.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$spec = osc_page_template('core.page_builder');

harness_section('the template is registered unconditionally');

check('core.page_builder exists with no plugin installed', is_array($spec), gettype($spec));
check('it is marked as a builder', !empty($spec['builder']));

harness_section('with no theme canvas, the blocks go through the theme page view');

$GLOBALS['rendered'] = array();
$GLOBALS['filters']  = array();
($spec['render'])(array('pk_i_id' => 42));

pin('page.php is the view rendered', array('page.php'), $GLOBALS['rendered']);
check(
    'header.php / footer.php are NOT called — neither is contract, and that lost the <head>',
    !in_array('header.php', $GLOBALS['rendered'], true)
        && !in_array('footer.php', $GLOBALS['rendered'], true),
    implode(',', $GLOBALS['rendered'])
);

harness_section('the blocks reach the view as the page text');

check('a static_page_text filter is registered', isset($GLOBALS['filters']['static_page_text']));
$text = osc_apply_filter('static_page_text', 'ORIGINAL', '');
check('the canvas replaces the editor text', strpos($text, 'BLOCKS') !== false, $text);
check('wrapped for styling', strpos($text, '<div class="page-builder">') === 0, $text);
check(
    'no <h1> of its own — page.php already prints the title, and two would be wrong',
    stripos($text, '<h1') === false,
    $text
);

harness_section('a theme that ships its own canvas still owns the page');

$themeDir = sys_get_temp_dir() . '/sc-pb-' . getmypid();
@mkdir($themeDir . '/withcanvas', 0777, true);
file_put_contents($themeDir . '/withcanvas/template-widgets.php', "<?php \$GLOBALS['ownCanvas'] = true;\n");
$GLOBALS['rendered']  = array();
$GLOBALS['ownCanvas'] = false;

// Re-point the stubs at a theme that does ship a canvas.
$GLOBALS['themesPath'] = $themeDir . '/';
$GLOBALS['themeName']  = 'withcanvas';
($spec['render'])(array('pk_i_id' => 42));
check('the theme file is required instead', $GLOBALS['ownCanvas'] === true);
pin('and no core view is rendered', array(), $GLOBALS['rendered']);

@unlink($themeDir . '/withcanvas/template-widgets.php');
@rmdir($themeDir . '/withcanvas');
@rmdir($themeDir);

exit(harness_result());

/* file end: ./tests/page-builder-render.php */
