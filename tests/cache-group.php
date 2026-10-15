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
 * CacheGroup::remember over a driver that keeps whatever it is given, null included, and
 * reports it found: a null load must not be stored, or every later read is a hit on null.
 *
 * DB-free. Usage:  php tests/cache-group.php
 */

require_once __DIR__ . '/lib/harness.php';

define('ABS_PATH', dirname(__DIR__) . '/');
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\cache\CacheGroup;

/** Keeps every value set, null too, and answers found for any key it holds. */
class KeepAllCache implements \mindstellar\cache\CacheDriver
{
    /** @var array<string,mixed> */
    public array $data = [];

    /** @var array<string,int> */
    public array $ttl = [];
    /** @var array<string,int> reads per key */
    public array $reads = [];

    public static function is_supported()
    {
        return true;
    }

    /** @var int add() calls */
    public int $adds = 0;

    public function add($key, $data, $expire = 0)
    {
        $this->adds++;
        if (array_key_exists($key, $this->data)) {
            return false;
        }

        return $this->set($key, $data, $expire);
    }

    public function set($key, $data, $expire = 0)
    {
        $this->data[$key] = $data;
        $this->ttl[$key]  = $expire;

        return true;
    }

    public function get($key, &$found = null)
    {
        $this->reads[$key] = ($this->reads[$key] ?? 0) + 1;
        $found = array_key_exists($key, $this->data);

        return $found ? $this->data[$key] : false;
    }

    public function delete($key)
    {
        unset($this->data[$key]);

        return true;
    }

    public function flush()
    {
        $this->data = [];

        return true;
    }

    public function stats()
    {
    }

    public function _get_cache()
    {
        return 'memcached';
    }

    public function __destruct()
    {
    }
}

$cache = new KeepAllCache();
$factory = new ReflectionProperty('mindstellar\\cache\\CacheManager', 'instance');
$factory->setAccessible(true);
$factory->setValue(null, $cache);

$loads = 0;
$load  = static function (mixed $answer) use (&$loads): Closure {
    return static function () use ($answer, &$loads) {
        $loads++;

        return $answer;
    };
};

harness_section('a null load is not cached');
pin('a failed load answers null', null, CacheGroup::remember('grp', 'k', $load(null)));
pin('...and nothing is stored', [], array_values(array_filter($cache->data, static fn ($v): bool => $v === null)));
pin('the next read loads again and gets the value', 'tree', CacheGroup::remember('grp', 'k', $load('tree')));
pin('...which is then a hit', ['tree', 2], [CacheGroup::remember('grp', 'k', $load('other')), $loads]);

harness_section('ttl and generation');
CacheGroup::remember('grp', 'short', $load('v'), 7);
pin('a given ttl is passed to the driver', [7], array_values(array_intersect_key($cache->ttl, array_filter($cache->data, static fn ($v): bool => $v === 'v'))));
CacheGroup::invalidate('grp');
pin('invalidating the group makes the next read load again', 'fresh', CacheGroup::remember('grp', 'k', $load('fresh')));

harness_section('a request reads each generation once');
CacheGroup::forgetGenerations();
$cache->reads = [];
CacheGroup::remember('grp', 'k', $load('x'));
CacheGroup::remember('grp', 'k', $load('x'));
CacheGroup::remember('grp', 'other', $load('y'));
pin('three reads in a group ask for its generation once', 1, $cache->reads['shared:osc_grp_cache_gen'] ?? 0);
$before = CacheGroup::generation('grp');
$cache->set('shared:osc_grp_cache_gen', $before + 5, 0);
pin('a drop by another process is not seen in this request', $before, CacheGroup::generation('grp'));
CacheGroup::forgetGenerations();
pin('it is seen once the generations are forgotten, as the job worker does per job', $before + 5, CacheGroup::generation('grp'));
$reads = $cache->reads['shared:osc_grp_cache_gen'];
pin('a drop in this request is seen at once, with no extra read after it', [$before + 6, $before + 6], [CacheGroup::invalidate('grp'), CacheGroup::generation('grp')]);
pin('...it reads the generation once, to count up from the latest', $reads + 1, $cache->reads['shared:osc_grp_cache_gen']);

/** Reads many keys at once and counts up atomically, as the Redis and memcached drivers do. */
final class ManyCache extends KeepAllCache
{
    /** @var list<list<string>> keys of each getMany() call */
    public array $many = [];

    public function getMany(array $keys): array
    {
        $this->many[] = $keys;

        return array_intersect_key($this->data, array_flip($keys));
    }

    public function increment($key, $by = 1, $initial = 0, $expire = 0)
    {
        if ($this->add($key, $initial, $expire)) {
            return $initial;
        }
        $this->data[$key] += $by;

        return $this->data[$key];
    }
}

/** Another request creates the generation between this one's read and its add. */
final class RaceCache extends KeepAllCache
{
    public function add($key, $data, $expire = 0)
    {
        $this->set($key, 42, 0);

        return parent::add($key, $data, $expire);
    }
}

