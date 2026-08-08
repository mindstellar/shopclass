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

/**
 * Class Compatibility
 *
 * Decides whether a plugin or theme is safe to install, update, or keep
 * running against the current core and PHP versions, from the
 * `Requires Shopclass` / `Tested up to` / `Requires PHP` header fields (or a
 * catalog entry carrying the same keys).
 *
 * @package mindstellar\market
 */
final class Compatibility
{
    /** Declared and compatible with the running core/PHP. */
    public const OK = 'ok';

    /** Neither `requires` nor `tested_up_to` is declared. */
    public const UNDECLARED = 'undeclared';

    /** `tested_up_to` is behind the running core, compared at minor precision. */
    public const UNTESTED = 'untested';

    /** `requires` is above the running core, or `requires_php` is above the running PHP. */
    public const INCOMPATIBLE = 'incompatible';

    private function __construct()
    {
    }

    /**
     * @param array $info a Plugins::getInfo() / WebThemes::loadThemeInfo() array,
     *                     or a catalog entry carrying the same 'requires' /
     *                     'tested_up_to' / 'requires_php' keys
     *
     * @return array{status:string, blocked:bool, reason:string} `reason` is a
     *               translated, human-readable sentence ('' when status is OK)
     */
    public static function evaluate(array $info, ?string $coreVersion = null, ?string $phpVersion = null): array
    {
        $coreVersion = $coreVersion ?? OSCLASS_VERSION;
        $phpVersion  = $phpVersion ?? PHP_VERSION;

        $requires    = self::normalize((string) ($info['requires'] ?? ''));
        $requiresPhp = self::normalize((string) ($info['requires_php'] ?? ''));
        $testedUpTo  = self::normalize((string) ($info['tested_up_to'] ?? ''));

        if ($requires !== null && version_compare($requires, $coreVersion, '>')) {
            return [
                'status'  => self::INCOMPATIBLE,
                'blocked' => true,
                'reason'  => sprintf(
                    __('Requires Shopclass %1$s or newer (you have %2$s).'),
                    $requires,
                    $coreVersion
                ),
            ];
        }

        if ($requiresPhp !== null && version_compare($requiresPhp, $phpVersion, '>')) {
            return [
                'status'  => self::INCOMPATIBLE,
                'blocked' => true,
                'reason'  => sprintf(__('Requires PHP %1$s (you have %2$s).'), $requiresPhp, $phpVersion),
            ];
        }

        if ($testedUpTo !== null) {
            // Authors declare "tested up to" against a minor line (e.g. "6.1"), not every patch.
            if (version_compare(self::minor($testedUpTo), self::minor($coreVersion), '<')) {
                return [
                    'status'  => self::UNTESTED,
                    'blocked' => false,
                    'reason'  => sprintf(__('Not tested with Shopclass %s yet.'), $coreVersion),
                ];
            }

            return ['status' => self::OK, 'blocked' => false, 'reason' => ''];
        }

        if ($requires !== null || $requiresPhp !== null) {
            return ['status' => self::OK, 'blocked' => false, 'reason' => ''];
        }

        return [
            'status'  => self::UNDECLARED,
            'blocked' => false,
            'reason'  => __('Compatibility with this Shopclass version has not been declared.'),
        ];
    }

    /**
     * Highest entry whose `requires` <= core and `requires_php` <= PHP.
     *
     * @param array $versions list of arrays each having at least 'version' and
     *                        optionally 'requires' / 'requires_php'
     *
     * @return array|null the winning entry, or null when none qualifies
     */
    public static function pickBestVersion(
        array $versions,
        ?string $coreVersion = null,
        ?string $phpVersion = null
    ): ?array {
        $coreVersion = $coreVersion ?? OSCLASS_VERSION;
        $phpVersion  = $phpVersion ?? PHP_VERSION;

        $best = null;
        foreach ($versions as $entry) {
            if (!isset($entry['version']) || $entry['version'] === '') {
                continue;
            }

            $requires    = self::normalize((string) ($entry['requires'] ?? ''));
            $requiresPhp = self::normalize((string) ($entry['requires_php'] ?? ''));

            if ($requires !== null && version_compare($requires, $coreVersion, '>')) {
                continue;
            }
            if ($requiresPhp !== null && version_compare($requiresPhp, $phpVersion, '>')) {
                continue;
            }

            if ($best === null || version_compare((string) $entry['version'], (string) $best['version'], '>')) {
                $best = $entry;
            }
        }

        return $best;
    }

    /** Short badge label for the admin UI, e.g. "Compatible with 6.0.x" / "Untested with 6.0" / "Requires 6.2+". */
    public static function badgeLabel(array $info, ?string $coreVersion = null): string
    {
        $coreVersion = $coreVersion ?? OSCLASS_VERSION;
        $verdict     = self::evaluate($info, $coreVersion);

        switch ($verdict['status']) {
            case self::INCOMPATIBLE:
                $requires = self::normalize((string) ($info['requires'] ?? ''));
                if ($requires !== null && version_compare($requires, $coreVersion, '>')) {
                    return sprintf(__('Requires %s+'), $requires);
                }

                $requiresPhp = self::normalize((string) ($info['requires_php'] ?? ''));

                return sprintf(__('Requires PHP %s'), $requiresPhp ?? '');
            case self::UNTESTED:
                return sprintf(__('Untested with %s'), self::minor($coreVersion));
            case self::UNDECLARED:
                return __('Compatibility not declared');
            default:
                return sprintf(__('Compatible with %s.x'), self::minor($coreVersion));
        }
    }

    /**
     * Treats blank strings, a leading "v", and non-version junk (e.g. "n/a") as
     * "not declared" so callers never compare garbage as a version.
     */
    private static function normalize(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = trim(ltrim($value, 'vV'));

        if ($value === '' || !preg_match('/^\d+(\.\d+)*/', $value)) {
            return null;
        }

        return $value;
    }

    /** First two dot-separated segments of a version string, e.g. "6.0.3.beta1" -> "6.0". */
    private static function minor(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }
}
