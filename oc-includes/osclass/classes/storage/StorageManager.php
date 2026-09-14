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

    /**
     * Not instantiable: use instance().
     */
    private function __construct()
    {
    }

    /**
     * The process-wide registry.
     *
     * @return StorageManager
     */
    public static function instance(): StorageManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Adds an adapter under its own id, replacing any adapter already registered there.
     *
     * @param StorageAdapter $adapter
     *
     * @return void
     */
    public function register(StorageAdapter $adapter): void
    {
        $this->adapters[$adapter->getId()] = $adapter;
    }

    /**
     * The adapter registered under $id.
     *
     * @param string $id
     *
     * @return StorageAdapter|null null when nothing is registered under $id
     */
    public function adapter(string $id): ?StorageAdapter
    {
        return $this->adapters[$id] ?? null;
    }

    /**
     * The remote adapter configured as active, if one is set and registered.
     * Returns null when the active preference is unset, still 'local', or
     * points at an adapter no plugin has registered.
     *
     * @return StorageAdapter|null
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
     *
     * @param array<string,mixed> $resource a t_item_resource or t_resource row
     *
     * @return StorageAdapter
     */
    public function forResource(array $resource): StorageAdapter
    {
        $id = $resource['s_storage'] ?? 'local';

        return $this->adapters[$id] ?? $this->adapters['local'];
    }

    /**
     * Registers the bundled local adapter and the core resource-url filters.
     * Safe to call more than once; only runs once.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $this->register(new LocalStorage());

        osc_add_filter('resource_path', [self::class, 'filterResourcePath'], 6);
        osc_add_filter('resource_url', [self::class, 'filterResourceUrlMain']);
        osc_add_filter('resource_thumbnail_url', [self::class, 'filterResourceUrlThumbnail']);
        osc_add_filter('resource_preview_url', [self::class, 'filterResourceUrlPreview']);
        osc_add_filter('resource_original_url', [self::class, 'filterResourceUrlOriginal']);
    }

    /**
     * resource_path filter: swaps the local uploads prefix for the remote adapter's public
     * base, for a resource stored in a public remote bucket.
     *
     * @param string                   $path
     * @param array<string,mixed>|null $resource
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
     * resource_url filter: presigns the main variant for a private remote bucket.
     *
     * @param string                   $url
     * @param array<string,mixed>|null $resource
     *
     * @return string
     */
    public static function filterResourceUrlMain($url, $resource = null)
    {
        return self::presignVariant($resource, '', $url);
    }

    /**
     * resource_thumbnail_url filter: presigns the thumbnail variant for a private remote bucket.
     *
     * @param string                   $url
     * @param array<string,mixed>|null $resource
     *
     * @return string
     */
    public static function filterResourceUrlThumbnail($url, $resource = null)
    {
        return self::presignVariant($resource, '_thumbnail', $url);
    }

    /**
     * resource_preview_url filter: presigns the preview variant for a private remote bucket.
     *
     * @param string                   $url
     * @param array<string,mixed>|null $resource
     *
     * @return string
     */
    public static function filterResourceUrlPreview($url, $resource = null)
    {
        return self::presignVariant($resource, '_preview', $url);
    }

    /**
     * resource_original_url filter: presigns the original variant for a private remote bucket.
     *
     * @param string                   $url
     * @param array<string,mixed>|null $resource
     *
     * @return string
     */
    public static function filterResourceUrlOriginal($url, $resource = null)
    {
        return self::presignVariant($resource, '_original', $url);
    }

    /**
     * Shared implementation behind the four resource-url filters above.
     * Public (non-signed) remote adapters are already handled by
     * filterResourcePath's prefix substitution, so this only has work to do
     * for a private bucket: swap in a time-limited presigned URL for the
     * requested variant, falling back to the original URL whenever the
     * adapter can't produce one.
     *
     * @param array<string,mixed>|null $resource
     * @param string                    $variant     one of ResourceLocator::VARIANTS
     * @param string                    $fallbackUrl
     *
     * @return string
     */
    private static function presignVariant($resource, string $variant, string $fallbackUrl): string
    {
        if (!is_array($resource) || ($resource['s_storage'] ?? 'local') === 'local') {
            return $fallbackUrl;
        }

        $adapter = self::instance()->forResource($resource);
        if (!$adapter->isRemote() || $adapter->isPublic()) {
            return $fallbackUrl;
        }

        if (!method_exists($adapter, 'presignedUrl')) {
            return $fallbackUrl;
        }

        $key = ResourceLocator::storageKey($resource, $variant);
        $presigned = $adapter->presignedUrl($key);

        return $presigned !== '' ? $presigned : $fallbackUrl;
    }
}