$swap = static function (\mindstellar\cache\CacheDriver $driver) use ($factory): void {
    $factory->setValue(null, $driver);
    CacheGroup::forgetGenerations();
};

harness_section('a missing generation is stored, so the next request finds it');
$swap($cache = new KeepAllCache());
$first = CacheGroup::generation('grp');
pin('it is stored with a long expiry, not the 60 second default', [$first, 2592000], [$cache->data['shared:osc_grp_cache_gen'] ?? null, $cache->ttl['shared:osc_grp_cache_gen'] ?? null]);
pin('...and starts from the clock, not 0', true, $first >= (int)floor(microtime(true) * 1000) - 60000);
CacheGroup::forgetGenerations();
$cache->reads = [];
pin('the next request reads it as a hit, with no write', [$first, 1, 1], [CacheGroup::generation('grp'), $cache->reads['shared:osc_grp_cache_gen'], $cache->adds]);

harness_section('a generation that expired or was evicted never returns to an old number');
$swap($cache = new KeepAllCache());
$cache->set('shared:osc_grp_cache_gen', 0, 0);
CacheGroup::remember('grp', 'k', $load('before'));
CacheGroup::invalidate('grp');
CacheGroup::remember('grp', 'k', $load('after'));
$cache->delete('shared:osc_grp_cache_gen');
CacheGroup::forgetGenerations();
pin('the entries stored before it went are not served', 'reloaded', CacheGroup::remember('grp', 'k', $load('reloaded')));
CacheGroup::invalidate('grp');
pin('...nor after the next drop', 'again', CacheGroup::remember('grp', 'k', $load('again')));

harness_section('a drop after the store was flushed mid-request still moves forward');
$swap($cache = new KeepAllCache());
$ahead = (int) floor(microtime(true) * 1000) + 1000000;
$cache->set('shared:osc_grp_cache_gen', $ahead, 0);
CacheGroup::forgetGenerations();
CacheGroup::generation('grp');
$cache->delete('shared:osc_grp_cache_gen');
pin('the new generation is above the one this request was using', true, CacheGroup::invalidate('grp') > $ahead);

harness_section('two requests creating the generation at once agree on it');
$swap($cache = new RaceCache());
pin('the one that loses the add takes the winner\'s number', 42, CacheGroup::generation('grp'));
$swap($cache = new KeepAllCache());
$cache->set('shared:osc_grp_cache_gen', 'junk', 0);
$fixed = CacheGroup::generation('grp');
pin('a stored value that is not a number is replaced', $fixed, $cache->data['shared:osc_grp_cache_gen']);

harness_section('a driver that reads many keys at once');
$swap($cache = new ManyCache());
$cache->set('shared:osc_category_cache_gen', 3, 0);
$cache->set('shared:osc_page_cache_gen', 4, 0);
CacheGroup::generation('preference');
pin('the first generation read fetches the common groups in one call', [1, 7], [count($cache->many), count($cache->many[0])]);
pin('...and later ones need no read', [3, 4, [], 1], [CacheGroup::generation('category'), CacheGroup::generation('page'), $cache->reads, count($cache->many)]);
pin('a group it did not find is created without reading it again', [true, []], [isset($cache->data['shared:osc_preference_cache_gen']), $cache->reads]);
CacheGroup::generation('field_group');
pin('another group reads itself and the common ones still missing, nothing more', ['shared:osc_field_group_cache_gen'], array_values(array_diff(end($cache->many), ['shared:osc_locale_cache_gen', 'shared:osc_currency_cache_gen', 'shared:osc_widget_cache_gen', 'shared:osc_search_cache_gen'])));
pin('a drop counts up atomically', 5, CacheGroup::invalidate('page'));
$cache->delete('shared:osc_page_cache_gen');
pin('...and a drop of a missing generation starts from the clock', true, CacheGroup::invalidate('page') > 4);

