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
 * `invalidate_item_cache` — the one hook a page cache outside PHP has to hang off.
 *
 * osc_invalidate_item_cache() is where every event that makes an item's rendered page
 * wrong already converges: an edit, an image added or removed, the listing deleted, and
 * the storage worker once an offload has rewritten the image URLs. That last one has no
 * hook of its own — StorageWorker calls this function directly — so before this hook
 * existed, a proxy or CDN had no way to learn that a cached listing page was still
 * pointing at local files the offload had moved.
 *
 * Pinned here: the hook fires, carries the id as an int, fires on both the locale branch
 * and the early-boot branch, and stays silent for an id that was never valid.
 *
 * The helper only reaches the object cache and the plugin layer, both stubbed, so this
 * runs with no database and no bootstrap.  Usage:  php tests/item-cache-invalidation.php
 */

$GLOBALS['fired']  = array();
$GLOBALS['locales'] = array();

// Defined before hCache.php loads, so there is no redeclaration -- everything the helper
// reaches outside itself, and nothing hCache declares (osc_cache_delete is its own). The
// file registers lifecycle hooks as it loads; osc_add_hook swallows those.
function osc_run_hook($hook, ...$args)
{
    $GLOBALS['fired'][] = array('hook' => $hook, 'args' => $args);
}
function osc_add_hook($hook, $callback = null, $priority = 5)
{
}
function osc_apply_filter($hook, $content, ...$args)
{
    return $content;
}
function osc_base_url($withIndex = false)
{
    return 'http://example.test/';
}
function osc_current_user_locale()
{
    return 'en_US';
}
function osc_get_locales()
{
    return $GLOBALS['locales'];
}

/** Stands in for the object cache; records what it was asked to delete. */
class Object_Cache_Factory
{
    public static $deleted = array();

    public static function newInstance()
    {
        return new self();
    }

    public function delete($key)
    {
        self::$deleted[] = $key;

        return true;
    }
}

require_once __DIR__ . '/../oc-includes/osclass/helpers/hCache.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

/** Invalidate and return only the invalidate_item_cache firings. */
function invalidations($itemId, array $locales = array())
{
    $GLOBALS['fired']   = array();
    $GLOBALS['locales'] = $locales;
    Object_Cache_Factory::$deleted = array();
    osc_invalidate_item_cache($itemId);

    return array_values(array_filter($GLOBALS['fired'], static function ($f) {
        return $f['hook'] === 'invalidate_item_cache';
    }));
}

harness_section('invalidate_item_cache — the hook fires');

$two = array(array('pk_c_code' => 'en_US'), array('pk_c_code' => 'fr_FR'));

$f = invalidations(42, $two);
pin('the hook fires once', 1, count($f));
pin('...carrying the item id', 42, $f[0]['args'][0] ?? null);
check('...as an int, not the string it may have arrived as', ($f[0]['args'][0] ?? null) === 42);

$f = invalidations('42', $two);
check('a numeric string id is normalised before the hook sees it', ($f[0]['args'][0] ?? null) === 42);

harness_section('invalidate_item_cache — every branch reaches it');

check(
    'the locale branch still clears its per-locale keys',
    count(Object_Cache_Factory::$deleted) === 2
);
$f = invalidations(7, $two);
pin('...and fires the hook after them', 1, count($f));

/* No locale list yet (early boot, install): the helper falls back to the current-locale
   key and returns. That return used to be before the hook. */
$f = invalidations(7, array());
pin('the early-boot branch fires it too', 1, count($f));
pin('...with the same id', 7, $f[0]['args'][0] ?? null);

harness_section('invalidate_item_cache — nothing to invalidate, nothing announced');

foreach (array(0, -1, 'abc', null) as $bad) {
    $f = invalidations($bad, $two);
    check('an id of ' . var_export($bad, true) . ' announces nothing', $f === array());
}

exit(harness_result());
