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
 * The installed plugins data table (rows, status tabs, search, sort, paging) and the Enable,
 * Disable and Uninstall bulk actions: each goes through the same one-plugin step as the
 * single action, skips what cannot change, fires its hook and is refused without a CSRF token.
 *
 * DB-free.  Usage: php tests/admin-plugins-datatable.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);
define('OSC_DEBUG', false);
define('OSCLASS_VERSION', '7.1.0');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';

class CsrfRefused extends RuntimeException
{
}

function __($text, $domain = 'core')
{
    return $text;
}
function _m($text)
{
    return $text;
}
function _mn($one, $many, $n)
{
    return $n === 1 ? $one : $many;
}
function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/' . ($index ? 'index.php' : '');
}
function osc_csrf_token_url()
{
    return 'CSRFName=n&CSRFToken=good';
}
function osc_csrf_check()
{
    if (Params::getParam('CSRFToken') !== 'good') {
        throw new CsrfRefused();
    }
}
function osc_plugins_path()
{
    return $GLOBALS['pluginsPath'];
}
function osc_run_hook($hook, ...$args)
{
    $GLOBALS['hooks'][] = array($hook, $args);
}
function osc_apply_filter($hook, $content = '', ...$args)
{
    return $content;
}
foreach (array('ok', 'error', 'warning') as $kind) {
    eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") { $GLOBALS["flashes"][] = "' . $kind . ': " . $msg; }');
}

/** The plugin store, stubbed: it records each call the controller makes. */
class Plugins
{
    public static array $installed = array();
    public static array $enabled   = array();
    public static array $calls     = array();

    public static function listAll($sort = true)
    {
        return array('alpha/index.php', 'beta/index.php', 'gamma/index.php', 'needy/index.php');
    }
    public static function isInstalled($p)
    {
        return in_array($p, self::$installed, true);
    }
    public static function isEnabled($p)
    {
        return in_array($p, self::$enabled, true);
    }
    public static function getInfo($p)
    {
        return $p === 'needy/index.php' ? array('plugin_name' => 'Needy', 'requires_plugins' => array('required' => array('absent' => ''))) : array('plugin_name' => $p);
    }
    public static function activate($p)
    {
        self::$calls[] = 'activate ' . $p;
        if (self::isEnabled($p)) {
            return false;
        }
        self::$enabled[] = $p;

        return true;
    }
    public static function deactivate($p)
    {
        self::$calls[] = 'deactivate ' . $p;
        $was           = self::isEnabled($p);
        self::$enabled = array_values(array_diff(self::$enabled, array($p)));

        return $was;
    }
    public static function uninstall($p)
    {
        self::$calls[] = 'uninstall ' . $p;
        self::$installed = array_values(array_diff(self::$installed, array($p)));

        return true;
    }
}

/** The controller base class, stubbed: redirects are recorded. */
class AdminSecBaseModel
{
    protected $action;

