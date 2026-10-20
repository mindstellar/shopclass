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

use Plugins;
use WebThemes;

/**
 * Class PackageIndex
 *
 * Joins the cached catalog (Catalog::index() / Catalog::updates()) with what is actually
 * on disk (Plugins::listAll()/getInfo() or WebThemes::getListThemes()/loadThemeInfo()) so
 * the Installed and Browse admin screens render from one place. Reads the catalog
 * cache only — it never forces a network check itself, so building these rows never
 * causes egress on its own.
 *
 * @package mindstellar\market
 */
final class PackageIndex
{
    private const TYPE_PLUGIN = 'plugin';
    private const TYPE_THEME  = 'theme';

    private string $type;
    private Catalog $catalog;

    /**
     * @param string $type self::TYPE_PLUGIN or self::TYPE_THEME
     */
    private function __construct(string $type)
    {
        $this->type    = $type;
        $this->catalog = $type === self::TYPE_PLUGIN ? Catalog::forPlugins() : Catalog::forThemes();
    }

    /**
     * Index over the installed plugins and the plugin catalog.
     *
     * @return self
     */
    public static function forPlugins(): self
    {
        return new self(self::TYPE_PLUGIN);
    }

    /**
     * Index over the installed themes and the theme catalog.
     *
     * @return self
     */
    public static function forThemes(): self
    {
        return new self(self::TYPE_THEME);
    }

