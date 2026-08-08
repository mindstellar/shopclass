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

    private function __construct(string $type)
    {
        $this->type    = $type;
        $this->catalog = $type === self::TYPE_PLUGIN ? Catalog::forPlugins() : Catalog::forThemes();
    }

    public static function forPlugins(): self
    {
        return new self(self::TYPE_PLUGIN);
    }

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
     *              enabled:?bool, active:?bool, in_catalog:bool, catalog:?array,
     *              compatibility:array{status:string, blocked:bool, reason:string},
     *              update:?array}>
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
     * @return array<string, array{slug:string, name:string, short_description:string,
     *              author:string, version:string, icon:?string, categories:array<int,string>,
     *              tags:array<int,string>, updated_at:string,
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

            // index.json does not carry `requires` — pull it from the newest entry in
            // updates.json (newest-first) so the browse badge reflects real compatibility
            // instead of guessing from the slim row alone.
            $versions = $updates[$slug] ?? [];
            $latest   = $versions[0] ?? null;
            $compatInfo = $latest !== null
                ? [
                    'requires'     => $latest['requires'],
                    'requires_php' => $latest['requires_php'],
                    'tested_up_to' => $latest['tested'],
                ]
                : [];

            $rows[$slug] = $row + ['compatibility' => Compatibility::evaluate($compatInfo)];
        }

        return $rows;
    }

    /**
     * Highest version each installed package may safely move to — the highest catalog
     * version whose `requires`/`requires_php` the running core/PHP satisfy, picked via
     * Compatibility::pickBestVersion() so a 6.0.x site is never offered a release it
     * cannot run even though a newer, incompatible one exists.
     *
     * @return array<string, array{version:string, requires:string, requires_php:string,
     *              tested:string, url:string, sha256:string, size:int}>
     */
    public function pendingUpdates(): array
    {
        return $this->pendingUpdatesFor($this->rawInstalled());
    }

    // ---- internals ----------------------------------------------------

    /** installed() rows without the `update` key — shared by installed() and pendingUpdates(). */
    private function rawInstalled(): array
    {
        return $this->type === self::TYPE_PLUGIN ? $this->rawInstalledPlugins() : $this->rawInstalledThemes();
    }

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

    private function rawInstalledThemes(): array
    {
        $index      = $this->catalog->index();
        $webThemes  = WebThemes::newInstance();
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

    /** @param array<string, array> $rows result of rawInstalled() */
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

    private function pluginSlug(string $file): string
    {
        $slug = dirname($file);

        return $slug !== '.' ? $slug : $file;
    }
}
