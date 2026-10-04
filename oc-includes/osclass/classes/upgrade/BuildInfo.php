<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\upgrade;

/**
 * How this copy was built. The edge image writes oc-includes/osclass/build-info.php with its
 * channel, git commit and build time; a release ships without that file.
 */
final class BuildInfo
{
    public const EDGE = 'edge';

    /** @var array{channel:string,revision:string,built:string}|null */
    private static ?array $info = null;

    private static bool $loaded = false;

    /**
     * Where the build writes the marker.
     *
     * @return string
     */
    public static function file(): string
    {
        return dirname(__DIR__, 2) . '/build-info.php';
    }

    /**
     * Read a marker file and use it from now on. A missing or malformed file means a release.
     *
     * @param string $file
     *
     * @return array{channel:string,revision:string,built:string}|null
     */
    public static function load(string $file): ?array
    {
        try {
            $data = is_file($file) ? include $file : null;
        } catch (\Throwable $e) {
            // A truncated or corrupt marker is no marker.
            $data = null;
        }

        self::$info   = self::clean($data);
        self::$loaded = true;

        return self::$info;
    }

    /**
     * @return array{channel:string,revision:string,built:string}|null
     */
    public static function current(): ?array
    {
        return self::$loaded ? self::$info : self::load(self::file());
    }

    /**
     * Whether this is an edge image, which is updated by pulling the image, never in the app.
     *
     * @return bool
     */
    public static function isEdge(): bool
    {
        return (self::current()['channel'] ?? '') === self::EDGE;
    }

    /**
     * The version with the build appended when there is one: "6.4.0.202610030200 (edge, a65dcfe)".
     *
     * @param string $version
     *
     * @return string
     */
    public static function label(string $version): string
    {
        $info = self::current();
        if ($info === null) {
            return $version;
        }
        $parts = array_filter(array($info['channel'], substr($info['revision'], 0, 7)), 'strlen');

        return $version . ' (' . implode(', ', $parts) . ')';
    }

    /**
     * @param mixed $data
     *
     * @return array{channel:string,revision:string,built:string}|null
     */
    private static function clean($data): ?array
    {
        if (!is_array($data) || ($data['channel'] ?? null) !== self::EDGE) {
            return null;
        }
        $revision = is_string($data['revision'] ?? null) ? strtolower($data['revision']) : '';
        $built    = is_string($data['built'] ?? null) ? $data['built'] : '';

        return array(
            'channel'  => $data['channel'],
            'revision' => preg_match('/^[0-9a-f]{7,40}$/', $revision) ? $revision : '',
            'built'    => preg_match('/^\d{12}$/', $built) ? $built : '',
        );
    }
}
