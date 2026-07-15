<?php
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
