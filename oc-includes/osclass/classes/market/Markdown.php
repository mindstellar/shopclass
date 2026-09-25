<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\market;

/**
 * A package README or CHANGELOG as safe HTML: a small Markdown subset, everything escaped
 * first, links and images limited to http(s). Shared by the catalog builder, which runs on
 * a bare `php` with this file beside it, and by the admin's detail dialog.
 */
final class Markdown
{
    /**
     * @param string|null $markdown
     *
     * @return string
     */
    public static function toHtml(?string $markdown): string
    {
        return self::renderMarkdownSafe($markdown);
    }

    private static function sanitizeLinkUrl(string $url, bool $isImage): ?string
    {
        $clean = trim(preg_replace('/[\x00-\x20]/', '', $url) ?? '');
        if ($clean === '') {
            return null;
        }
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $clean)) {
            return $clean; // No scheme -- a relative/anchor link. Not a protocol-injection vector.
        }
        $scheme = strtolower(explode(':', $clean, 2)[0]);
        $allowed = $isImage ? ['http', 'https'] : ['http', 'https', 'mailto'];

        return in_array($scheme, $allowed, true) ? $clean : null;
    }

    private static function renderInlineMarkdown(string $escaped): string
    {
        // Code spans first, stashed behind placeholders so later patterns cannot reach inside them.
        $codeSpans = [];
        $escaped = preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codeSpans) {
            $token = "\x01CODE" . count($codeSpans) . "\x02";
            $codeSpans[$token] = '<code>' . $m[1] . '</code>';

            return $token;
        }, $escaped);

        $escaped = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function ($m) {
            $url = self::sanitizeLinkUrl($m[2], true);

            return $url === null ? $m[1] : '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . $m[1] . '" loading="lazy">';
        }, $escaped);

        $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function ($m) {
            $url = self::sanitizeLinkUrl($m[2], false);

            return $url === null ? $m[1] : '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" rel="nofollow noopener noreferrer" target="_blank">' . $m[1] . '</a>';
        }, $escaped);

        $escaped = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '<strong>$1$2</strong>', $escaped);
        $escaped = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?!\*)|(?<![_\w])_(?!\s)(.+?)(?<!\s)_(?!_)/', '<em>$1$2</em>', $escaped);

        return strtr($escaped, $codeSpans);
    }

    private static function escapeMd(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Nesting levels beyond this flatten into the innermost list rather than recursing further. */
    private const MAX_LIST_DEPTH = 8;

    /**
     * Whether `$line` opens a list item, and at what indentation. Tabs count as two spaces so
     * tab- and space-indented sources compare on the same scale.
     *
     * @return array{indent:int, ordered:bool, content:string}|null
     */
    private static function matchListItem(string $line): ?array
    {
        if (preg_match('/^([ \t]*)([-*+])\s+(.*)$/', $line, $m)) {
            return ['indent' => strlen(str_replace("\t", '  ', $m[1])), 'ordered' => false, 'content' => $m[3]];
        }
        if (preg_match('/^([ \t]*)\d+\.\s+(.*)$/', $line, $m)) {
            return ['indent' => strlen(str_replace("\t", '  ', $m[1])), 'ordered' => true, 'content' => $m[2]];
        }

        return null;
    }

    /** A list item's own text: task-list checkbox (rendered as an inert symbol) plus inline markdown. */
    private static function renderListItemContent(string $raw): string
    {
        if (preg_match('/^\[([ xX])\]\s+(.*)$/', $raw, $m)) {
            $symbol = strtolower($m[1]) === 'x' ? "\u{2611}" : "\u{2610}"; // checked / empty box, never an interactive control
            return $symbol . ' ' . self::renderInlineMarkdown(self::escapeMd($m[2]));
        }

        return self::renderInlineMarkdown(self::escapeMd($raw));
    }

    /**
     * Consumes a run of same-type, same-indent list items starting at `$lines[$i]`, recursing into
     * a child `<ul>`/`<ol>` for any more-indented item that immediately follows. `$depth` is capped
     * at MAX_LIST_DEPTH: beyond that, deeper items are flattened into this list instead of nesting
     * further, so adversarial indentation cannot recurse without bound.
     */
    private static function renderListBlock(array $lines, int &$i, int $n, int $indent, int $depth): string
    {
        $ordered = self::matchListItem($lines[$i])['ordered'];
        $atCap = $depth >= self::MAX_LIST_DEPTH;
        $items = [];

        while ($i < $n) {
            $item = self::matchListItem($lines[$i]);
            if ($item === null || $item['ordered'] !== $ordered || $item['indent'] < $indent) {
                break;
            }
            if ($item['indent'] > $indent) {
                if (!$atCap) {
                    break; // Handled by the recursive call below, on the item that precedes this one.
                }
                $items[] = '<li>' . self::renderListItemContent($item['content']) . '</li>';
                $i++;
                continue;
            }

            $content = self::renderListItemContent($item['content']);
            $i++;

            $nested = '';
            $next = $i < $n ? self::matchListItem($lines[$i]) : null;
            if ($next !== null && $next['indent'] > $indent && !$atCap) {
                $nested = self::renderListBlock($lines, $i, $n, $next['indent'], $depth + 1);
            }

            $items[] = '<li>' . $content . $nested . '</li>';
        }

        $tag = $ordered ? 'ol' : 'ul';

        return "<{$tag}>" . implode('', $items) . "</{$tag}>";
    }

    /**
     * Splits a table row on unescaped `|`, dropping one optional leading/trailing empty cell
     * produced by the row's own outer pipes, and turning `\|` back into a literal pipe.
     *
     * @return string[]
     */
    private static function splitTableRow(string $row): array
    {
        $cells = preg_split('/(?<!\\\\)\|/', trim($row));
        if ($cells === false) {
            return [];
        }
        if ($cells !== [] && trim($cells[0]) === '') {
            array_shift($cells);
        }
        if ($cells !== [] && trim((string) end($cells)) === '') {
            array_pop($cells);
        }

        return array_map(static fn (string $c): string => str_replace('\\|', '|', trim($c)), $cells);
    }

    /** Whether `$line` is a GFM table delimiter row (`|---|:---:|---:|`, outer pipes optional). */
    private static function isTableDelimiterRow(string $line): bool
    {
        if (trim($line) === '' || !str_contains($line, '-')) {
            return false;
        }
        $cells = self::splitTableRow($line);
        if ($cells === []) {
            return false;
        }
        foreach ($cells as $cell) {
            if (!preg_match('/^:?-+:?$/', $cell)) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] 'left'|'center'|'right'|'' per delimiter cell, indexed like the header. */
    private static function parseTableAlignments(array $delimCells): array
    {
        return array_map(static function (string $cell): string {
            $left = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');
            if ($left && $right) {
                return 'center';
            }

            return $right ? 'right' : ($left ? 'left' : '');
        }, $delimCells);
    }

    /**
     * Pads a short row with empty cells and folds an overrun into the last column (joined by a
     * space) rather than dropping it, so a ragged row never loses content or breaks the table shape.
     *
     * @return string[]
     */
    private static function normalizeTableRow(array $cells, int $colCount): array
    {
        if ($colCount <= 0) {
            return [];
        }
        if (count($cells) <= $colCount) {
            return array_pad($cells, $colCount, '');
        }
        $head = array_slice($cells, 0, $colCount - 1);
        $head[] = implode(' ', array_slice($cells, $colCount - 1));

        return $head;
    }

    /** Bootstrap alignment utility class for a column, or '' — never a `style=` attribute. */
    private static function tableAlignClass(array $aligns, int $col): string
    {
        return match ($aligns[$col] ?? '') {
            'center' => ' class="text-center"',
            'right'  => ' class="text-end"',
            default  => '',
        };
    }

    private static function renderTableRow(array $cells, int $colCount, array $aligns, string $cellTag): string
    {
        $out = '<tr>';
        foreach (self::normalizeTableRow($cells, $colCount) as $idx => $cell) {
            $out .= '<' . $cellTag . self::tableAlignClass($aligns, $idx) . '>' . self::renderInlineMarkdown(self::escapeMd($cell)) . '</' . $cellTag . '>';
        }

        return $out . '</tr>';
    }

    private static function renderMarkdownSafe(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }
        $lines = preg_split('/\r\n|\r|\n/', $markdown);
        $html = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Fenced code block.
            if (preg_match('/^```/', $line)) {
                $buf = [];
                $i++;
                while ($i < $n && !preg_match('/^```/', $lines[$i])) {
                    $buf[] = $lines[$i];
                    $i++;
                }
                $i++; // closing fence
                $html[] = '<pre><code>' . self::escapeMd(implode("\n", $buf)) . '</code></pre>';
                continue;
            }

            // GFM pipe table: a header row followed immediately by a delimiter row. Excludes a
            // heading or blockquote line that happens to contain a literal '|' -- those take
            // priority so e.g. a heading directly above an unrelated `---` rule isn't swallowed.
            if (str_contains($line, '|') && !preg_match('/^(#{1,6}\s|>)/', $line)
                && $i + 1 < $n && self::isTableDelimiterRow($lines[$i + 1])) {
                $headerCells = self::splitTableRow($line);
                $aligns = self::parseTableAlignments(self::splitTableRow($lines[$i + 1]));
                $colCount = count($headerCells);
                $i += 2;
                $bodyHtml = '';
                while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $bodyHtml .= self::renderTableRow(self::splitTableRow($lines[$i]), $colCount, $aligns, 'td');
                    $i++;
                }
                $html[] = '<table><thead>' . self::renderTableRow($headerCells, $colCount, $aligns, 'th') . '</thead><tbody>' . $bodyHtml . '</tbody></table>';
                continue;
            }

            // ATX heading.
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*$/', $line, $m)) {
                $level = strlen($m[1]);
                $html[] = "<h{$level}>" . self::renderInlineMarkdown(self::escapeMd($m[2])) . "</h{$level}>";
                $i++;
                continue;
            }

            // Horizontal rule.
            if (preg_match('/^\s*([-*_])\s*(?:\1\s*){2,}$/', $line)) {
                $html[] = '<hr>';
                $i++;
                continue;
            }

            // Blockquote.
            if (preg_match('/^>\s?(.*)$/', $line)) {
                $buf = [];
                while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $m)) {
                    $buf[] = $m[1];
                    $i++;
                }
                $html[] = '<blockquote><p>' . self::renderInlineMarkdown(self::escapeMd(implode(' ', $buf))) . '</p></blockquote>';
                continue;
            }

            // List (ordered or unordered), nesting into a child list per indentation level.
            $listItem = self::matchListItem($line);
            if ($listItem !== null) {
                $html[] = self::renderListBlock($lines, $i, $n, $listItem['indent'], 1);
                continue;
            }

            // Paragraph — consecutive non-blank, non-special lines.
            $buf = [];
            while ($i < $n && trim($lines[$i]) !== ''
                && !preg_match('/^(```|#{1,6}\s|>|[ \t]*[-*+]\s|[ \t]*\d+\.\s|\s*([-*_])\s*(?:\2\s*){2,}$)/', $lines[$i])
                && !(str_contains($lines[$i], '|') && $i + 1 < $n && self::isTableDelimiterRow($lines[$i + 1]))) {
                $buf[] = $lines[$i];
                $i++;
            }
            if ($buf !== []) {
                $html[] = '<p>' . self::renderInlineMarkdown(self::escapeMd(implode(' ', $buf))) . '</p>';
            } else {
                $i++; // Guard against an unmatched special line looping forever.
            }
        }

        return implode("\n", $html);
    }
}