    public function doModel()
    {
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    protected function refuseOnDemo($redirectUrl = null)
    {
        return false;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminPlugins.php';

$GLOBALS['pluginsPath'] = sys_get_temp_dir() . '/osc-plugins-dt-' . getmypid() . '/';
foreach (Plugins::listAll() as $file) {
    @mkdir(dirname($GLOBALS['pluginsPath'] . $file), 0777, true);
    file_put_contents($GLOBALS['pluginsPath'] . $file, '<?php');
}

/** One plugin record, as PluginsDataTable::packages() builds it. */
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

$packages = array(
    pkg('zeta', 'Zeta', 'uninstalled'),
    pkg('alpha', 'Alpha', 'disabled', array('update' => true, 'description' => 'Sends <b>mail</b>')),
    pkg('mid', 'Mid', 'active', array('configurable' => true, 'support_uri' => 'https://help.example.test/')),
    pkg('beta', 'Beta', 'active'),
);

/** The table for one set of params: [names in order, data, counts]. */
function run_table(array $packages, array $params): array
{
    $table = new PluginsDataTable($packages);
    $data  = $table->table($params);

    return array(array_map(static fn ($r) => $r['name'], $table->rawRows()), $data, $table->counts());
}

harness_section('Rows and columns');
[$names, $data, $counts] = run_table($packages, array());
pin('columns', array('bulkactions', 'status', 'name', 'version'), array_keys($data['aColumns']));
pin('row cells follow the columns', array('bulkactions', 'status', 'name', 'version'), array_keys($data['aRows'][0]));
pin('default order is by status, then name', array('Beta', 'Mid', 'Alpha', 'Zeta'), $names);
pin('counts per tab', array('all' => 4, 'active' => 2, 'disabled' => 1, 'uninstalled' => 1, 'update' => 1), $counts);
$mid = $data['aRows'][1];
check('the checkbox carries the plugin file', strpos($mid['bulkactions'], 'name="plugin[]" value="mid/index.php"') !== false);
check('the name is bold, then one line of description, then the row actions', (bool) preg_match('#^<strong class="plugin-name" dir="auto">Mid</strong><span class="plugin-desc"[^>]*>[^<]*</span><div class="actions">#', $mid['name']));
foreach (array(
    'Settings' => 'action=admin&amp;plugin=mid%2Findex.php&amp;CSRFName=n&CSRFToken=good',
    'Disable'  => 'action=disable&amp;plugin=mid%2Findex.php&amp;CSRFName=n&CSRFToken=good',
    'Uninstall' => 'action=uninstall&amp;plugin=mid%2Findex.php&amp;CSRFName=n&CSRFToken=good',
    'Support'  => 'https://help.example.test/',
) as $label => $url) {
    check("$label keeps its address", strpos($mid['name'], $url) !== false && strpos($mid['name'], ">$label</a>") !== false);
}
check('Uninstall keeps its confirm dialog', strpos($mid['name'], 'onclick="return uninstall_dialog(') !== false);
check('Details opens the detail dialog', strpos($mid['name'], 'data-market-open-detail') !== false);
$zeta = $data['aRows'][3];
check('a plugin not installed offers Install and Delete files', strpos($zeta['name'], 'action=install&amp;plugin=zeta%2Findex.php') !== false
    && strpos($zeta['name'], 'delete_plugin(') !== false && strpos($zeta['name'], '>Delete files</a>') !== false);
check('the description is escaped', strpos($data['aRows'][2]['name'], '&lt;b&gt;mail&lt;/b&gt;') !== false);
$long = run_table(array(pkg('long', 'Long', 'active', array('description' => str_repeat('word ', 40)))), array())[1]['aRows'][0]['name'] ?? '';
check('a long description is one short line with the full text in its title', strpos($long, '…') !== false && strpos($long, 'title="word word') !== false);
pin('status is the plain state', 'disabled', $data['aRows'][2]['status']);

harness_section('Status tabs');
pin('Active', array('Beta', 'Mid'), run_table($packages, array('status' => 'active'))[0]);
pin('Disabled', array('Alpha'), run_table($packages, array('status' => 'disabled'))[0]);
pin('Not installed', array('Zeta'), run_table($packages, array('status' => 'uninstalled'))[0]);
pin('Update ready', array('Alpha'), run_table($packages, array('status' => 'update'))[0]);
pin('an unknown status shows all', 4, count(run_table($packages, array('status' => 'nope'))[0]));

harness_section('Search');
[$names, , $counts] = run_table($packages, array('sSearch' => 'MAIL'));
pin('matches the description, any case', array('Alpha'), $names);
pin('the tab counts follow the search', array('all' => 1, 'active' => 0, 'disabled' => 1, 'uninstalled' => 0, 'update' => 1), $counts);
pin('matches the name', array('Zeta'), run_table($packages, array('sSearch' => 'zet'))[0]);
pin('search and tab together', array(), run_table($packages, array('sSearch' => 'zet', 'status' => 'active'))[0]);
pin('an array search is ignored', 4, count(run_table($packages, array('sSearch' => array('x')))[0]));

harness_section('Sort');
pin('name ascending', array('Alpha', 'Beta', 'Mid', 'Zeta'), run_table($packages, array('sort' => 'name', 'direction' => 'asc'))[0]);
pin('name descending', array('Zeta', 'Mid', 'Beta', 'Alpha'), run_table($packages, array('sort' => 'name', 'direction' => 'desc'))[0]);
pin('status descending', array('Zeta', 'Alpha', 'Mid', 'Beta'), run_table($packages, array('sort' => 'status', 'direction' => 'desc'))[0]);
$head = run_table($packages, array('sort' => 'name', 'direction' => 'asc', 'status' => 'active'))[1]['aColumns']['name'];
check('the sorted header links to the other direction and keeps the tab', strpos($head, 'status=active&amp;sort=name&amp;direction=desc') !== false);

harness_section('Paging');
[$names, $data] = run_table($packages, array('sort' => 'name', 'iDisplayLength' => 3, 'iPage' => 2));
pin('the second page', array('Zeta'), $names);
pin('totals', array(4, 4, 3), array($data['iTotalRecords'], $data['iTotalDisplayRecords'], $data['iDisplayLength']));

harness_section('Bulk actions');

/** Run one controller action; returns how it ended. */
function drive(string $action, array $query): string
{
    $_GET     = $_REQUEST = $query + array('page' => 'plugins', 'action' => $action);
    $_POST    = array();
    Params::init();
    $GLOBALS['flashes']   = array();
    $GLOBALS['redirects'] = array();
    $GLOBALS['hooks']     = array();
    Plugins::$calls       = array();
    $controller = (new ReflectionClass('CAdminPlugins'))->newInstanceWithoutConstructor();
    $prop       = new ReflectionProperty('AdminSecBaseModel', 'action');
    $prop->setValue($controller, $action);
    try {
        $controller->doModel();
    } catch (CsrfRefused $e) {
        return 'csrf refused';
    }

    return 'finished';
}

$all = array('alpha/index.php', 'beta/index.php', 'gamma/index.php', 'needy/index.php', 'stray/index.php');
$ok  = array('CSRFToken' => 'good');

Plugins::$installed = array('alpha/index.php', 'beta/index.php', 'needy/index.php');
Plugins::$enabled   = array('beta/index.php');
pin('enable without a token is refused', 'csrf refused', drive('bulk_enable', array('plugin' => $all)));
pin('...and changes nothing', array(), Plugins::$calls);
drive('bulk_enable', array('plugin' => $all) + $ok);
pin('enable turns on the installed plugin that is off', array('activate alpha/index.php', 'activate beta/index.php'), Plugins::$calls);
pin('...flashes the count, the refusal and the skipped', array(
    'error: Install and turn on these plugins first: absent.',
    'ok: 1 plugin has been enabled',
    'warning: Skipped, nothing to change: beta, gamma, stray',
), $GLOBALS['flashes']);
pin('...fires its hook with the plugins that changed', array(array('plugin_bulk_enable', array(array('alpha/index.php')))), $GLOBALS['hooks']);
pin('...and returns to the list', array('https://example.test/oc-admin/index.php?page=plugins'), $GLOBALS['redirects']);

Plugins::$enabled = array('beta/index.php');
drive('enable', array('plugin' => 'needy/index.php') + $ok);
pin('the single Enable gives the same refusal', array('error: Install and turn on these plugins first: absent.'), $GLOBALS['flashes']);
drive('enable', array('plugin' => 'alpha/index.php') + $ok);
pin('the single Enable takes the same step', array('activate alpha/index.php'), Plugins::$calls);

Plugins::$enabled = array('alpha/index.php', 'beta/index.php');
pin('disable without a token is refused', 'csrf refused', drive('bulk_disable', array('plugin' => $all)));
drive('bulk_disable', array('plugin' => $all, 'status' => 'active', 'sSearch' => 'a b') + $ok);
pin('disable reaches only installed plugins', array('deactivate alpha/index.php', 'deactivate beta/index.php', 'deactivate needy/index.php'), Plugins::$calls);
pin('...counts two', 'ok: 2 plugins have been disabled', $GLOBALS['flashes'][0]);
pin('...fires its hook', 'plugin_bulk_disable', $GLOBALS['hooks'][0][0]);
pin('...and keeps the tab and search', array('https://example.test/oc-admin/index.php?page=plugins&status=active&sSearch=a+b'), $GLOBALS['redirects']);
drive('disable', array('plugin' => 'gamma/index.php') + $ok);
pin('the single Disable skips a plugin not installed', array(array(), array('error: Plugin is already disabled')), array(Plugins::$calls, $GLOBALS['flashes']));

pin('uninstall without a token is refused', 'csrf refused', drive('bulk_uninstall', array('plugin' => $all)));
pin('...and uninstalls nothing', array(), Plugins::$calls);
drive('bulk_uninstall', array('plugin' => array('alpha/index.php', 'gamma/index.php')) + $ok);
pin('uninstall reaches only the installed plugin', array('uninstall alpha/index.php'), Plugins::$calls);
pin('...and fires its hook', array(array('plugin_bulk_uninstall', array(array('alpha/index.php')))), $GLOBALS['hooks']);
drive('uninstall', array('plugin' => 'gamma/index.php') + $ok);
pin('the single Uninstall refuses a plugin not installed', array(array(), array("error: Plugin couldn't be uninstalled")), array(Plugins::$calls, $GLOBALS['flashes']));

exit(harness_result());
