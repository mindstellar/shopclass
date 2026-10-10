<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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
 * row shape (s_path, pk_i_id or s_base_name, s_extension), so it serves every
 * t_resource row without knowing which owner it belongs to.
 *
 * @package mindstellar\storage
 */
class ResourceLocator
{
    public const VARIANTS = ['', '_original', '_preview', '_thumbnail'];

    /**
     * The variant suffixes a resource can have on disk.
     *
     * @return string[]
     */
    public static function variants(): array
    {
        return self::VARIANTS;
    }

    /**
     * Absolute local filesystem path for $resource's $variant.
     *
     * @param array<string,mixed> $resource a t_resource row, or a listing photo row
     * @param string               $variant  one of self::VARIANTS
     *
     * @return string
     */
    public static function localPath(array $resource, string $variant = ''): string
    {
        return osc_base_path() . ($resource['s_path'] ?? '') . self::baseName($resource)
            . $variant . '.' . ($resource['s_extension'] ?? '');
    }

    /**
     * Storage key for $resource's $variant, relative to oc-content/uploads/.
     *
     * @param array<string,mixed> $resource a t_resource row, or a listing photo row
     * @param string               $variant  one of self::VARIANTS
     *
     * @return string
     */
    public static function storageKey(array $resource, string $variant = ''): string
    {
        return self::keyPrefix($resource) . self::baseName($resource)
            . $variant . '.' . ($resource['s_extension'] ?? '');
    }

    /**
     * The file name before the variant and extension: s_base_name when a row was given a new id,
     * otherwise the id.
     *
     * @param array<string,mixed> $resource
     */
    public static function baseName(array $resource): string
    {
        $base = (string) ($resource['s_base_name'] ?? '');

        return ctype_digit($base) ? $base : (string) ($resource['pk_i_id'] ?? '');
    }

    /**
     * Directory portion of the storage key (the part before the filename).
     *
     * @param array<string,mixed> $resource a t_resource row, or a listing photo row
     *
     * @return string
     */
    public static function keyPrefix(array $resource): string
    {
        return preg_replace('#^oc-content/uploads/#', '', $resource['s_path'] ?? '');
    }
}
