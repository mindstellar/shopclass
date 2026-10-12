<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin;

/**
 * Renders the inline Markdown the changelog uses: **bold**, `code` and [text](url).
 * Text is escaped first; only http(s) and relative links become anchors.
 *
 * @package mindstellar\admin
 */
final class InlineMarkdown
{
    /**
     * @param string $text one line of raw Markdown
     * @param string $base prefix for relative links, e.g. the site root URL
     *
     * @return string HTML
     */
    public static function render(string $text, string $base = ''): string
    {
        $parts = preg_split('/(`[^`]+`)/', osc_esc_html($text), -1, PREG_SPLIT_DELIM_CAPTURE);
        $out   = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $out .= '<code>' . substr($part, 1, -1) . '</code>';
                continue;
            }
            $part = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $part);
            $out .= preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m) use ($base): string {
                if (preg_match('~^https?://~i', $m[2])) {
                    return '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
                }
                if (preg_match('~^(?![a-z][a-z0-9+.-]*:|//)~i', $m[2])) {
                    return '<a href="' . osc_esc_html($base) . $m[2] . '">' . $m[1] . '</a>';
                }

                return $m[0];
            }, $part);
        }

        return $out;
    }
}
