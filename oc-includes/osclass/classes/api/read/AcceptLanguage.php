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

namespace mindstellar\api\read;

/**
 * Picks a locale from an Accept-Language header.
 */
final class AcceptLanguage
{
    /**
     * The locale code the header prefers most, or null for none. "hi-IN" and "hi_IN" match
     * hi_IN; a bare "hi" matches the first hi_* code.
     *
     * @param string[] $codes the locale codes on offer, in order
     */
    public static function best(string $header, array $codes): ?string
    {
        foreach (self::tags($header) as $tag) {
            foreach ($codes as $code) {
                if (strtolower($code) === $tag) {
                    return $code;
                }
            }
            if (!str_contains($tag, '_')) {
                foreach ($codes as $code) {
                    if (str_starts_with(strtolower($code), $tag . '_')) {
                        return $code;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The header's language tags, lower-cased with "_", highest q first; ties keep header order.
     * Tags with q=0, wildcards and junk are dropped.
     *
     * @return string[]
     */
    public static function tags(string $header): array
    {
        $wanted = [];
        foreach (explode(',', $header) as $i => $part) {
            $bits = array_map('trim', explode(';', $part));
            $tag  = strtolower(str_replace('-', '_', $bits[0]));
            $q    = 1.0;
            foreach (array_slice($bits, 1) as $bit) {
                if (preg_match('/^q=([01](?:\.\d{0,3})?)$/i', $bit, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($q > 0 && preg_match('/^[a-z]{2,3}(?:_[a-z0-9]{2,8})*$/', $tag)) {
                $wanted[] = [$q, -$i, $tag];
            }
        }
        rsort($wanted);

        return array_column($wanted, 2);
    }
}
