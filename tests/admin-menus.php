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
 * Appearance > Menus: the save and delete posts check the CSRF token first, refuse a
 * moderator, store the posted JSON through MenuService and flash its errors, create a new
 * empty menu once, and send main back to the automatic menu. The screen starts main from
 * the automatic items and refuses a moderator with 403.
 * DB-backed. Usage: php tests/admin-menus.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_admin_menus');
harness_reset_container();
define('OC_ADMIN', true);

foreach (array(
    'osc_admin_base_url'       => 'return "https://shop.test/oc-admin/index.php";',
    'osc_is_moderator'         => 'return !empty($GLOBALS["moderator"]);',
    'osc_csrf_check'           => 'if (!empty($GLOBALS["badCsrf"])) { throw new RuntimeException("csrf"); } return true;',
    'osc_current_admin_locale' => 'return "en_US";',
    'osc_current_user_locale'  => 'return "en_US";',
    'osc_search_url'           => 'return "/c/" . (int) (func_get_arg(0)["sCategory"] ?? 0);',
    'osc_core_url'             => 'return "/page/" . (int) (func_get_arg(1)["id"] ?? 0);',
    'osc_autop'                => 'return (string) func_get_arg(0);',
    'osc_plugins_path'         => 'return ABS_PATH . "oc-content/plugins/";',
) as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$args) { ' . $body . ' }');
    }
}
foreach (array('_m' => 'return $key;', '_e' => 'echo $key;') as $fn => $body) {
    eval('function ' . $fn . '($key, $domain = "core") { ' . $body . ' }');
}
foreach (array('error', 'ok', 'warning', 'info') as $kind) {
    eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") { $GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
}
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hWidgets.php';
osc_add_filter('page_cache_purge_enabled', static fn () => false);

/** The base controller, stubbed: redirects and the view's variables are recorded. */
class AdminSecBaseModel
{
    protected $action;

    public function __construct()
    {
        $this->action = Params::getParam('action');
    }

    public function doModel()
    {
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
        $GLOBALS['view'][$key] = $value;
    }

    public function doView($view)
    {
    }

    protected function refuseOnDemo($redirectUrl = null)
    {
        return false;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminAppearance.php';

use mindstellar\menu\MenuService;

seed_locale($admin, 'en_US', 'English');
Preference::getInstance()->replace('language', 'en_US');
$motors = seed_category($admin, 'Motors');
$about  = seed_page($admin, 'about', 'About us');

function drive(string $action, array $post = array()): array
{
    $_GET  = array('page' => 'appearance', 'action' => $action);
    $_POST = $post;
    Params::init();
    $GLOBALS['redirects'] = array();
    $GLOBALS['flashes']   = array();
    $GLOBALS['view']      = array();
    http_response_code(200);
    $threw = false;
    try {
        (new CAdminAppearance())->doModel();
    } catch (RuntimeException $e) {
        $threw = $e->getMessage() === 'csrf';
    }

    return array('status' => http_response_code(), 'redirects' => $GLOBALS['redirects'], 'flashes' => $GLOBALS['flashes'], 'view' => $GLOBALS['view'], 'csrf' => $threw);
}

$service = MenuService::getInstance();
$items   = json_encode(array(
    array('type' => 'link', 'url' => '/sale', 'label' => array('en_US' => 'Sale')),
    array('type' => 'category', 'ref' => $motors, 'children' => array(array('type' => 'page', 'ref' => $about))),
));

harness_section('screen');
$r = drive('menus');
pin('main is opened by default', 'main', $r['view']['menuEditor']['id']);
pin('unsaved main starts from the automatic items', array(array('type' => 'category', 'ref' => $motors)), $r['view']['menuEditor']['items']);
pin('the page list offers the page', array(array('id' => $about, 'name' => 'About us')), $r['view']['menuEditor']['pages']);
pin('an unknown menu opens main', 'main', drive('menus', array('menu' => 'nope'))['view']['menuEditor']['id']);

harness_section('csrf');
$GLOBALS['badCsrf'] = true;
check('save checks the token before anything', drive('menu_save_post', array('menu' => 'main', 'items' => $items))['csrf']);
check('...and nothing is stored', $service->load('main') === null);
check('delete checks the token', drive('menu_delete_post', array('menu' => 'main'))['csrf']);
$GLOBALS['badCsrf'] = false;

harness_section('moderator');
$GLOBALS['moderator'] = true;
pin('a moderator gets 403 on the screen', 403, drive('menus')['status']);
$r = drive('menu_save_post', array('menu' => 'main', 'items' => $items));
check('a moderator cannot save', $service->load('main') === null && in_array('error:Only an administrator can edit the menus.', $r['flashes'], true));
$GLOBALS['moderator'] = false;

harness_section('save');
$r = drive('menu_save_post', array('menu' => 'main', 'items' => $items));
pin('saved', array('ok:Menu saved.'), $r['flashes']);
pin('back to the same menu', array('https://shop.test/oc-admin/index.php?page=appearance&action=menus&menu=main'), $r['redirects']);
pin('the nested menu is stored', array('Sale', 'Motors'), array_column(osc_menu('main'), 'label'));
pin('...with its child', array(array('label' => 'About us', 'url' => '/page/' . $about)), osc_menu('main')[1]['children']);

$r = drive('menu_save_post', array('menu' => 'main', 'items' => json_encode(array(array('type' => 'link', 'url' => 'javascript:alert(1)', 'label' => array('en_US' => 'x')), array('type' => 'tag')))));
pin('validation errors are flashed, one each', array('error:A link needs an address that starts with http://, https:// or /.', 'error:A menu item has an unknown type.'), $r['flashes']);
pin('...and the saved menu is kept', array('Sale', 'Motors'), array_column(osc_menu('main'), 'label'));
$r = drive('menu_save_post', array('menu' => 'main', 'items' => 'not json'));
pin('bad JSON is refused', array('error:The menu could not be read.'), $r['flashes']);

harness_section('create and delete');
$r = drive('menu_save_post', array('menu' => 'side', 'label' => 'Side <b>links</b>', 'create' => '1'));
pin('a new menu is created empty', array('label' => 'Side links', 'items' => array()), $service->load('side'));
pin('...and opened', array('https://shop.test/oc-admin/index.php?page=appearance&action=menus&menu=side'), $r['redirects']);
$r = drive('menu_save_post', array('menu' => 'side', 'label' => 'Again', 'create' => '1'));
pin('creating it twice is refused', array('error:A menu with that id already exists.'), $r['flashes']);
pin('...and its name kept', 'Side links', $service->load('side')['label']);
drive('menu_save_post', array('menu' => 'side', 'items' => $items));
pin('saving items keeps the menu name', 'Side links', $service->load('side')['label']);
$r = drive('menu_save_post', array('menu' => 'Bad Id', 'label' => 'x', 'create' => '1'));
check('a bad id is refused', str_contains(implode(' ', $r['flashes']), 'menu id'));

$r = drive('menu_delete_post', array('menu' => 'main'));
pin('main goes back to automatic', array('ok:The automatic menu is back.'), $r['flashes']);
pin('...listing the top categories', array('Motors'), array_column(osc_menu('main'), 'label'));
$r = drive('menu_delete_post', array('menu' => 'side'));
pin('a custom menu is deleted', array('ok:Menu deleted.'), $r['flashes']);
check('...and gone', $service->load('side') === null && osc_menu('side') === array());

exit(harness_result());
