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
 * Which core releases a site is offered: stable only, or also release candidates, or also betas.
 * Read from the release tag (6.4.0, 6.4.0.rc1, 6.4.0.beta2); the stable channel also skips GitHub prereleases.
 */
final class ReleaseChannel
{
    public const STABLE = 'stable';
    public const RC     = 'rc';
    public const BETA   = 'beta';

    /**
     * The site's channel. ENABLE_PRERELEASE in config.php still means beta.
     *
     * @return string
     */
    public static function current(): string
    {
        if (defined('ENABLE_PRERELEASE') && ENABLE_PRERELEASE === true) {
            return self::BETA;
        }
        $channel = (string) osc_get_preference('update_channel');

        return in_array($channel, array(self::RC, self::BETA), true) ? $channel : self::STABLE;
    }

    /**
     * Whether a release tag belongs to a channel. Any other tag, such as a .dev build, belongs
     * to none.
     *
     * @param string $channel
     * @param string $tag
     *
     * @return bool
     */
    public static function allows(string $channel, string $tag): bool
    {
        if (!preg_match('/^v?\d+\.\d+\.\d+(?:\.(rc|beta)\d+)?$/i', trim($tag), $m)) {
            return false;
        }
        $kind = strtolower($m[1] ?? '');
        if ($kind === '') {
            return true;
        }

        return $kind === self::RC ? $channel !== self::STABLE : $channel === self::BETA;
    }

    /**
     * The newest release a channel allows, from a GitHub /releases list in any order.
     * With $installed, a release that needs a newer site first (see updateFrom()) is skipped.
     *
     * @param array<int,mixed> $releases
     * @param string           $channel
     * @param string           $installed the site's version, or '' to skip that rule
     *
     * @return array<string,mixed>|null
     */
    public static function pick(array $releases, string $channel, string $installed = ''): ?array
    {
        $best = null;
        foreach (self::offered($releases, $channel) as $release) {
            if (self::needsFirst($release, $installed) === null
                && ($best === null || version_compare(self::version($release), self::version($best), '>'))
            ) {
                $best = $release;
            }
        }

        return $best;
    }

    /**
     * The newest release the channel allows that the site cannot take yet, when it is newer than
     * what pick() gives, so the screen can say which version to update to first.
     *
     * @param array<int,mixed> $releases
     * @param string           $channel
     * @param string           $installed
     *
     * @return array{version:string,from:string}|null
     */
    public static function waiting(array $releases, string $channel, string $installed): ?array
    {
        $picked  = self::pick($releases, $channel, $installed);
        $waiting = null;
        foreach (self::offered($releases, $channel) as $release) {
            $from = self::needsFirst($release, $installed);
            if ($from !== null
                && ($picked === null || version_compare(self::version($release), self::version($picked), '>'))
                && ($waiting === null || version_compare(self::version($release), $waiting['version'], '>'))
            ) {
                $waiting = array('version' => self::version($release), 'from' => $from);
            }
        }

        return $waiting;
    }

    /**
     * The oldest version a release takes an update from, read from the line the release build
     * writes into its notes; '' when there is none.
     *
     * @param array<string,mixed> $release
     *
     * @return string
     */
    public static function updateFrom(array $release): string
    {
        return preg_match('/^Update from (\d+\.\d+\.\d+) or newer\.\s*$/m', (string) ($release['body'] ?? ''), $m) ? $m[1] : '';
    }

    /**
     * Releases in the list that the channel allows: no drafts, no junk, no prereleases on stable.
     *
     * @param array<int,mixed> $releases
     * @param string           $channel
     *
     * @return array<int,array<string,mixed>>
     */
    private static function offered(array $releases, string $channel): array
    {
        return array_values(array_filter($releases, static fn ($release) => is_array($release)
            && empty($release['draft']) && !empty($release['tag_name'])
            && self::allows($channel, (string) $release['tag_name'])
            // GitHub's prerelease flag also keeps a release off the stable channel.
            && !($channel === self::STABLE && !empty($release['prerelease']))));
    }

    /**
     * The version a site must reach before it may take this release, or null when it may now.
     *
     * @param array<string,mixed> $release
     * @param string              $installed '' never blocks
     *
     * @return string|null
     */
    private static function needsFirst(array $release, string $installed): ?string
    {
        $from = self::updateFrom($release);

        return $installed !== '' && $from !== '' && version_compare($installed, $from, '<') ? $from : null;
    }

    /**
     * @param array<string,mixed> $release
     *
     * @return string
     */
    public static function version(array $release): string
    {
        return ltrim(trim((string) $release['tag_name']), 'vV');
    }
}
