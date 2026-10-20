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
 * Shared key-value store.
 *
 * Small values core and plugins keep without a table of their own, by group and key,
 * optionally expiring. Values go in as JSON and come back decoded, so arrays, numbers,
 * strings, booleans and null round-trip; objects come back as associative arrays.
 *
 *     osc_kv_set('acme', 'last_sync', array('at' => time()), 3600);
 *     $sync = osc_kv_get('acme', 'last_sync', array());
 *
 *     if (osc_kv_claim('acme', 'nightly_export', 600)) {   // one process at a time
 *         acme_export();
 *         osc_kv_delete('acme', 'nightly_export');
 *     }
 *
 * Name the group after the plugin, and remove it with osc_kv_delete_group() on uninstall.
 * Keys are 1-191 characters, compared byte for byte.
 * Expired keys read as absent and are removed by the daily cron. t_key_value has no user column:
 * a plugin storing something about a person must handle its export and erasure itself.
 */

use mindstellar\model\KeyValue;

if (!function_exists('osc_kv_get')) {
    /**
     * Read a key.
     *
     * @param mixed $default returned when the key is absent or expired
     *
     * @return mixed the stored value, decoded; a stored null comes back as null
     * @throws InvalidArgumentException on a malformed group or key
     * @throws \mindstellar\database\DbException
     */
    function osc_kv_get(string $group, string $key, $default = null)
    {
        $row = (new KeyValue())->get($group, $key);
        if ($row === null || $row['value'] === null) {
            return $row === null ? $default : null;
        }

        return json_decode($row['value'], true);
    }
}

if (!function_exists('osc_kv_set')) {
    /**
     * Write a key, replacing any value, state and expiry it had.
     *
     * @param mixed    $value anything json_encode() takes
     * @param int|null $ttl   seconds it lives; null keeps it until deleted
     *
     * @throws InvalidArgumentException on a malformed group or key, a ttl below 1, or a value that cannot be encoded
     * @throws \mindstellar\database\DbException
     */
    function osc_kv_set(string $group, string $key, $value, ?int $ttl = null): void
    {
        if ($ttl !== null && $ttl < 1) {
            throw new InvalidArgumentException('A key-value ttl is at least one second.');
        }
        try {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('A key-value value must be JSON-encodable: ' . $e->getMessage(), 0, $e);
        }
        $now = time();
        (new KeyValue())->set($group, $key, $json, $ttl === null ? null : $now + $ttl, null, $now);
    }
}

if (!function_exists('osc_kv_delete')) {
    /**
     * Remove a key.
     *
     * @return bool false when there was nothing to remove
     * @throws InvalidArgumentException on a malformed group or key
     * @throws \mindstellar\database\DbException
     */
    function osc_kv_delete(string $group, string $key): bool
    {
        return (new KeyValue())->delete($group, $key) > 0;
    }
}

if (!function_exists('osc_kv_delete_group')) {
    /**
     * Remove every key of a group, e.g. a plugin's own when it is uninstalled.
     *
     * @return int keys removed
     * @throws InvalidArgumentException on a malformed group
     * @throws \mindstellar\database\DbException
     */
    function osc_kv_delete_group(string $group): int
    {
        return (new KeyValue())->deleteGroup($group);
    }
}

if (!function_exists('osc_kv_claim')) {
    /**
     * Take a key for $ttl seconds: true for exactly one caller while the key is absent or
     * expired, false while someone else holds it. Delete the key to release it early.
     *
     * @param string $state stored with the key, e.g. 'locked'
     *
     * @throws InvalidArgumentException on a malformed group, key or state, or a ttl below 1
     * @throws \mindstellar\database\DbException
     */
    function osc_kv_claim(string $group, string $key, int $ttl, string $state = 'locked'): bool
    {
        if ($ttl < 1) {
            throw new InvalidArgumentException('A key-value ttl is at least one second.');
        }
        $now = time();

        return (new KeyValue())->claim($group, $key, $now + $ttl, $state, null, $now);
    }
}
