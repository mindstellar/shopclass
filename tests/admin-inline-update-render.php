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
 * Renders the real Plugins page with a stubbed catalogue: a plugin with a pending update gets an
 * "Update to version X" button for the shared update flow, a blocked one gets the reason, one with
 * no update gets neither, and the state and mode badges are two stacked items.
 *
 * DB-free.  Usage: php tests/admin-inline-update-render.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);
define('OSC_DEBUG', false);
define('OSCLASS_VERSION', '7.1.0');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
require_once __DIR__ . '/lib/admin-ui-stubs.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

function osc_add_filter($hook, $fn, $priority = 5)
{
}
function osc_print_bulk_actions()
{
}
function _e($text)
{
    echo $text;
}
function _m($text)
{
    return $text;
}
function osc_show_pagination_admin($opts)
{
}
function osc_date_format()
{
    return 'Y-m-d';
}
function osc_time_format()
{
    return 'H:i';
}
function osc_themes_path()
{
    return $GLOBALS['themesDir'];
}
function osc_theme()
{
    return 'active';
}
function osc_theme_screenshot_url($theme = null)
{
    return '';
}
function osc_theme_has_screenshot($theme = null)
{
    return false;
}
function osc_update_check_state($what)
{
    return array('to_update' => $GLOBALS['themesToUpdate']);
}
function osc_customiser_spec()
{
    return null;
}
function osc_base_url($with_index = false)
{
    return 'https://example.test/';
}
function osc_csrf_token_url()
{
    return 'CSRFName=n&CSRFToken=good';
}
function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/' . ($index ? 'index.php' : '');
}
function osc_admin_count($count, string $id = ''): string
{
    return '';
}
function osc_asset_url_versioned($url)
{
    return $url;
}
function osc_current_admin_theme_js_url($file)
{
    return $file;
}
function osc_site_mode()
{
    return 'shop';
}
function osc_admin_status($state, $word)
{
    echo '<span class="osc-status status-' . $state . '">' . $word . '</span>';
}
/** The page chrome and the parts this test does not look at are blank; the market and package parts are the real files. */
function osc_current_admin_theme_path($path)
{
    if (in_array($path, array('parts/market.php', 'parts/package-ui.php'), true)) {
        require_once ABS_PATH . 'oc-admin/themes/modern/' . $path;
    }
}
foreach (array(
    'osc_admin_page', 'osc_register_script', 'osc_enqueue_script', 'osc_admin_empty', 'osc_admin_pagination',
    'osc_admin_tab_nav', 'osc_admin_list_filter', 'osc_admin_bulk_confirm_dialog', 'osc_admin_callout',
    'osc_admin_body_open', 'osc_admin_body_close', 'osc_admin_confirm_dialog',
) as $name) {
    if (!function_exists($name)) {
        eval('function ' . $name . '(...$args) { return ""; }');
    }
}

/** The pieces of the plugins controller the view reads, built by the real data table. */
function render_plugins_page(array $packages, array $updates, array $meta): string
{
    $table = new PluginsDataTable($packages);
    $data  = $table->table(array());
    $_GET  = $_REQUEST = array('page' => 'plugins');
    Params::init();
    $view = View::getInstance();
    $view->_exportVariableToView('aData', $data);
    $view->_exportVariableToView('aRawRows', $table->rawRows());
    $view->_exportVariableToView('aCounts', $table->counts());
    $view->_exportVariableToView('aMarketUpdates', $updates);
    $view->_exportVariableToView('aMarketMeta', $meta);
    $view->_exportVariableToView('iDisplayLength', 25);
    $view->_exportVariableToView('bulk_options', array());
    ob_start();
    include ABS_PATH . 'oc-admin/themes/modern/plugins/index.php';

    return (string) ob_get_clean();
}

function pkg(string $slug, string $name, string $state, array $extra = array()): array
{
    return $extra + array(
        'slug' => $slug, 'file' => $slug . '/index.php', 'name' => $name, 'version' => '1.0',
        'author' => 'A', 'author_uri' => '', 'plugin_uri' => '', 'support_uri' => '',
        'description' => $name . ' does things', 'state' => $state,
        'installed' => $state !== 'uninstalled', 'enabled' => $state === 'active',
        'update' => false, 'configurable' => false, 'modes' => array(),
    );
}

function upd(string $slug, string $to, bool $blocked = false): array
{
    return array(
        'slug' => $slug, 'name' => $slug, 'installed_version' => '1.0', 'new_version' => $to,
        'compat' => array('status' => $blocked ? 'incompatible' : 'compatible', 'blocked' => $blocked, 'reason' => $blocked ? 'Needs PHP 9.' : ''),
    );
}

/** The table row of one plugin out of the page. */
function row_of(string $html, string $slug): string
{
    return preg_match('#<tr[^>]*id="pkg-' . preg_quote($slug, '#') . '".*?</tr>#s', $html, $m) ? $m[0] : '';
}

