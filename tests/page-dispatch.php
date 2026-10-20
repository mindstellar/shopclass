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
 * Pins which controller answers every ?page= value, on the site and in the admin, as the
 * old switch statements in index.php and oc-admin/index.php did. Unknown pages go to the
 * fallback. Plugins may add pages through the route filters but never replace a core one.
 *
 * DB-free.  Usage: php tests/page-dispatch.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\routing\FrontController;
use mindstellar\routing\PageDispatcher;
use mindstellar\routing\PageRoutes;

$guest    = static fn (): bool => true;
$signedIn = static fn (): bool => false;
$web      = new PageDispatcher(PageRoutes::web(), PageRoutes::WEB_FALLBACK);
$admin    = new PageDispatcher(PageRoutes::admin(), PageRoutes::ADMIN_FALLBACK);

harness_section('site pages');
$webPages = array(
    'cron'     => FrontController::class . '::cron',
    'user'     => 'CWebUser',
    'item'     => 'CWebItem',
    'billing'  => 'CWebBilling',
    'resource' => 'CWebResource',
    'search'   => 'CWebSearch',
    'page'     => 'CWebPage',
    'register' => 'CWebRegister',
    'ajax'     => 'CWebAjax',
    'login'    => 'CWebLogin',
    'language' => 'CWebLanguage',
    'contact'  => 'CWebContact',
    'form'     => 'CWebForm',
    'custom'   => 'CWebCustom',
    'route'    => 'CWebRoute',
    'api'      => 'CWebApi',
    'sitemap'  => FrontController::class . '::sitemap',
);
foreach ($webPages as $page => $target) {
    pin("page=$page", $target, $web->resolve($page, '', $guest));
}
pin('the table has no other page', array_keys($webPages), array_keys(PageRoutes::web()));

harness_section('site actions with their own controller');
foreach (array('change_email_confirm', 'activate_alert', 'contact_post', 'pub_profile') as $action) {
    pin("user&action=$action, signed in", 'CWebUserNonSecure', $web->resolve('user', $action, $signedIn));
    pin("user&action=$action, guest", 'CWebUserNonSecure', $web->resolve('user', $action, $guest));
}
pin('user&action=unsub_alert, guest', 'CWebUserNonSecure', $web->resolve('user', 'unsub_alert', $guest));
pin('user&action=unsub_alert, signed in', 'CWebUser', $web->resolve('user', 'unsub_alert', $signedIn));
pin('user&action=dashboard', 'CWebUser', $web->resolve('user', 'dashboard', $guest));
pin('billing&action=callback', 'CWebBillingNonSecure', $web->resolve('billing', 'callback', $signedIn));
pin('billing&action=wallet', 'CWebBilling', $web->resolve('billing', 'wallet', $signedIn));
pin('item&action=pub_profile is still the item page', 'CWebItem', $web->resolve('item', 'pub_profile', $guest));

$asked = false;
$web->resolve('user', 'dashboard', static function () use (&$asked): bool {
    $asked = true;

    return true;
});
check('the sign-in check runs only for a guest action', $asked === false);

harness_section('unknown site pages go home');
foreach (array('', 'nonsense', 'USER', 'Item', ' item', 'item ', '0', 'main', 'admin', 'toString', '__construct') as $page) {
    pin("page='$page'", 'CWebMain', $web->resolve($page, '', $guest));
}

harness_section('admin pages');
$adminPages = array(
    'items'      => 'CAdminItems',
    'comments'   => 'CAdminItemComments',
    'media'      => 'CAdminMedia',
    'login'      => 'CAdminLogin',
    'categories' => 'CAdminCategories',
    'emails'     => 'CAdminEmails',
    'pages'      => 'CAdminPages',
    'settings'   => 'CAdminSettings',
    'plugins'    => 'CAdminPlugins',
    'languages'  => 'CAdminLanguages',
    'admins'     => 'CAdminAdmins',
    'users'      => 'CAdminUsers',
    'ajax'       => 'CAdminAjax',
    'appearance' => 'CAdminAppearance',
    'tools'      => 'CAdminTools',
    'billing'    => 'CAdminBilling',
    'stats'      => 'CAdminStats',
    'cfields'    => 'CAdminCFields',
    'upgrade'    => 'CAdminUpgrade',
);
foreach ($adminPages as $page => $target) {
    // CWebAjax and CAdminAjax both define IS_AJAX when loaded; a request only ever loads one.
    pin("admin page=$page", $target, @$admin->resolve($page, 'anything', $guest));
}
pin('the admin table has no other page', array_keys($adminPages), array_keys(PageRoutes::admin()));
foreach (array('', 'nonsense', 'Items', 'item', 'user', 'search') as $page) {
    pin("admin page='$page'", 'CAdminMain', $admin->resolve($page, '', $guest));
}

harness_section('every core target can run');
foreach (array(PageRoutes::web(), PageRoutes::admin()) as $routes) {
    foreach ($routes as $page => $route) {
        $targets = is_string($route) ? array($route) : array_merge(
            isset($route['controller']) ? array($route['controller']) : array(),
            array_values($route['actions'] ?? array()),
            array_values($route['guest'] ?? array())
        );
        if (isset($route['handler'])) {
            check("$page handler is callable", is_callable($route['handler']));
        }
        foreach ($targets as $class) {
            check("$page -> $class has doModel()", class_exists($class) && method_exists($class, 'doModel'));
        }
    }
}
check('the site fallback has doModel()', method_exists(PageRoutes::WEB_FALLBACK, 'doModel'));
check('the admin fallback has doModel()', method_exists(PageRoutes::ADMIN_FALLBACK, 'doModel'));

harness_section('pages added by plugins');
$filtered = PageRoutes::web() + array(
    'acme'     => 'CWebItem',
    'acme-fn'  => array('handler' => FrontController::class . '::sitemap'),
    'acme-bad' => 'NoSuchAcmeController',
    'acme-arr' => array('controller' => 'CWebSearch', 'actions' => array('x' => 'NoSuchAcmeController')),
);
$filtered['item'] = 'CWebSearch';
$filtered['api']  = 'CWebMain';
$plugged = new PageDispatcher(PageDispatcher::merge(PageRoutes::web(), $filtered), PageRoutes::WEB_FALLBACK);
pin('a new page runs its controller', 'CWebItem', $plugged->resolve('acme', '', $guest));
pin('a new page may use a handler', FrontController::class . '::sitemap', $plugged->resolve('acme-fn', '', $guest));
pin('a missing controller goes home', 'CWebMain', $plugged->resolve('acme-bad', '', $guest));
pin('a missing action controller goes home', 'CWebMain', $plugged->resolve('acme-arr', 'x', $guest));
pin('its other actions run', 'CWebSearch', $plugged->resolve('acme-arr', 'y', $guest));
pin('a core page cannot be replaced', 'CWebItem', $plugged->resolve('item', '', $guest));
pin('nor the API', 'CWebApi', $plugged->resolve('api', '', $guest));
pin('a filter returning junk adds nothing', PageRoutes::web(), PageDispatcher::merge(PageRoutes::web(), 'junk'));

harness_section('entry points use the table');
$index = (string) file_get_contents(ABS_PATH . 'index.php');
$adm   = (string) file_get_contents(ABS_PATH . 'oc-admin/index.php');
check('index.php runs the front controller', str_contains($index, '\\mindstellar\\routing\\FrontController::run(CLI);'));
check('oc-admin/index.php dispatches through the admin table', str_contains($adm, 'PageDispatcher::admin()->dispatch('));
check('neither keeps a switch on page', !str_contains($index . $adm, "switch (Params::getParam('page'))"));

exit(harness_result());
