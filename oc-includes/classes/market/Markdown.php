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
 * A package README or CHANGELOG as HTML: a small Markdown subset, everything escaped first,
 * links and images limited to http(s). Shared by the catalog builder, which runs on a bare
 * `php` with this file beside it, and by the admin's detail dialog. The markup is not
 * guaranteed well-formed, so pass it through an HTML purifier before showing it.
 */
final class Markdown
{
    /** Nesting levels beyond this flatten into the innermost list rather than recursing further. */
    private const MAX_LIST_DEPTH = 8;

    /** Blockquotes nested deeper than this render their text flat. */
    private const MAX_QUOTE_DEPTH = 4;

    /** Bytes of one paragraph and of one document that are rendered, so crafted input cannot stall it. */
    private const MAX_INLINE = 20000;
    private const MAX_DOCUMENT = 524288;

    /** Where a relative link or image points, when the caller gave one. */
    private static ?string $base = null;

    private static int $quoteDepth = 0;

    /**
     * @param string|null $markdown
     * @param string|null $baseUrl  an http(s) folder URL, ending in '/', that relative links
     *                              and images resolve against; null leaves them as written
     *
     * @return string
     */
    public static function toHtml(?string $markdown, ?string $baseUrl = null): string
    {
        $previous   = self::$base;
        self::$base = $baseUrl !== null && preg_match('#^https?://[^\s]+/$#i', $baseUrl) === 1 ? $baseUrl : null;
        try {
            return self::renderMarkdownSafe($markdown);
        } finally {
            self::$base = $previous;
        }
    }