$packages = array(
    pkg('ready', 'Ready', 'active', array('update' => true, 'modes' => array('classifieds'))),
    pkg('blocked', 'Blocked', 'active', array('update' => true)),
    pkg('current', 'Current', 'active'),
);
$updates = array(upd('ready', '1.2'), upd('blocked', '2.0', true));
$meta    = array('last_checked' => 0, 'error' => null, 'writable' => true, 'disabled' => false, 'categories' => array(), 'catalog_available' => true);

$html = render_plugins_page($packages, $updates, $meta);

harness_section('pending update');
$row = row_of($html, 'ready');
check('the row is on the page', $row !== '', $html);
check('it has an Update to version 1.2 button', strpos($row, 'Update to version 1.2') !== false, $row);
check('the button carries the shared update action, slug and version', preg_match('#<button[^>]*class="[^"]*market-action-btn[^"]*"[^>]*data-market-action="update"[^>]*data-market-slug="ready"[^>]*data-market-version="1.2"#s', $row) === 1, $row);
check('the old "Open Updates" pointer is gone', strpos($row, 'Open Updates') === false, $row);
check('the page tells market.js where the update runs', strpos($html, 'data-update-url="https://example.test/oc-admin/index.php?page=ajax&amp;action=market_update&amp;type=plugin&amp;CSRFName=n&amp;CSRFToken=good"') !== false);

harness_section('blocked update');
$row = row_of($html, 'blocked');
check('the reason is shown', strpos($row, 'Needs PHP 9.') !== false && strpos($row, 'version 2.0') !== false, $row);
check('there is no update button', strpos($row, 'data-market-action') === false, $row);

harness_section('no update');
$row = row_of($html, 'current');
check('no button and no note', strpos($row, 'data-market-update-note') === false && strpos($row, 'data-market-action') === false, $row);

harness_section('status cell');
$row = row_of($html, 'ready');
check('state and mode are two separate stacked items', preg_match('#<div class="osc-pkg-badges"><span class="osc-pkg-badge">.*?</span><span class="osc-pkg-badge">.*?</span></div>#s', $row) === 1 && substr_count($row, 'class="osc-pkg-badge"') === 2, $row);

harness_section('theme card');
$GLOBALS['themesDir'] = sys_get_temp_dir() . '/osc_inline_update_themes_' . getmypid() . '/';
foreach (array('active', 'ready', 'blocked', 'current') as $theme) {
    @mkdir($GLOBALS['themesDir'] . $theme, 0777, true);
    file_put_contents($GLOBALS['themesDir'] . $theme . '/index.php', "<?php\n/*\nTheme Name: $theme\nVersion: 1.0\nAuthor: A\n*/\n");
}
register_shutdown_function(static function () {
    foreach (glob($GLOBALS['themesDir'] . '*/index.php') as $f) {
        @unlink($f);
        @rmdir(dirname($f));
    }
    @rmdir($GLOBALS['themesDir']);
});

function render_themes_page(array $updates, array $meta, array $toUpdate): string
{
    $_GET = $_REQUEST = array('page' => 'appearance');
    Params::init();
    $GLOBALS['themesToUpdate'] = $toUpdate;
    $view = View::getInstance();
    $view->_exportVariableToView('themes', array('active', 'ready', 'blocked', 'current'));
    $view->_exportVariableToView('aMarketUpdates', $updates);
    $view->_exportVariableToView('aMarketMeta', $meta);
    ob_start();
    include ABS_PATH . 'oc-admin/themes/modern/appearance/index.php';

    return (string) ob_get_clean();
}

/** The card of one theme out of the page. */
function card_of(string $html, string $slug): string
{
    return preg_match('#<li[^>]*id="pkg-' . preg_quote($slug, '#') . '".*?</li>#s', $html, $m) ? $m[0] : '';
}

$themeUpdates = array(upd('ready', '1.3'), upd('blocked', '2.0', true));
$html         = render_themes_page($themeUpdates, $meta, array('ready', 'blocked'));
$card         = card_of($html, 'ready');
check('the card is on the page', $card !== '', $html);
check('it has an Update to version 1.3 button with the shared update data', preg_match('#<button[^>]*class="[^"]*market-action-btn[^"]*"[^>]*data-market-action="update"[^>]*data-market-slug="ready"[^>]*data-market-version="1.3"#s', $card) === 1, $card);
check('the update line shares one note cell with the mode note, so they do not overlap', preg_match('#<div class="osc-pkg-notes">\\s*<p class="osc-pkg-note.*?</p>\\s*<div class="osc-pkg-note osc-pkg-note--update" data-market-update-note#s', $card) === 1, $card);
check('the installed version is marked for the script to refresh', strpos($card, '<span data-installed-version>1.0</span>') !== false, $card);
check('the old "Open the Updates tab" pointer is gone', strpos($card, 'Open the Updates tab') === false, $card);
$card = card_of($html, 'blocked');
check('a blocked update shows the reason and no button', strpos($card, 'Needs PHP 9.') !== false && strpos($card, 'data-market-action') === false, $card);
$card = card_of($html, 'current');
check('a theme with no update has no note and no update button', strpos($card, 'data-market-update-note') === false && strpos($card, 'data-market-action') === false, $card);

exit(harness_result());
