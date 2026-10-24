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

namespace mindstellar\menu;

use mindstellar\widgets\WidgetConfig;

/**
 * Checks a posted menu and returns the items to store. An item is a category, a page or a
 * link, with a label per language and up to two levels of children below it.
 */
final class MenuItems
{
    public const TYPES     = ['category', 'page', 'link'];
    public const MAX_DEPTH = 3;
    public const MAX_ITEMS = 200;
    public const MAX_LABEL = 100;

    /** @var string[] */
    private array $errors = [];

    private int $count = 0;

    /**
     * @param string[]                $locales     the site's language codes; labels in others are dropped
     * @param \Closure(int):bool      $categoryExists
     * @param \Closure(int):bool      $pageExists
     */
    public function __construct(private array $locales, private \Closure $categoryExists, private \Closure $pageExists)
    {
    }

    /**
     * The clean items, or null when anything is wrong; errors() says what.
     *
     * @param mixed $items
     *
     * @return array<int,array<string,mixed>>|null
     */
    public function clean(mixed $items): ?array
    {
        $this->errors = [];
        $this->count  = 0;
        if (!is_array($items) || !array_is_list($items)) {
            $this->fail(__('The menu could not be read.'));

            return null;
        }
        $clean = $this->level($items, 1);

        return $this->errors() === [] ? $clean : null;
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return array_values(array_unique($this->errors));
    }

    /**
     * A label as plain text: tags and control characters out, spaces folded, cut to MAX_LABEL.
     */
    public static function plain(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $text = strip_tags($value);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, self::MAX_LABEL);
    }

    /**
     * @param array<mixed> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private function level(array $items, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            $this->fail(sprintf(__('A menu can nest items at most %d levels deep.'), self::MAX_DEPTH));

            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (++$this->count > self::MAX_ITEMS) {
                $this->fail(sprintf(__('A menu can hold at most %d items.'), self::MAX_ITEMS));

                return $out;
            }
            $row = is_array($item) ? $this->item($item) : null;
            if ($row === null) {
                continue;
            }
            $children = $item['children'] ?? [];
            if (!is_array($children) || !array_is_list($children)) {
                $this->fail(__('The menu could not be read.'));
                continue;
            }
            if ($children !== []) {
                $kids = $this->level($children, $depth + 1);
                if ($kids !== []) {
                    $row['children'] = $kids;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<mixed> $item
     *
     * @return array<string,mixed>|null
     */
    private function item(array $item): ?array
    {
        $type   = $item['type'] ?? null;
        $labels = $this->labels($item['label'] ?? []);
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            $this->fail(__('A menu item has an unknown type.'));

            return null;
        }
        if ($type === 'link') {
            $url = is_string($item['url'] ?? null) ? WidgetConfig::imageValue($item['url']) : '';
            if ($url === '') {
                $this->fail(__('A link needs an address that starts with http://, https:// or /.'));

                return null;
            }
            if ($labels === []) {
                $this->fail(__('A link needs a label.'));

                return null;
            }

            return ['type' => 'link', 'url' => $url, 'label' => $labels];
        }
        $ref = $item['ref'] ?? null;
        $ref = is_int($ref) || (is_string($ref) && ctype_digit($ref)) ? (int) $ref : 0;
        $ok  = $ref > 0 && ($type === 'category' ? ($this->categoryExists)($ref) : ($this->pageExists)($ref));
        if (!$ok) {
            $this->fail($type === 'category' ? __('A menu item points to a category that does not exist.') : __('A menu item points to a page that does not exist.'));

            return null;
        }
        $row = ['type' => $type, 'ref' => $ref];
        if ($labels !== []) {
            $row['label'] = $labels;
        }

        return $row;
    }

    /**
     * @return array<string,string> locale => label, empty ones left out
     */
    private function labels(mixed $labels): array
    {
        $out = [];
        foreach (is_array($labels) ? $labels : [] as $locale => $label) {
            $text = self::plain($label);
            if ($text !== '' && in_array((string) $locale, $this->locales, true)) {
                $out[(string) $locale] = $text;
            }
        }

        return $out;
    }

    private function fail(string $message): void
    {
        $this->errors[] = $message;
    }
}