    private static function sanitizeLinkUrl(string $url, bool $isImage): ?string
    {
        $clean = trim(preg_replace('/[\x00-\x20]/', '', $url) ?? '');
        if ($clean === '') {
            return null;
        }
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $clean)) {
            // A relative/anchor link: not a protocol-injection vector.
            return self::$base !== null ? (self::resolveInPackage($clean, self::$base) ?? $clean) : $clean;
        }
        $scheme = strtolower(explode(':', $clean, 2)[0]);
        $allowed = $isImage ? ['http', 'https'] : ['http', 'https', 'mailto'];

        return in_array($scheme, $allowed, true) ? $clean : null;
    }

    /**
     * A relative path inside a package, as a URL under `$base`; null for anything else: a
     * scheme, an absolute path, an anchor, or a path that climbs out with `..`.
     */
    public static function resolveInPackage(string $url, string $base): ?string
    {
        if ($url === '' || preg_match('#^([a-z][a-z0-9+.-]*:|/|\\\\|\#)#i', $url) === 1
            || preg_match('#(^|[/\\\\])(\.|%2e){2}([/\\\\]|$)#i', $url) === 1
        ) {
            return null;
        }

        return $base . preg_replace('#^(\./)+#', '', $url);
    }

    /** A link's or image's escaped URL and title, as the attributes they become. */
    private static function linkAttrs(string $escapedUrl, string $escapedTitle, bool $isImage): ?string
    {
        $url = self::sanitizeLinkUrl(html_entity_decode($escapedUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $isImage);
        if ($url === null) {
            return null;
        }

        $title = str_replace("\x03", ' ', preg_replace('/\x01\d+\x02/', '', $escapedTitle));

        return ($isImage ? 'src' : 'href') . '="' . htmlspecialchars($url, ENT_QUOTES) . '"'
            . ($title !== '' ? ' title="' . $title . '"' : '');
    }

    private static function renderInlineMarkdown(string $escaped): string
    {
        $escaped = self::cut($escaped, self::MAX_INLINE);
        // Anything already rendered is stashed behind a placeholder, so later patterns cannot
        // reach inside a code span, a URL or a tag.
        $stash = [];
        $keep = static function (string $html) use (&$stash): string {
            $token = "\x01" . count($stash) . "\x02";
            $stash[$token] = $html;

            return $token;
        };

        $escaped = preg_replace_callback('/`([^`]+)`/', static fn ($m) => $keep('<code>' . $m[1] . '</code>'), $escaped);

        // A backslash before punctuation writes that character as itself.
        $escaped = preg_replace_callback(
            '/\\\\(&(?:lt|gt|amp|quot|#039);|[!#$%()*+,\-.\/:;=?@\[\]\\\\^_`{|}~])/',
            static fn ($m) => $keep($m[1]),
            $escaped
        );

        $title = '(?:\s+&quot;((?:(?!&quot;).)*)&quot;)?';
        $escaped = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)' . $title . '\)/', static function ($m) use ($keep) {
            $attrs = self::linkAttrs($m[2], $m[3] ?? '', true);

            return $attrs === null ? $m[1] : $keep('<img ' . $attrs . ' alt="' . str_replace("\x03", ' ', preg_replace('/\x01\d+\x02/', '', $m[1])) . '" loading="lazy">');
        }, $escaped);

        $escaped = preg_replace_callback('/\[((?:[^\[\]]|\x01\d+\x02)+)\]\(([^)\s]+)' . $title . '\)/', static function ($m) use ($keep) {
            $attrs = self::linkAttrs($m[2], $m[3] ?? '', false);

            return $attrs === null ? $m[1] : $keep('<a ' . $attrs . ' rel="nofollow noopener noreferrer" target="_blank">' . self::emphasis($m[1]) . '</a>');
        }, $escaped);

        // <https://…> and bare http(s) addresses become links.
        $escaped = preg_replace_callback('/&lt;((?:https?|mailto):[^\s]+?)&gt;/i', static function ($m) use ($keep) {
            $attrs = self::linkAttrs($m[1], '', false);

            return $attrs === null ? $m[0] : $keep('<a ' . $attrs . ' rel="nofollow noopener noreferrer" target="_blank">' . $m[1] . '</a>');
        }, $escaped);
        $escaped = preg_replace_callback('/(?<![\w\/"=;])https?:\/\/[^\s\x01]{1,2048}/i', static function ($m) use ($keep) {
            $url = $m[0];
            $tail = '';
            while ($url !== '') {
                $cut = 0;
                foreach (['&gt;', '&lt;', '&quot;', '&#039;'] as $entity) {
                    if (str_ends_with($url, $entity)) {
                        $cut = strlen($entity);
                    }
                }
                if ($cut === 0 && str_contains('.,;:!?)]*_', substr($url, -1))) {
                    $cut = 1;
                }
                if ($cut === 0) {
                    break;
                }
                $tail = substr($url, -$cut) . $tail;
                $url = substr($url, 0, -$cut);
            }
            $attrs = self::linkAttrs($url, '', false);

            return ($attrs === null ? $url : $keep('<a ' . $attrs . ' rel="nofollow noopener noreferrer" target="_blank">' . $url . '</a>')) . $tail;
        }, $escaped);

        $escaped = self::emphasis($escaped);

        // Stashed pieces can hold other stashed pieces, so unwrap until none is left.
        for ($pass = 0; $pass < 4 && str_contains($escaped, "\x01"); $pass++) {
            $escaped = strtr($escaped, $stash);
        }

        return str_replace("\x03", '<br>', $escaped);
    }

    /** The first `$max` bytes, never ending inside a UTF-8 character or an HTML entity. */
    private static function cut(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return preg_replace(['/[\xC0-\xFF][\x80-\xBF]*$/', '/&[#\w]*$/'], '', substr($s, 0, $max)) ?? '';
    }

    /** Bold, italic and struck-through text. */
    private static function emphasis(string $s): string
    {
        $s = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '<strong>$1$2</strong>', $s);
        $s = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?!\*)|(?<![_\w])_(?!\s)(.+?)(?<!\s)_(?!_)/', '<em>$1$2</em>', $s);

        return preg_replace('/~~(?!\s)(.+?)(?<!\s)~~/', '<del>$1</del>', $s);
    }

    private static function escapeMd(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Lines of text joined for one paragraph: a line ending in two spaces or a backslash
     * keeps its break.
     *
     * @param string[] $lines
     */
    private static function joinLines(array $lines): string
    {
        $out = '';
        foreach ($lines as $idx => $line) {
            if ($idx > 0) {
                $out .= preg_match('/( {2,}|\\\\)$/', $lines[$idx - 1]) === 1 ? "\x03" : ' ';
            }
            $out .= $idx < count($lines) - 1 ? preg_replace('/( {2,}|\\\\)$/', '', $line) : $line;
        }

        return trim($out);
    }

    /** @param string[] $lines */
    private static function paragraph(array $lines): string
    {
        return '<p>' . self::renderInlineMarkdown(self::escapeMd(self::joinLines($lines))) . '</p>';
    }

    /**
     * Whether `$line` opens a list item, and at what indentation. Tabs count as two spaces so
     * tab- and space-indented sources compare on the same scale.
     *
     * @return array{indent:int, ordered:bool, start:int, content:string}|null
     */
    private static function matchListItem(string $line): ?array
    {
        if (preg_match('/^([ \t]*)([-*+])\s+(.*)$/', $line, $m) && !self::isRule($line)) {
            return ['indent' => strlen(str_replace("\t", '  ', $m[1])), 'ordered' => false, 'start' => 1, 'content' => $m[3]];
        }
        if (preg_match('/^([ \t]*)(\d{1,9})[.)]\s+(.*)$/', $line, $m)) {
            return ['indent' => strlen(str_replace("\t", '  ', $m[1])), 'ordered' => true, 'start' => (int) $m[2], 'content' => $m[3]];
        }

        return null;
    }

    private static function isRule(string $line): bool
    {
        return preg_match('/^\s{0,3}([-*_])\s*(?:\1\s*){2,}$/', $line) === 1;
    }

    private static function isFence(string $line): bool
    {
        return preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line) === 1;
    }

    private static function indentOf(string $line): int
    {
        preg_match('/^[ \t]*/', $line, $m);

        return strlen(str_replace("\t", '  ', $m[0]));
    }

    /** Whether a line starts a block of its own, so it cannot continue a paragraph. */
    private static function startsBlock(string $line): bool
    {
        return self::isFence($line) || self::isRule($line) || self::matchListItem($line) !== null
            || preg_match('/^\s{0,3}(#{1,6}\s|>)/', $line) === 1;
    }

    /**
     * A fenced code block starting at `$lines[$i]`, which it moves past the closing fence.
     *
     * @param string[] $lines
     */
    private static function renderFence(array $lines, int &$i, int $n): string
    {
        preg_match('/^(\s*)(`{3,}|~{3,})/', $lines[$i], $open);
        $strip = strlen($open[1]);
        $buf = [];
        $i++;
        while ($i < $n && preg_match('/^\s*' . preg_quote($open[2][0], '/') . '{' . strlen($open[2]) . ',}\s*$/', $lines[$i]) !== 1) {
            $buf[] = preg_replace('/^ {0,' . $strip . '}/', '', $lines[$i]);
            $i++;
        }
        $i++; // closing fence

        return '<pre><code>' . self::escapeMd(implode("\n", $buf)) . '</code></pre>';
    }

    /**
     * A list item's own text: a task-list checkbox (an inert symbol), then its first
     * paragraph, then any further paragraphs or code the item holds.
     *
     * @param string[] $parts the first paragraph's text, then each later block's markup
     */
    private static function renderListItemContent(string $first, array $parts): string
    {
        $prefix = '';
        if (preg_match('/^\[([ xX])\]\s+(.*)$/s', $first, $m)) {
            $prefix = (strtolower($m[1]) === 'x' ? "\u{2611}" : "\u{2610}") . ' '; // never an interactive control
            $first = $m[2];
        }
        $html = $prefix . self::renderInlineMarkdown(self::escapeMd($first));
        foreach ($parts as $part) {
            $html .= $part;
        }

        return $html;
    }

    /**
     * Consumes a run of same-type, same-indent list items, each with the text, code and
     * sublists under it. Past MAX_LIST_DEPTH deeper items flatten into this list.
     *
     * @param string[] $lines
     */
    private static function renderListBlock(array $lines, int &$i, int $n, int $indent, int $depth): string
    {
        $firstItem = self::matchListItem($lines[$i]);
        $ordered = $firstItem['ordered'];
        $atCap = $depth >= self::MAX_LIST_DEPTH;
        $items = [];

        while ($i < $n) {
            $item = self::matchListItem($lines[$i]);
            if ($item === null || $item['ordered'] !== $ordered || $item['indent'] < $indent) {
                break;
            }
            if ($item['indent'] > $indent && !$atCap) {
                break; // Handled by the recursive call below, on the item that precedes this one.
            }
            $i++;

            $text = [$item['content']];
            $parts = [];
            $para = null;
            while ($i < $n) {
                $line = $lines[$i];
                if (trim($line) === '') {
                    // A blank line ends the item unless what follows is indented under it.
                    $j = $i;
                    while ($j < $n && trim($lines[$j]) === '') {
                        $j++;
                    }
                    if ($j >= $n || self::indentOf($lines[$j]) <= $indent || self::matchListItem($lines[$j]) !== null) {
                        break;
                    }
                    if ($para !== null) {
                        $parts[] = self::paragraph($para);
                    }
                    $para = [];
                    $i = $j;
                    continue;
                }
                $next = self::matchListItem($line);
                if ($next !== null) {
                    if ($next['indent'] > $indent && !$atCap) {
                        if ($para !== null && $para !== []) {
                            $parts[] = self::paragraph($para);
                            $para = null;
                        }
                        $parts[] = self::renderListBlock($lines, $i, $n, $next['indent'], $depth + 1);
                        continue;
                    }
                    break;
                }
                if (self::isFence($line) && self::indentOf($line) > $indent) {
                    if ($para !== null && $para !== []) {
                        $parts[] = self::paragraph($para);
                    }
                    $para = null;
                    $parts[] = self::renderFence($lines, $i, $n);
                    continue;
                }
                if (self::indentOf($line) <= $indent && self::startsBlock($line)) {
                    break;
                }
                // Wrapped text: under the item, or a lazy line straight after it.
                if ($para === null) {
                    if ($parts !== []) {
                        break;
                    }
                    $text[] = trim($line);
                } else {
                    $para[] = trim($line);
                }
                $i++;
            }
            if ($para !== null && $para !== []) {
                $parts[] = self::paragraph($para);
            }

            $items[] = '<li>' . self::renderListItemContent(self::joinLines($text), $parts) . '</li>';

            // A blank line between two items of this list does not end it.
            $j = $i;
            while ($j < $n && trim($lines[$j]) === '') {
                $j++;
            }
            $after = $j < $n ? self::matchListItem($lines[$j]) : null;
            if ($j > $i && $after !== null && $after['ordered'] === $ordered && $after['indent'] === $indent) {
                $i = $j;
            }
        }

        $tag = $ordered ? 'ol' : 'ul';
        $start = $ordered && $firstItem['start'] !== 1 ? ' start="' . $firstItem['start'] . '"' : '';

        return "<{$tag}{$start}>" . implode('', $items) . "</{$tag}>";
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
        // The renderer's own placeholder bytes are never text.
        $lines = preg_split('/\r\n|\r|\n/', str_replace(["\x01", "\x02", "\x03"], '', self::cut($markdown, self::MAX_DOCUMENT)));
        $html = [];
        $i = 0;
        $n = count($lines);

        // A front-matter block at the very top is settings for a site generator, not text.
        if (self::$quoteDepth === 0 && $n > 1 && trim($lines[0]) === '---') {
            for ($j = 1; $j < $n; $j++) {
                if (trim($lines[$j]) === '---') {
                    $i = $j + 1;
                    break;
                }
            }
        }

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // An HTML comment is a note to the README's editors, not text.
            if (preg_match('/^\s*<!--/', $line)) {
                while ($i < $n && !str_contains($lines[$i], '-->')) {
                    $i++;
                }
                $i++;
                continue;
            }

            if (self::isFence($line)) {
                $html[] = self::renderFence($lines, $i, $n);
                continue;
            }

            // GFM pipe table: a header row followed immediately by a delimiter row. Excludes a
            // heading or blockquote line that happens to contain a literal '|' -- those take
            // priority so e.g. a heading directly above an unrelated `---` rule isn't swallowed.
            if (str_contains($line, '|') && !preg_match('/^(#{1,6}\s|>)/', $line)
                && $i + 1 < $n && self::isTableDelimiterRow($lines[$i + 1])) {
                $headerCells = self::splitTableRow($line);
                $aligns = self::parseTableAlignments(self::splitTableRow($lines[$i + 1]));
                $colCount = max(count($headerCells), count($aligns));
                $i += 2;
                $bodyHtml = '';
                while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $bodyHtml .= self::renderTableRow(self::splitTableRow($lines[$i]), $colCount, $aligns, 'td');
                    $i++;
                }
                // A header row of empty cells is a layout table's placeholder: nothing to show.
                $head = implode('', $headerCells) === '' ? '' : '<thead>' . self::renderTableRow($headerCells, $colCount, $aligns, 'th') . '</thead>';
                $html[] = '<table>' . $head . '<tbody>' . $bodyHtml . '</tbody></table>';
                continue;
            }

            // ATX heading.
            if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)(?:\s+#+)?\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $html[] = "<h{$level}>" . self::renderInlineMarkdown(self::escapeMd($m[2])) . "</h{$level}>";
                $i++;
                continue;
            }

            if (self::isRule($line)) {
                $html[] = '<hr>';
                $i++;
                continue;
            }

            // Blockquote: its lines, without the marker, are Markdown of their own.
            if (preg_match('/^\s{0,3}>/', $line)) {
                $buf = [];
                while ($i < $n && trim($lines[$i]) !== '' && (preg_match('/^\s{0,3}>\s?(.*)$/', $lines[$i], $m) || $buf !== [])) {
                    $buf[] = preg_match('/^\s{0,3}>\s?(.*)$/', $lines[$i], $m) ? $m[1] : $lines[$i];
                    $i++;
                }
                if (self::$quoteDepth < self::MAX_QUOTE_DEPTH) {
                    self::$quoteDepth++;
                    try {
                        $inner = self::renderMarkdownSafe(implode("\n", $buf));
                    } finally {
                        self::$quoteDepth--;
                    }
                } else {
                    $inner = self::paragraph($buf);
                }
                $html[] = '<blockquote>' . $inner . '</blockquote>';
                continue;
            }

            // List (ordered or unordered), nesting into a child list per indentation level.
            $listItem = self::matchListItem($line);
            if ($listItem !== null) {
                $html[] = self::renderListBlock($lines, $i, $n, $listItem['indent'], 1);
                continue;
            }

            // Paragraph — consecutive lines that start no other block. A line of `=` or `-`
            // under it makes it a heading instead.
            $buf = [];
            $setext = 0;
            while ($i < $n && trim($lines[$i]) !== '') {
                if ($buf !== [] && preg_match('/^\s{0,3}(=+|-+)\s*$/', $lines[$i], $m)) {
                    $setext = $m[1][0] === '=' ? 1 : 2;
                    $i++;
                    break;
                }
                if ($buf !== [] && (self::startsBlock($lines[$i])
                    || (str_contains($lines[$i], '|') && $i + 1 < $n && self::isTableDelimiterRow($lines[$i + 1])))) {
                    break;
                }
                $buf[] = $lines[$i];
                $i++;
            }
            $text = self::renderInlineMarkdown(self::escapeMd(self::joinLines($buf)));
            $html[] = $setext > 0 ? "<h{$setext}>{$text}</h{$setext}>" : '<p>' . $text . '</p>';
        }

        return implode("\n", $html);
    }
}