harness_section('a web worker keeps its own copy of a shared value');
$GLOBALS['__apcu'] = [];
if (extension_loaded('apcu')) {
    // The stand-ins below cannot replace the real extension's functions.
    exit(harness_result());
}
if (!function_exists('apcu_fetch')) {
    function apcu_fetch($key, &$found = null)
    {
        $found = array_key_exists($key, $GLOBALS['__apcu']);

        return $found ? $GLOBALS['__apcu'][$key][0] : false;
    }

    function apcu_store($key, $value, $ttl = 0)
    {
        $GLOBALS['__apcu'][$key] = [$value, $ttl];

        return true;
    }
}
$localFlag = new ReflectionProperty(CacheGroup::class, 'local');
$localFlag->setValue(null, true);
$swap($cache = new KeepAllCache());
$loads = 0;
$loader = static function () use (&$loads) {
    $loads++;

    return ['rows' => [1, 2, 3]];
};
CacheGroup::remember('category', 'tree', $loader);
$cache->reads = [];
pin('the second read comes from the copy, not the shared cache', [['rows' => [1, 2, 3]], [], 1], [CacheGroup::remember('category', 'tree', $loader), $cache->reads, $loads]);
pin('the copy lasts at most 15 seconds', [15], array_values(array_map(static fn ($e) => $e[1], $GLOBALS['__apcu'])));
$GLOBALS['__apcu'] = [];
CacheGroup::remember('category', 'short', $loader, 5);
CacheGroup::remember('category', 'forever', $loader, 0);
pin('...no longer than the value itself, and never for ever', [5, 15], array_values(array_map(static fn ($e) => $e[1], $GLOBALS['__apcu'])));
CacheGroup::invalidate('category');
$before = $loads;
CacheGroup::remember('category', 'tree', $loader);
pin('a dropped group is loaded again, not read from the copy', 1, $loads - $before);
$GLOBALS['__apcu'] = [];
$swap($cache = new KeepAllCache());
CacheGroup::remember('category', 'shared', static fn () => 'from the shared cache');
$GLOBALS['__apcu'] = [];
pin('a value only the shared cache holds is copied on read', ['from the shared cache', 1], [CacheGroup::remember('category', 'shared', $loader), count($GLOBALS['__apcu'])]);
pin('a null load is not copied either', [null, 1], [CacheGroup::remember('category', 'none', static fn () => null), count($GLOBALS['__apcu'])]);
$localFlag->setValue(null, false);
$GLOBALS['__apcu'] = [];
CacheGroup::remember('category', 'off', $loader);
pin('with copies off nothing is copied', [], $GLOBALS['__apcu']);
$GLOBALS['__apcu'] = array_fill_keys(array_keys($cache->data), ['stale copy', 15]);
pin('...and no copy is read', true, CacheGroup::remember('category', 'off', $loader) !== 'stale copy');
$localFlag->setValue(null, null);
$swap($cache = new KeepAllCache());
pin('copies stay off unless the site turns them on', false, (new ReflectionMethod(CacheGroup::class, 'localCopies'))->invoke(null, $cache));
$localFlag->setValue(null, null);

harness_section('a change inside a transaction is dropped again at commit');
$swap($cache = new KeepAllCache());
$depth   = new ReflectionProperty(\mindstellar\database\Db::class, 'depth');
$pending = new ReflectionProperty(\mindstellar\database\Db::class, 'afterCommit');
$depth->setAccessible(true);
$pending->setAccessible(true);
$start = CacheGroup::generation('grp');
$depth->setValue(null, 1);
CacheGroup::changed('grp');
pin('the group is dropped at once', $start + 1, CacheGroup::generation('grp'));
$queued = $pending->getValue()[1] ?? [];
$depth->setValue(null, 0);
$pending->setValue(null, []);
foreach ($queued as $fn) {
    $fn();
}
pin('...and again once the write commits', $start + 2, CacheGroup::generation('grp'));
CacheGroup::changed('grp');
pin('outside a transaction it is dropped once', $start + 3, CacheGroup::generation('grp'));

harness_section('osc_cache_remember');
$GLOBALS['__locale'] = 'en_US';
function osc_add_hook()
{
}
function osc_current_user_locale()
{
    return $GLOBALS['__locale'];
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
$swap($cache = new KeepAllCache());
$loads = 0;
osc_cache_remember('category', 'name:7', $load('Cars'), 600);
pin('a stored value is a hit', ['Cars', 1], [osc_cache_remember('category', 'name:7', $load('other')), $loads]);
pin('...kept for the given seconds', [600], array_values(array_filter($cache->ttl, static fn ($t) => $t === 600)));
$GLOBALS['__locale'] = 'es_ES';
pin('each locale has its own copy', 'Coches', osc_cache_remember('category', 'name:7', $load('Coches')));
CacheGroup::invalidate('category');
pin('dropping the group loads again', 'Autos', osc_cache_remember('category', 'name:7', $load('Autos')));

harness_section('a generation is one per database, not per address');
$driver = (new ReflectionClass(\mindstellar\cache\RedisCache::class))->newInstanceWithoutConstructor();
(new ReflectionMethod(\mindstellar\base\Cache::class, '__construct'))->invoke($driver);
$storeKey = new ReflectionMethod($driver, '_key');
if (PHP_VERSION_ID < 80100) {
    $storeKey->setAccessible(true);
}
$web = [$storeKey->invoke($driver, 'shared:osc_preference_cache_gen'), $storeKey->invoke($driver, 'osc_preference:map')];
$driver->site_prefix = 'osc_' . substr(md5('http://localhost:8000/'), 0, 12) . '_';
$cli = [$storeKey->invoke($driver, 'shared:osc_preference_cache_gen'), $storeKey->invoke($driver, 'osc_preference:map')];
pin('the command line under another address bumps the generation the web reads', $web[0], $cli[0]);
check('...while values stay apart per address, so a spoofed Host never fills another\'s', $web[1] !== $cli[1]);

exit(harness_result());

/* file end: ./tests/cache-group.php */
