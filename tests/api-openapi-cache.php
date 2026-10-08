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
 * GET /openapi.json is built once and then read from the object cache, under a key that
 * changes with the route table, the site URL and the active plugins.
 *
 * DB-free, no network.  Usage: php tests/api-openapi-cache.php
 */

require_once __DIR__ . '/lib/api-boot.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';

define('OSC_CACHE', 'probe');
define('OSCLASS_VERSION', '7.0.0-test');
$GLOBALS['probe_plugins'] = 'a:0:{}';
function osc_current_user_locale()
{
    return '';
}
function osc_api_url()
{
    return 'https://shop.test/api/v1/';
}
function osc_active_plugins()
{
    return $GLOBALS['probe_plugins'];
}

/**
 * An in-memory driver that counts what is written.
 */
class Object_Cache_probe implements iObject_Cache
{
    public static array $store = [];

    public static int $sets = 0;

    public static function is_supported()
    {
        return true;
    }
    public function add($key, $data, $expire = 0)
    {
        return $this->set($key, $data, $expire);
    }
    public function set($key, $data, $expire = 0)
    {
        self::$sets++;
        self::$store[$key] = [$data, $expire];

        return true;
    }
    public function get($key, &$found = null)
    {
        $found = isset(self::$store[$key]);

        return $found ? self::$store[$key][0] : false;
    }
    public function delete($key)
    {
        unset(self::$store[$key]);

        return true;
    }
    public function flush()
    {
        self::$store = [];

        return true;
    }
    public function stats()
    {
        return '';
    }
    public function _get_cache()
    {
        return self::$store;
    }
    public function __destruct()
    {
    }
}

use mindstellar\api\ApiCall;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\OpenApi;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\Scopes;

$definitions = Schema::definitions();
$validator   = new Validator($definitions);
$make        = static fn (array $extra = []): OpenApi => OpenApi::forSite(new Router($validator, Router::core() + $extra), $definitions, new Scopes());
$show        = static fn (OpenApi $api): Response => $api->show(new ApiCall(new Request('GET', 'v1/openapi.json', [], [], '127.0.0.1'), Credential::anonymous()));

harness_section('the cache');
$first = $show($make());
pin('the first call builds and stores it', [200, 1], [$first->status(), Object_Cache_probe::$sets]);
$second = $show($make());
pin('the next reads it back: nothing more is stored, the same document', [1, $first->body()], [Object_Cache_probe::$sets, $second->body()]);
pin('the stored OpenAPI document lives 300 seconds', 300, array_values(Object_Cache_probe::$store)[0][1]);

harness_section('the key');
$plugin = ['GET ext/acme/offers' => ['handler' => static fn (): Response => Response::ok([]), 'auth' => 'none']];
$r      = $show($make($plugin));
pin('a plugin route makes a new document with its path', [2, true], [Object_Cache_probe::$sets, isset($r->body()['paths']['/ext/acme/offers'])]);
$GLOBALS['probe_plugins'] = 'a:1:{i:0;s:4:"acme";}';
$show($make());
pin('a change in the active plugins makes a new one', 3, Object_Cache_probe::$sets);
$show($make());
pin('the same plugin state again is a cache hit', 3, Object_Cache_probe::$sets);

exit(harness_result());
