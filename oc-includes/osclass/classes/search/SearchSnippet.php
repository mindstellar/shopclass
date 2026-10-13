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

namespace mindstellar\search;

/**
 * A short piece of a listing's text with the searched words in <mark>. The result is
 * escaped HTML whose only tags are <mark> and </mark>.
 */
final class SearchSnippet
{
    public const LENGTH = 200;

    /**
     * Core's own snippet: the start of the description, with the pattern's words marked.
     */
    public static function make(string $description, string $pattern, int $length = self::LENGTH): string
    {
        $text = self::plain($description);
        if (mb_strlen($text, 'UTF-8') > $length) {
            $text = rtrim(mb_substr($text, 0, $length, 'UTF-8')) . '…';
        }
        $html  = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $words = array_filter(preg_split('/[\s"()+\-~<>*]+/u', $pattern) ?: [], static fn ($w) => mb_strlen($w, 'UTF-8') > 1);
        if ($words === []) {
            return $html;
        }
        $alternatives = implode('|', array_map(static fn ($w) => preg_quote(htmlspecialchars($w, ENT_QUOTES, 'UTF-8'), '/'), $words));

        // Whole words only, and never inside an entity such as &amp;.
        return (string) preg_replace('/(?<![\pL\pN&#])(' . $alternatives . ')(?![\pL\pN;])/iu', '<mark>$1</mark>', $html);
    }

    /**
     * A snippet from a search engine, made safe: all of it escaped, then <mark> put back.
     * Accepts text the engine escaped already or did not.
     */
    public static function clean(string $html): string
    {
        $out  = '';
        $open = false;
        foreach (preg_split('/(<\/?mark>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            $tag = strtolower($part);
            if ($tag === '<mark>' || $tag === '</mark>') {
                if (($tag === '<mark>') !== $open) {
                    $out .= $tag;
                    $open = !$open;
                }
                continue;
            }
            $out .= htmlspecialchars(self::plain($part, false), ENT_QUOTES, 'UTF-8');
        }

        return $open ? $out . '</mark>' : $out;
    }

    /**
     * Text with no tags or entities. A tag counts as a space, so words either side stay apart.
     */
    private static function plain(string $html, bool $trim = true): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('/<[^>]*>/', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return $trim ? trim($text) : $text;
    }
}
