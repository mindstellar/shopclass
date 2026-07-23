<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * @param     $key
 * @param     $data
 * @param int $expire
 *
 * @return bool
 */
function osc_cache_add($key, $data, $expire = 0)
{
    $key .= osc_current_user_locale();

    return Object_Cache_Factory::newInstance()->add($key, $data, $expire);
}


/**
 * @return mixed
 */
function osc_cache_close()
{
    return Object_Cache_Factory::newInstance()->close();
}


/**
 * @param $key
 *
 * @return bool
 */
function osc_cache_delete($key)
{
    $key .= osc_current_user_locale();

    return Object_Cache_Factory::newInstance()->delete($key);
}


/**
 * @return bool
 */
function osc_cache_flush()
{
    return Object_Cache_Factory::newInstance()->flush();
}


/**
 * Normalised statistics for the active cache driver, or null when it has none.
 *
 * Probed rather than declared on iObject_Cache, because third-party drivers
 * implement that interface and a new required method would fatal them.
 *
 * @return array|null
 */
function osc_cache_stats()
{
    $cache = Object_Cache_Factory::newInstance();
    if (!method_exists($cache, 'statsData')) {
        return null;
    }

    return $cache->statsData();
}


/**
 * Initialize Cache factory instance using singleton
 */
function osc_cache_init()
{
    Object_Cache_Factory::newInstance();
}


/**
 * @param $key
 * @param $found
 *
 * @return bool|mixed
 */
function osc_cache_get($key, &$found)
{
    $key .= osc_current_user_locale();

    return Object_Cache_Factory::newInstance()->get($key, $found);
}


/**
 * @param     $key
 * @param     $data
 * @param int $expire
 *
 * @return bool
 */
function osc_cache_set($key, $data, $expire = 0)
{
    $key .= osc_current_user_locale();

    return Object_Cache_Factory::newInstance()->set($key, $data, $expire);
}


/**
 * Invalidate cached data that is a pure function of an item id (currently the
 * item's resource/photo list). Without this the object cache is TTL-only, so a
 * persistent backend (memcached/apcu) serves a stale copy of an item the user
 * just edited for up to OSC_CACHE_TTL seconds.
 *
 * The osc_cache_* helpers suffix every key with the current user locale, so the
 * same item is cached once per locale that has requested it; clear each enabled
 * locale's entry, not only the current request's.
 *
 * @param int $itemId
 *
 * @return void
 */
function osc_invalidate_item_cache($itemId)
{
    $itemId = (int)$itemId;
    if ($itemId <= 0) {
        return;
    }

    $baseKey = md5(osc_base_url() . 'ItemResource:getAllResourcesFromItem:' . $itemId);
    $cache   = Object_Cache_Factory::newInstance();

    $locales = function_exists('osc_get_locales') ? osc_get_locales() : array();
    if (empty($locales)) {
        // No locale list available yet (early boot / install): clear the current-locale key.
        osc_cache_delete($baseKey);

        return;
    }

    foreach ($locales as $locale) {
        $cache->delete($baseKey . $locale['pk_c_code']);
    }
}

/**
 * Current generation number for the search/latest-items cache. Callers fold it
 * into their cache key so a bump (see osc_invalidate_search_cache) makes every
 * previously stored entry unreachable at once — the practical way to invalidate a
 * key space whose members are not enumerable (one entry per filter combination).
 *
 * Read through the raw cache factory rather than osc_cache_get so the generation
 * is global rather than per-locale. A backend that does not persist (the default
 * dummy cache) simply keeps returning 0, which leaves caching behaviour exactly
 * as it was before this helper existed.
 *
 * @return int
 */
function osc_cache_search_generation()
{
    $found = null;
    $gen   = Object_Cache_Factory::newInstance()->get('osc_search_cache_gen', $found);

    return is_numeric($gen) ? (int)$gen : 0;
}


/**
 * Bump the search-cache generation, invalidating every cached search/latest-items
 * result. Called on the item lifecycle events that change what a search returns
 * (post, edit, disable, enable, spam on/off) so a quarantined or edited listing
 * leaves the search cache immediately instead of lingering for the cache TTL.
 *
 * @return int the new generation
 */
function osc_invalidate_search_cache()
{
    $cache = Object_Cache_Factory::newInstance();
    $found = null;
    $gen   = $cache->get('osc_search_cache_gen', $found);
    $gen   = (is_numeric($gen) ? (int)$gen : 0) + 1;
    $cache->set('osc_search_cache_gen', $gen, 0);

    return $gen;
}


// Clear an item's derived cache on the lifecycle events that change it, so reads
// following a write see fresh data instead of a stale cached copy.
osc_add_hook('edited_item', static function ($item) {
    if (is_array($item) && isset($item['pk_i_id'])) {
        osc_invalidate_item_cache($item['pk_i_id']);
    }
});

// Bump the search cache on every lifecycle event that changes the live set, so a
// quarantined, disabled or edited listing stops being served from a stale search
// result. The generation bump ignores its hook arguments by design.
osc_add_hook('posted_item', 'osc_invalidate_search_cache');
osc_add_hook('edited_item', 'osc_invalidate_search_cache');
osc_add_hook('disable_item', 'osc_invalidate_search_cache');
osc_add_hook('enable_item', 'osc_invalidate_search_cache');
osc_add_hook('item_spam_on', 'osc_invalidate_search_cache');
osc_add_hook('item_spam_off', 'osc_invalidate_search_cache');
osc_add_hook('after_delete_item', 'osc_invalidate_search_cache');
osc_add_hook('uploaded_file', static function ($resource) {
    if (is_array($resource) && isset($resource['fk_i_item_id'])) {
        osc_invalidate_item_cache($resource['fk_i_item_id']);
    }
});
osc_add_hook('delete_resource', static function ($resource) {
    if (is_array($resource) && isset($resource['fk_i_item_id'])) {
        osc_invalidate_item_cache($resource['fk_i_item_id']);
    }
});
osc_add_hook('after_delete_item', static function ($itemId) {
    osc_invalidate_item_cache($itemId);
});
