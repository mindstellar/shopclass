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
 * Which language a listing's text is answered in.
 */
final class ListingText
{
    private function __construct()
    {
    }

    /**
     * The language a listing's text is in, its title and its description, all from one language:
     * the asked one when it has a title there, else the first that has one.
     *
     * @param array<string,array<string,string>> $texts locale => s_title, s_description
     *
     * @return array{0:?string,1:string,2:string} a null locale when no language has a title
     */
    public static function pick(array $texts, string $locale): array
    {
        if (($texts[$locale]['s_title'] ?? '') !== '') {
            return [$locale, (string) $texts[$locale]['s_title'], (string) ($texts[$locale]['s_description'] ?? '')];
        }
        foreach ($texts as $code => $text) {
            if (($text['s_title'] ?? '') !== '') {
                return [(string) $code, (string) $text['s_title'], (string) ($text['s_description'] ?? '')];
            }
        }

        return [null, '', ''];
    }
}