    /**
     * Locally-present packages (installed, whether enabled/active or not), joined with
     * whatever the catalog knows about them.
     *
     * @return array<string, array{slug:string, name:string, version:string, author:string,
     *              requires:string, requires_php:string, tested_up_to:string,
     *              enabled:?bool, active:?bool, in_catalog:bool,
     *              catalog:array<string,mixed>|null,
     *              compatibility:array{status:string, blocked:bool, reason:string},
     *              update:array<string,mixed>|null}> keyed by slug
     */
    public function installed(): array
    {
        $rows    = $this->rawInstalled();
        $pending = $this->pendingUpdatesFor($rows);

        foreach ($rows as $slug => &$row) {
            $row['update'] = $pending[$slug] ?? null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Catalog entries that are not present locally — what the Browse screen renders for
     * "not yet installed".
     *
     * Each row describes the release Install would fetch: `version` and `offer` (its
     * requires, requires_php and tested_up_to) are that release's, and `compatibility` is
     * judged on it. The other index fields pass through, `requires_min`/`tested_max` being
     * the range across every release.
     *
     * @return array<string, array{slug:string, name:string, short_description:string,
     *              author:string, version:string, icon:?string, categories:array<int,string>,
     *              tags:array<int,string>, updated_at:string, requires_min:?string,
     *              tested_max:?string, offer:array<string,string>,
     *              compatibility:array{status:string, blocked:bool, reason:string}}>
     */
    public function available(): array
    {
        $index      = $this->catalog->index();
        $updates    = $this->catalog->updates();
        $localSlugs = array_keys($this->rawInstalled());

        $rows = [];
        foreach ($index as $slug => $row) {
            if (in_array($slug, $localSlugs, true)) {
                continue;
            }

            $rows[$slug] = self::browseRow($row, $updates[$slug] ?? []);
        }

        return $rows;
    }

    /**
     * A Browse row for the version Install would fetch: the newest this site can run, else
     * the newest. index.json does not carry `requires`, so it comes from updates.json.
     *
     * @param array<string,mixed>             $row      the index.json row
     * @param array<int,array<string,mixed>> $versions updates.json entries, newest first
     *
     * @return array<string,mixed>
     */
    private static function browseRow(array $row, array $versions): array
    {
        $offer = Compatibility::pickBestVersion($versions) ?? ($versions[0] ?? null);
        $info  = [];
        if ($offer !== null) {
            $row['version'] = (string) $offer['version'];
            $info           = [
                'requires'     => (string) ($offer['requires'] ?? ''),
                'requires_php' => (string) ($offer['requires_php'] ?? ''),
                'tested_up_to' => (string) ($offer['tested'] ?? ''),
            ];
        }

        return $row + ['offer' => $info, 'compatibility' => Compatibility::evaluate($info)];
    }

    /**
     * Highest version each installed package may safely move to — the highest catalog
     * version whose `requires`/`requires_php` the running core/PHP satisfy, picked via
     * Compatibility::pickBestVersion() so a 6.0.x site is never offered a release it
     * cannot run even though a newer, incompatible one exists.
     *
     * @return array<string, array{version:string, requires:string, requires_php:string,
     *              tested:string, url:string, sha256:string, size:int, published_at:string,
     *              downloads:int}> keyed by slug
     */
    public function pendingUpdates(): array
    {
        return $this->pendingUpdatesFor($this->rawInstalled());
    }

    // ---- internals ----------------------------------------------------

    /**
     * installed() rows without the `update` key — shared by installed() and pendingUpdates().
     *
     * @return array<string, array<string,mixed>> keyed by slug
     */
    private function rawInstalled(): array
    {
        return $this->type === self::TYPE_PLUGIN ? $this->rawInstalledPlugins() : $this->rawInstalledThemes();
    }

    /**
     * On-disk plugins joined with their catalog row and compatibility verdict.
     *
     * @return array<string, array<string,mixed>> keyed by slug
     */
    private function rawInstalledPlugins(): array
    {
        $index = $this->catalog->index();

        $rows = [];
        foreach (Plugins::listAll() as $file) {
            // Plugins::listAll()/getInfo() key by "slug/index.php"; the catalog and the
            // on-disk directory name both use the bare slug — this is the one place that
            // conversion has to happen, or every lookup below silently misses.
            $slug = $this->pluginSlug($file);
            $info = Plugins::getInfo($file);

            $catalogRow = $index[$slug] ?? null;

            $rows[$slug] = [
                'slug'          => $slug,
                'name'          => $info['plugin_name'] ?? $slug,
                'version'       => $info['version'] ?? '',
                'author'        => $info['author'] ?? '',
                'requires'      => $info['requires'] ?? '',
                'requires_php'  => $info['requires_php'] ?? '',
                'tested_up_to'  => $info['tested_up_to'] ?? '',
                'enabled'       => Plugins::isEnabled($file),
                'active'        => null,
                'in_catalog'    => $catalogRow !== null,
                'catalog'       => $catalogRow,
                'compatibility' => Compatibility::evaluate($info),
            ];
        }

        return $rows;
    }

    /**
     * On-disk themes joined with their catalog row and compatibility verdict.
     *
     * @return array<string, array<string,mixed>> keyed by slug
     */
    private function rawInstalledThemes(): array
    {
        $index      = $this->catalog->index();
        $webThemes  = WebThemes::getInstance();
        $activeSlug = function_exists('osc_theme') ? osc_theme() : null;

        $rows = [];
        foreach ($webThemes->getListThemes() as $slug) {
            $info = $webThemes->loadThemeInfo($slug);
            if (!is_array($info)) {
                continue;
            }

            $catalogRow = $index[$slug] ?? null;

            $rows[$slug] = [
                'slug'          => $slug,
                'name'          => $info['name'] ?? $slug,
                'version'       => $info['version'] ?? '',
                // Theme headers use `author_name`, plugin headers use `author` — normalised here.
                'author'        => $info['author_name'] ?? '',
                'requires'      => $info['requires'] ?? '',
                'requires_php'  => $info['requires_php'] ?? '',
                'tested_up_to'  => $info['tested_up_to'] ?? '',
                'enabled'       => null,
                'active'        => $activeSlug === $slug,
                'in_catalog'    => $catalogRow !== null,
                'catalog'       => $catalogRow,
                'compatibility' => Compatibility::evaluate($info),
            ];
        }

        return $rows;
    }

    /**
     * Best safely-installable catalog version above each row's installed version.
     *
     * @param array<string, array<string,mixed>> $rows result of rawInstalled()
     *
     * @return array<string, array<string,mixed>> keyed by slug; only rows with an update
     */
    private function pendingUpdatesFor(array $rows): array
    {
        $updates = $this->catalog->updates();

        $result = [];
        foreach ($rows as $slug => $row) {
            if (!isset($updates[$slug])) {
                continue;
            }

            $best = Compatibility::pickBestVersion($updates[$slug]);
            if ($best === null) {
                continue;
            }

            $installedVersion = (string) ($row['version'] ?? '');
            if ($installedVersion === '' || version_compare((string) $best['version'], $installedVersion, '>')) {
                $result[$slug] = $best;
            }
        }

        return $result;
    }

    /**
     * The bare directory slug behind a Plugins::listAll() "slug/index.php" key.
     *
     * @param string $file
     *
     * @return string
     */
    private function pluginSlug(string $file): string
    {
        $slug = dirname($file);

        return $slug !== '.' ? $slug : $file;
    }
}
