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

namespace mindstellar\market;

use mindstellar\security\Demo;

/**
 * The plugins a theme needs: what state each one is in, installing one at a time from the
 * market, and holding the theme back until every required plugin is active.
 *
 * @package mindstellar\market
 */
final class PluginDependencies
{
    public const ACTIVE         = 'active';
    public const INACTIVE       = 'inactive';
    public const MISSING        = 'missing';
    public const TOO_OLD        = 'too_old';
    public const INCOMPATIBLE   = 'incompatible';
    public const NOT_IN_CATALOG = 'not_in_catalog';

    /** @var array<string, array<string,mixed>> */
    private array $installed;

    /** @var array<string, array<int, array<string,mixed>>> */
    private array $versions;

    /** @var array<string, array<string,mixed>> */
    private array $index;

    private ?string $coreVersion;

    private ?string $phpVersion;

    /** @var callable(string, string, array<string,mixed>): array<string,mixed> */
    private $fetcher;

    /** @var callable(string): ?string */
    private $switcher;

    /**
     * @param array<string, array<string,mixed>>             $installed slug => name, version, enabled
     * @param array<string, array<int, array<string,mixed>>> $versions  Catalog::updates()
     * @param array<string, array<string,mixed>>             $index     Catalog::index()
     * @param callable|null $fetcher  (mode, slug, version entry) => Installer result
     * @param callable|null $switcher (slug) => null when the plugin is on, else an error message
     */
    public function __construct(
        array $installed,
        array $versions,
        array $index,
        ?string $coreVersion = null,
        ?string $phpVersion = null,
        ?callable $fetcher = null,
        ?callable $switcher = null
    ) {
        $this->installed   = $installed;
        $this->versions    = $versions;
        $this->index       = $index;
        $this->coreVersion = $coreVersion;
        $this->phpVersion  = $phpVersion;
        $this->fetcher     = $fetcher ?? static function (string $mode, string $slug, array $entry): array {
            $installer = Installer::forPlugins();

            return $mode === 'update' ? $installer->update($slug, $entry) : $installer->install($slug, $entry);
        };
        $this->switcher = $switcher ?? [self::class, 'switchOn'];
    }

    /**
     * The installed plugins and the cached plugin catalog of this site.
     */
    public static function forSite(): self
    {
        $catalog = Catalog::forPlugins();

        return new self(PackageIndex::forPlugins()->installed(), $catalog->updates(), $catalog->index());
    }

    /**
     * The plugins an installed theme asks for, or null when no such theme is installed.
     *
     * @return array{required: array<string,string>, recommended: array<string,string>}|null
     */
    public static function themeRequirements(string $theme): ?array
    {
        $themes = \WebThemes::getInstance();
        if ($theme === '' || !in_array($theme, $themes->getListThemes(), true)) {
            return null;
        }
        $info  = $themes->loadThemeInfo($theme);
        $needs = is_array($info) && is_array($info['requires_plugins'] ?? null) ? $info['requires_plugins'] : [];

        return [
            'required'    => (array) ($needs['required'] ?? []),
            'recommended' => (array) ($needs['recommended'] ?? []),
        ];
    }

    /**
     * One row per plugin the theme asks for.
     *
     * @param array{required?: array<string,string>, recommended?: array<string,string>} $requires
     *
     * @return array{required: list<array<string,mixed>>, recommended: list<array<string,mixed>>}
     */
    public function resolve(array $requires): array
    {
        $out = ['required' => [], 'recommended' => []];
        foreach (['required', 'recommended'] as $kind) {
            foreach ((array) ($requires[$kind] ?? []) as $slug => $constraint) {
                if ($kind === 'recommended' && isset($requires['required'][$slug])) {
                    continue;
                }
                $out[$kind][] = $this->row((string) $slug, (string) $constraint, $kind === 'required');
            }
        }

        return $out;
    }

    /**
     * What one plugin needs before the theme can use it.
     *
     * @return array{slug:string, name:string, description:string, constraint:string,
     *               required:bool, status:string, action:string, installed_version:string,
     *               offer:array<string,mixed>|null, manual_url:string, message:string}
     */
    public function row(string $slug, string $constraint, bool $required): array
    {
        $local    = $this->installed[$slug] ?? null;
        $versions = $this->versions[$slug] ?? [];
        $meets    = array_values(array_filter(
            $versions,
            static fn ($v) => VersionConstraint::satisfies((string) ($v['version'] ?? ''), $constraint)
        ));
        $offer  = Compatibility::pickBestVersion($meets, $this->coreVersion, $this->phpVersion);
        $manual = (string) (($offer ?? $meets[0] ?? $versions[0] ?? [])['url'] ?? '');
        if (!preg_match('#^https://#i', $manual)) {
            $manual = '';
        }

        $row = [
            'slug'              => $slug,
            'name'              => (string) ($this->index[$slug]['name'] ?? $local['name'] ?? $slug),
            'description'       => (string) ($this->index[$slug]['short_description'] ?? ''),
            'constraint'        => $constraint,
            'required'          => $required,
            'status'            => self::ACTIVE,
            'action'            => 'none',
            'installed_version' => (string) ($local['version'] ?? ''),
            'offer'             => $offer,
            'manual_url'        => $manual,
            'message'           => '',
        ];

        if ($local !== null && VersionConstraint::satisfies($row['installed_version'], $constraint)) {
            if (empty($local['enabled'])) {
                $row['status'] = self::INACTIVE;
                $row['action'] = 'activate';
            }

            return $row;
        }

        if ($local !== null) {
            $row['status'] = self::TOO_OLD;
            if ($offer !== null) {
                $row['action'] = 'update';
            } else {
                $row['message'] = sprintf(
                    __('Version %1$s is installed; the theme needs %2$s and no release this site can run meets that.'),
                    $row['installed_version'],
                    $constraint
                );
            }

            return $row;
        }

        if ($versions === []) {
            $row['status']  = self::NOT_IN_CATALOG;
            $row['message'] = __('This plugin is not in the market. Get its zip file from the theme author and upload it under Plugins, Add plugin.');

            return $row;
        }

        if ($offer === null) {
            $row['status']  = self::INCOMPATIBLE;
            $row['message'] = __('No release of this plugin that the theme accepts runs on this version of Shopclass or PHP.');

            return $row;
        }

        $row['status'] = self::MISSING;
        $row['action'] = 'install';

        return $row;
    }

