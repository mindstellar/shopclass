<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\market;

/**
 * A package version constraint: `>=1.2`, `<2`, `=1.0`, `^1.2`, `~1.2`, or a bare version,
 * read as a minimum. Up to four numeric parts and a `-suffix` such as `-beta1`.
 */
final class VersionConstraint
{
    private const PATTERN = '/^(>=|<=|>|<|=|\^|~)?\s*(\d+(?:\.\d+){0,3})(-[0-9A-Za-z.]+)?$/';

    /**
     * Whether $constraint is one this class reads. An empty one is valid and accepts any version.
     */
    public static function isValid(string $constraint): bool
    {
        $constraint = trim($constraint);

        return $constraint === '' || preg_match(self::PATTERN, $constraint) === 1;
    }

    /**
     * Whether $version meets $constraint. An empty constraint accepts any version; one that
     * isValid() refuses accepts none.
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            return true;
        }
        if ($version === '' || preg_match(self::PATTERN, $constraint, $m) !== 1) {
            return false;
        }
        $op      = $m[1];
        $base    = self::pad($m[2]) . ($m[3] ?? '');
        $version = self::pad($version);

        switch ($op) {
            case '':
            case '>=':
                return version_compare($version, $base, '>=');
            case '=':
                return version_compare($version, $base, '==');
            case '>':
            case '<':
            case '<=':
                return version_compare($version, $base, $op);
            case '^':
                $parts = array_map('intval', explode('.', $m[2]));
                $upper = $parts[0] > 0 ? ($parts[0] + 1) . '.0.0' : '0.' . (($parts[1] ?? 0) + 1) . '.0';

                return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
            default:
                $parts = array_map('intval', explode('.', $m[2]));
                if (count($parts) > 1) {
                    array_pop($parts);
                }
                $parts[count($parts) - 1]++;
                $upper = self::pad(implode('.', $parts));

                return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }
    }

    /**
     * A version with at least three numeric parts, so 1.0 and 1.0.0 compare equal.
     */
    private static function pad(string $version): string
    {
        if (preg_match('/^(\d+(?:\.\d+)*)(.*)$/', trim($version), $m) !== 1) {
            return $version;
        }
        $parts = explode('.', $m[1]);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', $parts) . $m[2];
    }
}
