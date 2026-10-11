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
final class KeepAllCache implements \mindstellar\cache\CacheDriver
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

    public function add($key, $data, $expire = 0)
    {
        return false;
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
pin('three reads in a group ask for its generation once', 1, $cache->reads['osc_grp_cache_gen'] ?? 0);
$before = CacheGroup::generation('grp');
$cache->set('osc_grp_cache_gen', $before + 5, 0);
pin('a drop by another process is not seen in this request', $before, CacheGroup::generation('grp'));
CacheGroup::forgetGenerations();
pin('it is seen once the generations are forgotten, as the job worker does per job', $before + 5, CacheGroup::generation('grp'));
$reads = $cache->reads['osc_grp_cache_gen'];
pin('a drop in this request is seen at once, with no extra read after it', [$before + 6, $before + 6], [CacheGroup::invalidate('grp'), CacheGroup::generation('grp')]);
pin('...it reads the generation once, to count up from the latest', $reads + 1, $cache->reads['osc_grp_cache_gen']);

exit(harness_result());

/* file end: ./tests/cache-group.php */
