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
 * Class ResourceLocator
 *
 * Derives filesystem paths and storage keys for a resource row and one of its
 * variants ('', '_original', '_preview', '_thumbnail'). Keys come purely from the
 * row shape (s_path, pk_i_id, s_extension), so it serves both t_item_resource and
 * t_resource rows without knowing which owner they belong to.
 *
 * @package mindstellar\storage
 */
class ResourceLocator
{
    public const VARIANTS = ['', '_original', '_preview', '_thumbnail'];

    /**
     * @return string[]
     */
    public static function variants(): array
    {
        return self::VARIANTS;
    }

    /**
     * Absolute local filesystem path for $resource's $variant.
     *
     * @param array  $resource
     * @param string $variant
     *
     * @return string
     */
    public static function localPath(array $resource, string $variant = ''): string
    {
        return osc_base_path() . ($resource['s_path'] ?? '') . ($resource['pk_i_id'] ?? '')
            . $variant . '.' . ($resource['s_extension'] ?? '');
    }

    /**
     * Storage key for $resource's $variant, relative to oc-content/uploads/.
     *
     * @param array  $resource
     * @param string $variant
     *
     * @return string
     */
    public static function storageKey(array $resource, string $variant = ''): string
    {
        return self::keyPrefix($resource) . ($resource['pk_i_id'] ?? '')
            . $variant . '.' . ($resource['s_extension'] ?? '');
    }

    /**
     * Directory portion of the storage key (the part before the filename).
     *
     * @param array $resource
     *
     * @return string
     */
    public static function keyPrefix(array $resource): string
    {
        return preg_replace('#^oc-content/uploads/#', '', $resource['s_path'] ?? '');
    }
}