    /**
     * Required plugins that are not yet active.
     *
     * @param array{required?: array<string,string>, recommended?: array<string,string>} $requires
     *
     * @return list<array<string,mixed>>
     */
    public function missingRequired(array $requires): array
    {
        return array_values(array_filter(
            $this->resolve($requires)['required'],
            static fn ($row) => $row['status'] !== self::ACTIVE
        ));
    }

    /**
     * Whether the theme has to wait for the plugins screen. Required plugins always hold it;
     * recommended ones only until the owner has seen the screen once.
     *
     * @param array{required?: array<string,string>, recommended?: array<string,string>} $requires
     */
    public function holds(array $requires, bool $screenSeen): bool
    {
        if ($this->missingRequired($requires) !== []) {
            return true;
        }
        if ($screenSeen) {
            return false;
        }
        foreach ($this->resolve($requires)['recommended'] as $row) {
            if ($row['status'] !== self::ACTIVE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Make $theme the site's theme unless a plugin still holds it.
     *
     * @param array{required?: array<string,string>, recommended?: array<string,string>} $requires
     *
     * @return string 'live', or 'held' when the plugins screen has to come first
     */
    public function activateTheme(string $theme, array $requires, bool $screenSeen): string
    {
        if ($this->holds($requires, $screenSeen)) {
            return 'held';
        }
        osc_set_preference('theme', $theme);
        // A new theme's code must run at once, even with opcache.validate_timestamps=Off.
        \Plugins::resetOpcache();
        osc_run_hook('theme_activate', $theme);

        return 'live';
    }

    /**
     * Install, update or switch on one plugin the theme asks for. One plugin per call, so
     * each runs in its own request on shared hosting.
     *
     * @param array{required?: array<string,string>, recommended?: array<string,string>} $requires
     *
     * @return array{ok:bool, message:string, slug:string, status:string, manual_url:string}
     */
    public function ensure(array $requires, string $slug): array
    {
        if (isset($requires['required'][$slug])) {
            $row = $this->row($slug, (string) $requires['required'][$slug], true);
        } elseif (isset($requires['recommended'][$slug])) {
            $row = $this->row($slug, (string) $requires['recommended'][$slug], false);
        } else {
            return self::answer(false, __('The theme does not ask for this plugin.'), $slug, '', '');
        }

        if ($row['status'] === self::ACTIVE) {
            return self::answer(true, __('Plugin is active.'), $slug, self::ACTIVE, '');
        }
        if (Demo::active()) {
            return self::answer(false, Demo::message(), $slug, $row['status'], '');
        }
        if (osc_package_installs_disabled()) {
            return self::answer(false, __('Package installs are disabled on this deployment.'), $slug, $row['status'], '');
        }
        if ($row['action'] === 'none') {
            return self::answer(false, $row['message'], $slug, $row['status'], $row['manual_url']);
        }

        if ($row['action'] === 'install' || $row['action'] === 'update') {
            $result = ($this->fetcher)($row['action'], $slug, (array) $row['offer']);
            if (empty($result['ok'])) {
                $message = (string) ($result['message'] ?? '');
                if ($row['manual_url'] !== '') {
                    $message .= ' ' . __('You can also download the zip file and upload it under Plugins, Add plugin.');
                }

                return self::answer(false, trim($message), $slug, $row['status'], $row['manual_url']);
            }
        }

        $error = ($this->switcher)($slug);
        if ($error !== null) {
            return self::answer(false, $error, $slug, self::INACTIVE, $row['manual_url']);
        }

        return self::answer(true, __('Plugin installed and active.'), $slug, self::ACTIVE, '');
    }

    /**
     * Turn on a plugin that is on disk: finish its install, or just activate it.
     *
     * @return string|null null when it is on, else what went wrong
     */
    public static function switchOn(string $slug): ?string
    {
        $path = $slug . '/index.php';
        if (!\Plugins::isInstalled($path)) {
            $installed = \Plugins::install($path);
            $code      = is_array($installed) ? (string) ($installed['error_code'] ?? '') : '';
            // Already installed, or installed but noisy: go on and make sure it is on.
            if ($installed !== true && $code !== 'error_installed' && $code !== 'error_output') {
                return \Plugins::installError($installed);
            }
        }
        if (!\Plugins::isEnabled($path)) {
            \Plugins::activate($path);
        }

        return null;
    }

    /**
     * @return array{ok:bool, message:string, slug:string, status:string, manual_url:string}
     */
    private static function answer(bool $ok, string $message, string $slug, string $status, string $manual): array
    {
        return ['ok' => $ok, 'message' => $message, 'slug' => $slug, 'status' => $status, 'manual_url' => $manual];
    }
}

/* file end: ./oc-includes/osclass/classes/market/PluginDependencies.php */
