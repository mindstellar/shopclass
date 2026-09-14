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
 * Pins ?page=route dispatch (CWebRoute). The route name comes from the request, so only a
 * route registered with osc_add_route_hook() may run its hook; any other name, cron_hourly
 * included, is a 404. The hook runs after `init`, so init-registered gateways exist.
 *
 * DB-free: runs index.php's page=route case with the real Plugins, Params, Rewrite and
 * CWebRoute; BaseModel is stood in by a class that fires `init` as the real constructor
 * does.  Usage: php tests/route-hook-dispatch.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('UPLOADS_PATH', sys_get_temp_dir() . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

function osc_plugins_path()
{
    return ABS_PATH . 'oc-content/plugins/';
}

function osc_uploads_path()
{
    return UPLOADS_PATH;
}

/** Thrown by the stand-in do404() in place of rendering the theme and exiting. */
class RouteTestNotFound extends Exception
{
}

/** Stand-in for BaseModel: the real constructor needs a database and a theme. */
abstract class BaseModel
{
    protected $ajax;

    public function __construct()
    {
        $this->ajax = false;
        osc_run_hook('init');
    }

    public function do404()
    {
        throw new RouteTestNotFound();
    }

    abstract protected function doModel();

    abstract protected function doView($file);
}

// The page=route case body, lifted from index.php so the test runs what ships.
$index = (string) file_get_contents(ABS_PATH . 'index.php');
preg_match("/case \\('route'\\):(.*?)\\bbreak;/s", $index, $case);
check('index.php has a page=route case', isset($case[1]));
$routeCase = $case[1] ?? '';

// Routes as a plugin registers them: one hook route, one file route.
$rw = (new ReflectionClass('Rewrite'))->newInstanceWithoutConstructor();
$rp = new ReflectionProperty('Rewrite', 'routes');
$rp->setAccessible(true);
$rp->setValue($rw, array());
$ip = new ReflectionProperty('Rewrite', 'instance');
$ip->setAccessible(true);
$ip->setValue(null, $rw);
$rw->addRouteHook('demo-pay', 'demo/pay', 'demo/pay');
$rw->addRoute('demo-page', 'demo/page', 'demo/page', 'demo/page.php');

$fired = array();
$gatewayReady = false;
osc_add_hook('init', static function () use (&$gatewayReady) {
    $gatewayReady = true;
});
foreach (array('cron_hourly', 'demo-pay', 'demo-page') as $hook) {
    osc_add_hook($hook, static function () use (&$fired, &$gatewayReady, $hook) {
        $fired[] = array($hook, $gatewayReady);
    });
}

/**
 * Dispatch ?page=route&route=$name. Returns 'ran', '404' or the error class.
 *
 * @param string|array $name
 */
function dispatch_route($name): string
{
    global $fired, $gatewayReady, $routeCase;
    $fired        = array();
    $gatewayReady = false;
    Params::setParam('page', 'route');
    Params::setParam('route', $name);
    try {
        eval($routeCase);
    } catch (RouteTestNotFound $e) {
        return '404';
    } catch (Throwable $e) {
        return get_class($e);
    }

    return 'ran';
}

/** The route hooks that fired, each with whether init had run first. */
function fired_route_hooks(): array
{
    return $GLOBALS['fired'];
}

harness_section('a registered hook route runs, after init');
pin('demo-pay dispatches', 'ran', dispatch_route('demo-pay'));
pin('its hook fired once, with init already run', array(array('demo-pay', true)), fired_route_hooks());

harness_section('anything else is a 404 and fires nothing');
foreach (array('cron_hourly', 'demo-page', 'no-such-route', '') as $name) {
    pin("route=$name is 404", '404', dispatch_route($name));
    pin("route=$name fired no route hook", array(), fired_route_hooks());
}
pin('route[] array is 404', '404', dispatch_route(array('cron_hourly')));
pin('route[] array fired no route hook', array(), fired_route_hooks());

harness_section('wiring');
$base = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/base/abstract/BaseModel.php');
check('the real BaseModel constructor fires init', strpos($base, "osc_run_hook('init');") !== false);

exit(harness_result());

/* file end: ./tests/route-hook-dispatch.php */
