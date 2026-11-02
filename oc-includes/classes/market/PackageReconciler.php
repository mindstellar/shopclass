<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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
    /**
     * Not instantiable: every entry point on this class is static.
     */
    private function __construct()
    {
    }

    /**
     * Installs bundled packages missing from the live volume and refreshes the ones
     * the image ships a strictly newer version of.
     *
     * @param string $pristineRoot   image-baked copy of oc-content (plugins/ and
     *                                themes/ subdirectories), outside any volume mount
     * @param string $livePluginsPath PLUGINS_PATH
     * @param string $liveThemesPath  THEMES_PATH
     *
     * @return array<int, string> one human-readable line per action taken
     * @throws \RuntimeException when a package directory cannot be synced
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
     * Refreshes the language files the image bundles, such as en_US, onto the live volume.
     *
     * A file is written only when it is missing or still holds what the last refresh wrote, so a
     * language the owner downloaded or edited, and any file the image does not ship, is left alone.
     * The hashes written are kept in .bundled.json in the live directory.
     *
     * @param string $pristineDir image-baked copy of oc-content/languages
     * @param string $liveDir     the live oc-content/languages
     *
     * @return array<int, string> one line per file written or kept
     */
    public static function reconcileLanguages(string $pristineDir, string $liveDir): array
    {
        $pristineDir = rtrim($pristineDir, '/') . '/';
        $liveDir     = rtrim($liveDir, '/') . '/';
        if (!is_dir($pristineDir) || !is_dir($liveDir)) {
            return [];
        }

        $manifestFile = $liveDir . '.bundled.json';
        $manifest     = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : [];
        $manifest     = is_array($manifest) ? $manifest : [];
        $log          = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pristineDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel     = substr($file->getPathname(), strlen($pristineDir));
            $target  = $liveDir . $rel;
            $bundled = (string) sha1_file($file->getPathname());
            $live    = is_file($target) ? (string) sha1_file($target) : null;

            if ($live === $bundled) {
                $manifest[$rel] = $bundled;
                continue;
            }
            if ($live !== null && ($manifest[$rel] ?? null) !== $live) {
                $log[] = 'kept language file ' . $rel . ' (changed on this site)';
                continue;
            }
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
                throw new \RuntimeException('Cannot create ' . dirname($target));
            }
            if (!@copy($file->getPathname(), $target)) {
                throw new \RuntimeException('Cannot write ' . $target);
            }
            $manifest[$rel] = $bundled;
            $log[]          = ($live === null ? 'installed' : 'refreshed') . ' language file ' . $rel;
        }

        ksort($manifest);
        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $log;
    }

    /**
     * Slugs of the package directories the image bundles, ignoring index.php.
     *
     * @param string $pristinePath must end with a slash
     *
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

    /**
     * Installs or refreshes one bundled package, leaving the live copy alone when either
     * side has no readable Version header or the live copy is not older.
     *
     * @param string $kind 'plugin' or 'theme'
     * @param string $slug
     * @param string $pristineDir
     * @param string $liveDir
     *
     * @return string|null a log line, or null when nothing was done
     * @throws \RuntimeException when the package directory cannot be synced
     */
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

    /**
     * Reads the Version header out of a package's index.php.
     *
     * @param string $packageDir
     *
     * @return string '' when the file or the header is missing
     */
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
