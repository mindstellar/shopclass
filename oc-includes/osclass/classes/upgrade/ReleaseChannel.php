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
 * Read from the release tag (6.4.0, 6.4.0.rc1, 6.4.0.beta2), not from GitHub's prerelease flag.
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
     *
     * @param array<int,mixed> $releases
     * @param string           $channel
     *
     * @return array<string,mixed>|null
     */
    public static function pick(array $releases, string $channel): ?array
    {
        $best = null;
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft']) || empty($release['tag_name'])
                || !self::allows($channel, (string) $release['tag_name'])
            ) {
                continue;
            }
            if ($best === null || version_compare(self::version($release), self::version($best), '>')) {
                $best = $release;
            }
        }

        return $best;
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
