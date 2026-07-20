<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\storage;

/**
 * Class StorageManager
 *
 * Registry of storage adapters, keyed by adapter id. The bundled 'local'
 * adapter is always present once boot() has run; plugins add others by
 * hooking 'register_storage_adapters' and calling osc_register_storage_adapter().
 *
 * @package mindstellar\storage
 */
class StorageManager
{
    private static ?StorageManager $instance = null;

    /** @var StorageAdapter[] */
    private array $adapters = [];

    private bool $booted = false;

    private function __construct()
    {
    }

    public static function instance(): StorageManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function register(StorageAdapter $adapter): void
    {
        $this->adapters[$adapter->getId()] = $adapter;
    }

    public function adapter(string $id): ?StorageAdapter
    {
        return $this->adapters[$id] ?? null;
    }

    /**
     * The remote adapter configured as active, if one is set and registered.
     * Returns null when the active preference is unset, still 'local', or
     * points at an adapter no plugin has registered.
     */
    public function remote(): ?StorageAdapter
    {
        $active = osc_get_preference('storage_active', 'osclass');
        if (!$active || $active === 'local') {
            return null;
        }

        return $this->adapters[$active] ?? null;
    }

    /**
     * The adapter that owns $resource, based on its s_storage column.
     * Falls back to the local adapter when the stored id isn't registered
     * (e.g. the plugin that provided it was deactivated).
     */
    public function forResource(array $resource): StorageAdapter
    {
        $id = $resource['s_storage'] ?? 'local';

        return $this->adapters[$id] ?? $this->adapters['local'];
    }

    /**
     * Registers the bundled local adapter and the core resource-url filters.
     * Safe to call more than once; only runs once.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $this->register(new LocalStorage());

        osc_add_filter('resource_path', [self::class, 'filterResourcePath'], 6);
        osc_add_filter('resource_url', [self::class, 'filterResourceUrl']);
        osc_add_filter('resource_thumbnail_url', [self::class, 'filterResourceUrl']);
        osc_add_filter('resource_preview_url', [self::class, 'filterResourceUrl']);
        osc_add_filter('resource_original_url', [self::class, 'filterResourceUrl']);
    }

    /**
     * @param string     $path
     * @param array|null $resource
     *
     * @return string
     */
    public static function filterResourcePath($path, $resource = null)
    {
        if (!is_array($resource) || ($resource['s_storage'] ?? 'local') === 'local') {
            return $path;
        }

        $adapter = self::instance()->forResource($resource);
        if (!$adapter->isRemote() || !$adapter->isPublic()) {
            return $path;
        }

        return $adapter->url(ResourceLocator::keyPrefix($resource));
    }

    /**
     * Phase-1 no-op: the remote-URL hooks exist so adapters can implement
     * presigned/expiring URLs in a later phase; nothing to do while every
     * install is 'local'.
     *
     * @param string     $url
     * @param array|null $resource
     *
     * @return string
     */
    public static function filterResourceUrl($url, $resource = null)
    {
        return $url;
    }
}
