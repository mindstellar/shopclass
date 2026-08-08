<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\market;

use mindstellar\utility\FileSystem;

/**
 * Class PackageReconciler
 *
 * Reconciles the plugins/themes an image bundles against a persistent
 * oc-content volume, run on every container start. Solves the trap of a named
 * volume being seeded once from the image and then never touched again: a
 * later image that fixes a bundled plugin or ships a newer bundled theme
 * would otherwise never reach a running site.
 *
 * Identification rule: a package is "bundled" if, and only if, its slug
 * directory exists under the image's pristine oc-content copy (baked outside
 * any volume mount, so it always reflects the image currently running —
 * never whatever a previous container happened to write into the volume).
 * Reconcile only ever iterates that pristine set, so a package the site owner
 * installed through the market under any other slug is never inspected, let
 * alone touched. For a slug that is bundled, the live copy is only replaced
 * when the image's Version header is strictly newer than the live one, so a
 * site owner who has updated a bundled package past what this image ships
 * keeps their copy.
 *
 * @package mindstellar\market
 */
final class PackageReconciler
{
    private function __construct()
    {
    }

    /**
     * @param string $pristineRoot   image-baked copy of oc-content (plugins/ and
     *                                themes/ subdirectories), outside any volume mount
     * @param string $livePluginsPath PLUGINS_PATH
     * @param string $liveThemesPath  THEMES_PATH
     *
     * @return array<int, string> one human-readable line per action taken
     */
    public static function reconcile(string $pristineRoot, string $livePluginsPath, string $liveThemesPath): array
    {
        $pristineRoot = rtrim($pristineRoot, '/');
        $log          = [];

        $kinds = [
            'plugin' => [$pristineRoot . '/plugins/', rtrim($livePluginsPath, '/') . '/'],
            'theme'  => [$pristineRoot . '/themes/', rtrim($liveThemesPath, '/') . '/'],
        ];

        foreach ($kinds as $kind => [$pristinePath, $livePath]) {
            if (!is_dir($pristinePath)) {
                continue;
            }
            foreach (self::bundledSlugs($pristinePath) as $slug) {
                $line = self::reconcileOne($kind, $slug, $pristinePath . $slug, $livePath . $slug);
                if ($line !== null) {
                    $log[] = $line;
                }
            }
        }

        return $log;
    }

    /**
     * @return array<int, string>
     */
    private static function bundledSlugs(string $pristinePath): array
    {
        $entries = @scandir($pristinePath);
        if ($entries === false) {
            return [];
        }

        $slugs = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'index.php') {
                continue;
            }
            if (is_dir($pristinePath . $entry)) {
                $slugs[] = $entry;
            }
        }

        return $slugs;
    }

    private static function reconcileOne(string $kind, string $slug, string $pristineDir, string $liveDir): ?string
    {
        $fs = new FileSystem();

        if (!is_dir($liveDir)) {
            $fs->sync($pristineDir, $liveDir, ['override' => true]);

            $version = self::readVersion($pristineDir);

            return sprintf('installed %s %s%s', $kind, $slug, $version !== '' ? " ({$version})" : '');
        }

        $pristineVersion = self::readVersion($pristineDir);
        $liveVersion      = self::readVersion($liveDir);

        // Unreadable version on either side: never guess, leave the live copy alone.
        if ($pristineVersion === '' || $liveVersion === '') {
            return null;
        }

        if (version_compare($pristineVersion, $liveVersion, '>')) {
            $fs->sync($pristineDir, $liveDir, ['delete' => true, 'override' => true]);

            return sprintf('refreshed %s %s (%s -> %s)', $kind, $slug, $liveVersion, $pristineVersion);
        }

        return null;
    }

    private static function readVersion(string $packageDir): string
    {
        $indexFile = $packageDir . '/index.php';
        if (!is_file($indexFile)) {
            return '';
        }

        $contents = @file_get_contents($indexFile);
        if ($contents === false) {
            return '';
        }

        if (preg_match('|Version:([^\r\t\n]*)|i', $contents, $match)) {
            return trim($match[1]);
        }

        return '';
    }
}
