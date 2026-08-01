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
 * Pins Rewrite::matchRoute() — the registered-route matching extracted out of
 * init(). Covers the three param-binding modes (named {…} placeholders, the
 * route_param_N fallback, controller routes) plus the no-match case, so the
 * init() decomposition cannot silently change route dispatch.
 *
 * DB-free: matchRoute touches only preg_match, Params and the private $routes
 * array (set here via reflection), so no Preference/DB access is triggered.
 * Usage:  php tests/rewrite-route-match.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Params.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Rewrite.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

/**
 * Drive matchRoute() with a fixed routes table against a request URI.
 *
 * @param array  $routes      the private $routes value to inject
 * @param string $request_uri
 *
 * @return array{matched: bool, rewrite: Rewrite}
 */
function run_match(array $routes, string $request_uri): array
{
    $_GET  = array();
    $_POST = array();
    Params::init();

    $ref = new ReflectionClass('Rewrite');
    $rw  = $ref->newInstanceWithoutConstructor();

    $prop = $ref->getProperty('routes');
    $prop->setAccessible(true);
    $prop->setValue($rw, $routes);

    $meth = $ref->getMethod('matchRoute');
    $meth->setAccessible(true);
    $matched = $meth->invoke($rw, $request_uri);

    return array('matched' => $matched, 'rewrite' => $rw);
}

/** Read a private Rewrite property. */
function rw_prop(Rewrite $rw, string $name)
{
    $p = (new ReflectionClass('Rewrite'))->getProperty($name);
    $p->setAccessible(true);

    return $p->getValue($rw);
}

harness_section('matchRoute — named {…} placeholders');
$named = array('blog' => array(
    'regexp'   => 'blog/([0-9]+)/([^/]+)',
    'url'      => 'index.php?page=custom&post_id={post_id}&slug={slug}',
    'location' => 'blog', 'section' => 'single', 'title' => 'Blog',
));
$r = run_match($named, 'blog/42/hello-world');
check('matched', $r['matched'] === true);
pin('capture 1 bound to {post_id}', '42',          Params::getParam('post_id'));
pin('capture 2 bound to {slug}',    'hello-world',  Params::getParam('slug'));
pin('route id recorded',            'blog',         Params::getParam('route'));
pin('page set to custom',           'custom',       Params::getParam('page'));
pin('location from route',          'blog',         rw_prop($r['rewrite'], 'location'));
pin('section from route',           'single',       rw_prop($r['rewrite'], 'section'));
pin('title from route',             'Blog',         rw_prop($r['rewrite'], 'title'));

harness_section('matchRoute — route_param_N fallback (no placeholders)');
$fallback = array('foo' => array(
    'regexp' => 'foo/([0-9]+)', 'url' => 'index.php?page=custom',
    'location' => 'custom', 'section' => 'custom', 'title' => 'Custom',
));
$r = run_match($fallback, 'foo/7');
check('matched', $r['matched'] === true);
pin('unnamed capture -> route_param_1', '7', Params::getParam('route_param_1'));

harness_section('matchRoute — controller route');
$controller = array('api' => array(
    'regexp' => 'api/([a-z]+)', 'url' => 'index.php', 'routeController' => true,
));
$r = run_match($controller, 'api/status');
check('matched', $r['matched'] === true);
pin('page set to route',   'route',  Params::getParam('page'));
pin('route id recorded',   'api',    Params::getParam('route'));

harness_section('matchRoute — no match');
$r = run_match($named, 'nothing/here');
check('returns false', $r['matched'] === false);
check('no page set', Params::getParam('page') === '');

exit(harness_result());
