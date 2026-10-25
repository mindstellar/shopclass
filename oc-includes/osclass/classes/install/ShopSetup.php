<?php

declare(strict_types=1);

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\install;

use mindstellar\market\Catalog;
use mindstellar\market\Compatibility;

/**
 * What the installer can offer for a shop site: the Aisle theme and the shop plugins,
 * read from the market catalogue.
 *
 * @package mindstellar\install
 */
final class ShopSetup
{
    public const THEME = 'aisle';

    /** The plugins Aisle recommends, in its order; true when the box starts ticked. */
    public const PLUGINS = [
        'shipping'      => true,
        'tax'           => true,
        'promotions'    => true,
        'razorpay'      => false,
        'shop-emails'   => true,
        'analytics'     => false,
        'digital-goods' => true,
    ];

    /** @var array<string, array<int, array<string,mixed>>> */
    private array $themeVersions;

    /** @var array<string, array<string,mixed>> */
    private array $themeIndex;

    /** @var array<string, array<int, array<string,mixed>>> */
    private array $pluginVersions;

    /** @var array<string, array<string,mixed>> */
    private array $pluginIndex;

    /**
     * @param array<string, array<int, array<string,mixed>>> $themeVersions  Catalog::forThemes()->updates()
     * @param array<string, array<string,mixed>>             $themeIndex     Catalog::forThemes()->index()
     * @param array<string, array<int, array<string,mixed>>> $pluginVersions Catalog::forPlugins()->updates()
     * @param array<string, array<string,mixed>>             $pluginIndex    Catalog::forPlugins()->index()
     */
    public function __construct(array $themeVersions, array $themeIndex, array $pluginVersions, array $pluginIndex)
    {
        $this->themeVersions  = $themeVersions;
        $this->themeIndex     = $themeIndex;
        $this->pluginVersions = $pluginVersions;
        $this->pluginIndex    = $pluginIndex;
    }

    /**
     * Ask the catalogue now. The plugin catalogue is only fetched when the theme one lists Aisle,
     * so an offline server waits for one request, not four.
     */
    public static function probe(): self
    {
        if (osc_market_changes_blocked()) {
            return new self([], [], [], []);
        }
        $themes = Catalog::forThemes();
        $index  = $themes->index(true);
        if (!isset($index[self::THEME])) {
            return new self([], $index, [], []);
        }
        $plugins = Catalog::forPlugins();

        return new self($themes->updates(true), $index, $plugins->updates(true), $plugins->index(true));
    }

    /**
     * The catalogue as the last probe() stored it; makes no request.
     */
    public static function fromCache(): self
    {
        $themes  = Catalog::forThemes();
        $plugins = Catalog::forPlugins();

        return new self($themes->updates(), $themes->index(), $plugins->updates(), $plugins->index());
    }

    /**
     * Whether a shop can be set up, and the plugins that can come with it.
     *
     * @return array{available:bool, reason:string,
     *               plugins:list<array{slug:string, name:string, description:string, checked:bool}>}
     *               reason is '', 'disabled' (package installs are off) or 'offline'
     */
    public function offer(): array
    {
        if (osc_market_changes_blocked()) {
            return ['available' => false, 'reason' => 'disabled', 'plugins' => []];
        }
        if ($this->themeEntry() === null) {
            return ['available' => false, 'reason' => 'offline', 'plugins' => []];
        }
        $plugins = [];
        foreach (self::PLUGINS as $slug => $checked) {
            if ($this->pluginEntry($slug) === null) {
                continue;
            }
            $plugins[] = [
                'slug'        => $slug,
                'name'        => (string) ($this->pluginIndex[$slug]['name'] ?? $slug),
                'description' => (string) ($this->pluginIndex[$slug]['short_description'] ?? ''),
                'checked'     => $checked,
            ];
        }

        return ['available' => true, 'reason' => '', 'plugins' => $plugins];
    }

    /**
     * The newest Aisle release this core and PHP can run, or null.
     *
     * @return array<string,mixed>|null
     */
    public function themeEntry(): ?array
    {
        if (!isset($this->themeIndex[self::THEME])) {
            return null;
        }

        return Compatibility::pickBestVersion($this->themeVersions[self::THEME] ?? []);
    }

    /**
     * The newest release of a shop plugin this core and PHP can run, or null.
     *
     * @return array<string,mixed>|null
     */
    public function pluginEntry(string $slug): ?array
    {
        if (!isset(self::PLUGINS[$slug])) {
            return null;
        }

        return Compatibility::pickBestVersion($this->pluginVersions[$slug] ?? []);
    }
}
