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
use mindstellar\market\Installer;
use mindstellar\market\PackageIndex;
use mindstellar\market\PluginDependencies;
use mindstellar\site\SiteMode;

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

    /** @var callable(string, string, array<string,mixed>): array<string,mixed> */
    private $fetcher;

    /** @var callable(): ?array */
    private $requirements;

    /** @var callable(string): ?string|null */
    private $switcher;

    /** @var array<string, array<string,mixed>>|null */
    private ?array $installed;

    /**
     * The callables and $installed are test seams; the installer leaves them null.
     *
     * @param array<string, array<int, array<string,mixed>>> $themeVersions  Catalog::forThemes()->updates()
     * @param array<string, array<string,mixed>>             $themeIndex     Catalog::forThemes()->index()
     * @param array<string, array<int, array<string,mixed>>> $pluginVersions Catalog::forPlugins()->updates()
     * @param array<string, array<string,mixed>>             $pluginIndex    Catalog::forPlugins()->index()
     * @param callable|null $fetcher      (type, slug, version entry) => Installer result; type is theme or plugin
     * @param callable|null $requirements () => Aisle's plugin list, null while Aisle is not on disk
     * @param callable|null $switcher     (slug) => null when the plugin is on, else an error message
     * @param array<string, array<string,mixed>>|null $installed plugins on disk; read from the site when null
     */
    public function __construct(
        array $themeVersions,
        array $themeIndex,
        array $pluginVersions,
        array $pluginIndex,
        ?callable $fetcher = null,
        ?callable $requirements = null,
        ?callable $switcher = null,
        ?array $installed = null
    ) {
        $this->themeVersions  = $themeVersions;
        $this->themeIndex     = $themeIndex;
        $this->pluginVersions = $pluginVersions;
        $this->pluginIndex    = $pluginIndex;
        $this->fetcher        = $fetcher ?? static function (string $type, string $slug, array $entry): array {
            return ($type === 'theme' ? Installer::forThemes() : Installer::forPlugins())->install($slug, $entry);
        };
        $this->requirements = $requirements ?? static fn (): ?array => PluginDependencies::themeRequirements(self::THEME);
        $this->switcher     = $switcher;
        $this->installed    = $installed;
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
     * The plugins whose box starts ticked.
     *
     * @return list<string>
     */
    public static function defaultPlugins(): array
    {
        return array_keys(array_filter(self::PLUGINS));
    }

    /**
     * The shop install in order: Aisle, then each chosen plugin the catalogue has, then shop mode.
     *
     * @param list<string> $plugins the chosen plugin slugs
     *
     * @return list<array{action:string, type:string, slug:string, name:string}> action is install_package or activate_theme
     */
    public function steps(array $plugins): array
    {
        $steps = [['action' => 'install_package', 'type' => 'theme', 'slug' => self::THEME, 'name' => 'Aisle']];
        foreach (array_keys(self::PLUGINS) as $slug) {
            if (in_array($slug, $plugins, true) && $this->pluginEntry($slug) !== null) {
                $name    = (string) ($this->pluginIndex[$slug]['name'] ?? $slug);
                $steps[] = ['action' => 'install_package', 'type' => 'plugin', 'slug' => $slug, 'name' => $name];
            }
        }
        $steps[] = ['action' => 'activate_theme', 'type' => 'theme', 'slug' => self::THEME, 'name' => 'Aisle'];

        return $steps;
    }

    /**
     * Run one step from steps().
     *
     * @param array{action:string, type:string, slug:string} $step
     *
     * @return array{ok:bool, message:string}
     */
    public function run(array $step): array
    {
        if ($step['action'] === 'activate_theme') {
            return $this->activate();
        }

        return $step['type'] === 'theme' ? $this->installTheme() : $this->installPlugin($step['slug']);
    }

    /**
     * Download Aisle, unless it is on disk already.
     *
     * @return array{ok:bool, message:string}
     */
    public function installTheme(): array
    {
        if (osc_market_changes_blocked()) {
            return self::answer(false, __('Package installs are disabled on this deployment.'));
        }
        if (($this->requirements)() !== null) {
            return self::answer(true, __('Theme installed.'));
        }
        $entry = $this->themeEntry();
        if ($entry === null) {
            return self::answer(false, __('No release of this theme runs on this version of Shopclass.'));
        }
        $result = ($this->fetcher)('theme', self::THEME, $entry);

        return self::answer(!empty($result['ok']), (string) ($result['message'] ?? ''));
    }

    /**
     * Download and switch on one shop plugin. Needs Aisle on disk, since its manifest says which
     * releases fit.
     *
     * @return array{ok:bool, message:string}
     */
    public function installPlugin(string $slug): array
    {
        if (!isset(self::PLUGINS[$slug])) {
            return self::answer(false, __('The theme does not ask for this plugin.'));
        }
        $requires = ($this->requirements)();
        if ($requires === null) {
            return self::answer(false, __('Skipped, because the Aisle theme is not installed.'));
        }
        $fetcher = $this->fetcher;
        $deps    = new PluginDependencies(
            $this->installed ?? PackageIndex::forPlugins()->installed(),
            $this->pluginVersions,
            $this->pluginIndex,
            null,
            null,
            static fn (string $mode, string $plugin, array $entry): array => $fetcher('plugin', $plugin, $entry),
            $this->switcher
        );
        $result = $deps->ensure($requires, $slug);

        return self::answer($result['ok'], $result['message']);
    }

    /**
     * Make Aisle the site's theme and only then turn the site into a shop.
     *
     * @return array{ok:bool, message:string}
     */
    public function activate(): array
    {
        $requires = ($this->requirements)();
        if ($requires === null) {
            return self::answer(false, __('The Aisle theme is not installed, so the site stays a classifieds site.'));
        }
        $deps = new PluginDependencies($this->installed ?? PackageIndex::forPlugins()->installed(), [], []);
        if ($deps->activateTheme(self::THEME, $requires, true) !== 'live') {
            return self::answer(false, __('A plugin the Aisle theme needs is missing, so the site stays a classifieds site.'));
        }
        osc_set_preference('site_mode', SiteMode::SHOP);

        return self::answer(true, __('Shop mode is on.'));
    }

    /**
     * @return array{ok:bool, message:string}
     */
    private static function answer(bool $ok, string $message): array
    {
        return ['ok' => $ok, 'message' => $message];
    }

    /**
     * The newest Aisle release this core and PHP can run, or null.
     *
     * @return array<string,mixed>|null
     */
    private function themeEntry(): ?array
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
    private function pluginEntry(string $slug): ?array
    {
        if (!isset(self::PLUGINS[$slug])) {
            return null;
        }

        return Compatibility::pickBestVersion($this->pluginVersions[$slug] ?? []);
    }
}
