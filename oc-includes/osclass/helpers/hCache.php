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

// Clear an item's derived cache on the lifecycle events that change it, so reads
// following a write see fresh data instead of a stale cached copy.
osc_add_hook('edited_item', static function ($item) {
    if (is_array($item) && isset($item['pk_i_id'])) {
        osc_invalidate_item_cache($item['pk_i_id']);
    }
});
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
