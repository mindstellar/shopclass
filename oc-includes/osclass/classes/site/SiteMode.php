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
 * What the site is for: classifieds, a shop, or both. Packages declare the modes they
 * support in their manifest.
 *
 * @package mindstellar\site
 */
final class SiteMode
{
    public const CLASSIFIEDS = 'classifieds';
    public const SHOP        = 'shop';
    public const BOTH        = 'both';

    public const ALL = [self::CLASSIFIEDS, self::SHOP, self::BOTH];

    private function __construct()
    {
    }

    /**
     * A stored value as a known mode; anything else is classifieds.
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
            self::BOTH => [self::CLASSIFIEDS, self::SHOP],
            default    => [self::CLASSIFIEDS],
        };
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
