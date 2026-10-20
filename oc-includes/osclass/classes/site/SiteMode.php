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

namespace mindstellar\site;

/**
 * What the site is for: classifieds or a shop. Packages declare the modes they
 * support in their manifest.
 *
 * @package mindstellar\site
 */
final class SiteMode
{
    public const CLASSIFIEDS = 'classifieds';
    public const SHOP        = 'shop';

    public const ALL = [self::CLASSIFIEDS, self::SHOP];

    private function __construct()
    {
    }

    /**
     * A stored value as a known mode; anything else is classifieds. A retired 'both' site let
     * visitors post, so classifieds keeps it working where shop would lock every seller out.
     */
    public static function normalize(string $value): string
    {
        return in_array($value, self::ALL, true) ? $value : self::CLASSIFIEDS;
    }

    /**
     * The package modes a site mode needs.
     *
     * @return list<string>
     */
    public static function needed(string $mode): array
    {
        return match (self::normalize($mode)) {
            self::SHOP => [self::SHOP],
            default    => [self::CLASSIFIEDS],
        };
    }

    /**
     * The mode in words, for a sentence: "shop sites" or "classifieds sites".
     */
    public static function noun(string $mode): string
    {
        return self::normalize($mode) === self::SHOP ? __('shop sites') : __('classifieds sites');
    }

    /**
     * Whether a package that declares $modes says it works with the site mode.
     *
     * @param list<string> $modes
     */
    public static function declares(string $mode, array $modes): bool
    {
        return array_diff(self::needed($mode), $modes) === [];
    }

    /**
     * The packages that do not declare every mode the site mode needs.
     *
     * @param array<string,list<string>> $packages name => modes the package declares
     *
     * @return list<string> the names, in the order given
     */
    public static function lacking(string $mode, array $packages): array
    {
        $needed = self::needed($mode);
        $names  = [];
        foreach ($packages as $name => $modes) {
            if (array_diff($needed, $modes) !== []) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
