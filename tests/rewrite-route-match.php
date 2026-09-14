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
 * Pins the pure dispatch resolvers extracted from init():
 *   - resolveRoute()   registered-route matching + param binding
 *   - resolveSitemap() core sitemap/robots fallback
 *   - applyMatch()     the sink that writes a resolved route into Params
 *
 * resolveRoute/resolveSitemap return a plain description and touch nothing, so the
 * matching contract is asserted on the returned array; one applyMatch() case
 * covers the Params/instance write-through.  Usage:  php tests/rewrite-route-match.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Params.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Rewrite.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$REF = new ReflectionClass('Rewrite');

/** Unconstructed instance with $routes injected (DB-free). */
function rw_with_routes(array $routes)
{
    global $REF;
    $rw   = $REF->newInstanceWithoutConstructor();
    $prop = $REF->getProperty('routes');
    $prop->setAccessible(true);
    $prop->setValue($rw, $routes);

    return $rw;
}

/** Invoke a private method on a Rewrite instance. */
function rw_invoke($rw, string $method, ...$args)
{
    global $REF;
    $m = $REF->getMethod($method);
    $m->setAccessible(true);

    return $m->invoke($rw, ...$args);
}

/** Read a private Rewrite property. */
function rw_prop($rw, string $name)
{
    global $REF;
    $p = $REF->getProperty($name);
    $p->setAccessible(true);

    return $p->getValue($rw);
}

$named = array('blog' => array(
    'regexp'   => 'blog/([0-9]+)/([^/]+)',
    'url'      => 'index.php?page=custom&post_id={post_id}&slug={slug}',
    'location' => 'blog', 'section' => 'single', 'title' => 'Blog',
));

harness_section('resolveRoute — named {…} placeholders (pure)');
$r = rw_invoke(rw_with_routes($named), 'resolveRoute', 'blog/42/hello-world');
check('returns a match', is_array($r));
pin('capture 1 bound to {post_id}', '42', $r['params']['post_id'] ?? null);
pin('capture 2 bound to {slug}', 'hello-world', $r['params']['slug'] ?? null);
pin('route id recorded', 'blog', $r['params']['route'] ?? null);
pin('page set to custom', 'custom', $r['params']['page'] ?? null);
pin('location carried', 'blog', $r['location']);
pin('section carried', 'single', $r['section']);
pin('title carried', 'Blog', $r['title']);

harness_section('resolveRoute — route_param_N fallback + controller + miss');
$fallback = array('foo' => array(
    'regexp' => 'foo/([0-9]+)', 'url' => 'index.php?page=custom',
    'location' => 'custom', 'section' => 'custom', 'title' => 'Custom',
));
$r = rw_invoke(rw_with_routes($fallback), 'resolveRoute', 'foo/7');
pin('unnamed capture -> route_param_1', '7', $r['params']['route_param_1'] ?? null);

$controller = array('api' => array(
    'regexp' => 'api/([a-z]+)', 'url' => 'index.php', 'routeController' => true,
));
$r = rw_invoke(rw_with_routes($controller), 'resolveRoute', 'api/status');
pin('controller route -> page=route', 'route', $r['params']['page'] ?? null);
pin('controller route location null', null, $r['location']);

pin('no match -> null', null, rw_invoke(rw_with_routes($named), 'resolveRoute', 'nothing/here'));

harness_section('applyMatch — writes the resolved route through to Params/instance');
$_GET = array();
$_POST = array();
Params::init();
$rw    = rw_with_routes($named);
$match = rw_invoke($rw, 'resolveRoute', 'blog/42/hello-world');
rw_invoke($rw, 'applyMatch', $match);
pin('Params.post_id set', '42', Params::getParam('post_id'));
pin('Params.page set', 'custom', Params::getParam('page'));
pin('instance location set', 'blog', rw_prop($rw, 'location'));

/* ----------------------------------------------------------------------------
 * A route param must not overwrite what the POST body already said. A form's
 * hidden page/action are its intent; the URL it posts to only says where it was
 * rendered. Writing the route over the body is what stopped the checkout form
 * working the day the buy page gained a permalink -- silently, because the POST
 * still rendered 200 on the page it came from. The same body posted at index.php
 * was always in full control (no rule matches there), so this only makes the two
 * behave alike.
 * ------------------------------------------------------------------------- */
harness_section('applyParams — a POST body outranks the route it was posted to');

$applyWith = static function (array $post, array $params, $method) {
    global $REF;
    $_GET                      = array();
    $_POST                     = $post;
    $_SERVER['REQUEST_METHOD'] = $method;
    Params::init();
    rw_invoke($REF->newInstanceWithoutConstructor(), 'applyParams', $params);
};

/* The real shape: the buy page's route says action=buy, the form posts checkout. */
$applyWith(
    array('page' => 'billing', 'action' => 'checkout', 'packageId' => '2'),
    array('page' => 'billing', 'action' => 'buy'),
    'POST'
);
pin('the posted action survives the route', 'checkout', Params::getParam('action'));
pin('a param both agree on is unchanged', 'billing', Params::getParam('page'));
pin('a param only the body carries is untouched', '2', Params::getParam('packageId'));

/* A key the body does not carry still comes from the route. */
$applyWith(array('action' => 'checkout'), array('page' => 'billing', 'action' => 'buy'), 'POST');
pin('the route still fills in what the body omits', 'billing', Params::getParam('page'));

/* An empty posted value is not an answer -- the route fills it in. */
$applyWith(array('action' => ''), array('page' => 'billing', 'action' => 'buy'), 'POST');
pin('an empty posted value does not shadow the route', 'buy', Params::getParam('action'));

/* GET is untouched: there is no body to defer to, so the route is the only source. */
$applyWith(array(), array('page' => 'billing', 'action' => 'buy'), 'GET');
pin('on GET the route is authoritative', 'buy', Params::getParam('action'));

$_SERVER['REQUEST_METHOD'] = 'GET';

harness_section('resolveSitemap — core fallback (pure)');
$s = rw_invoke($REF->newInstanceWithoutConstructor(), 'resolveSitemap', 'sitemap.xml');
pin('sitemap.xml -> index doc', 'index', $s['sitemap_doc'] ?? null);
pin('sitemap.xml -> page=sitemap', 'sitemap', $s['page'] ?? null);
$s = rw_invoke($REF->newInstanceWithoutConstructor(), 'resolveSitemap', 'sitemap/item-sitemap_s3.xml');
pin('paginated item sitemap page', '3', $s['sitemap_page'] ?? null);
pin('robots.txt -> robots doc', 'robots', rw_invoke($REF->newInstanceWithoutConstructor(), 'resolveSitemap', 'robots.txt')['sitemap_doc'] ?? null);
pin('non-sitemap -> null', null, rw_invoke($REF->newInstanceWithoutConstructor(), 'resolveSitemap', 'search/cars'));

exit(harness_result());
