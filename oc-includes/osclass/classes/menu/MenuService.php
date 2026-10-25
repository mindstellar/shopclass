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

use mindstellar\cache\CacheGroup;
use mindstellar\category\CategoryQuery;
use mindstellar\category\CategoryStore;
use mindstellar\pages\PageQuery;
use mindstellar\theme\ThemeSupports;
use mindstellar\utility\Utils;
use Preference;

/**
 * The site's menus, one t_preference row each in the `menus` section. `main` and `footer`
 * always exist: with no saved row they list the top categories and the linked pages.
 * The admin screen, the API and osc_menu() all read and write them here.
 */
final class MenuService
{
    public const SECTION   = 'menus';
    public const CORE      = ['main', 'footer'];
    public const MAX_MENUS = 20;
    public const ID        = '/^[a-z][a-z0-9_-]{0,31}$/';

    private static ?self $instance = null;

    /**
     * @param \Closure(int):string                    $categoryUrl a category's search URL
     * @param \Closure(int,string,string,string):string $pageUrl   a page's URL from id, slug, title and locale ('' for the default)
     * @param string[]                                $locales     the site's language codes
     */
    public function __construct(
        private Preference $preferences,
        private string $defaultLocale,
        private array $locales,
        private \Closure $categoryUrl,
        private \Closure $pageUrl,
    ) {
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self(
            Preference::getInstance(),
            (string) osc_language(),
            array_map(static fn (array $l): string => (string) $l['pk_c_code'], (array) \OSCLocale::getInstance()->listAllEnabled()),
            static fn (int $id): string => (string) osc_search_url(['sCategory' => $id]),
            \Closure::fromCallable([PageQuery::class, 'siteUrl'])
        );
    }

    /**
     * The saved menus' ids, sorted.
     *
     * @return string[]
     */
    public function ids(): array
    {
        $ids = [];
        foreach ($this->preferences->getSection(self::SECTION) as $id => $value) {
            if ($value !== '' && preg_match(self::ID, (string) $id) === 1) {
                $ids[] = (string) $id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * `main`, `footer`, then every other saved menu.
     *
     * @return string[]
     */
    public function menuIds(): array
    {
        return array_values(array_unique([...self::CORE, ...$this->ids()]));
    }

    /**
     * The places the theme declared with osc_add_theme_support('menus', [id => ['label' => …, 'depth' => 1..3]]).
     * An entry with a bad id, no label or a depth outside 1 to 3 is skipped.
     *
     * @return array<string,array{label:string,depth:int}>
     */
    public function themePlaces(): array
    {
        $raw = ThemeSupports::getInstance()->get('menus');
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id => $place) {
            $id    = (string) $id;
            $label = is_array($place) && is_string($place['label'] ?? null) ? trim($place['label']) : '';
            $depth = is_array($place) && array_key_exists('depth', $place) ? $place['depth'] : MenuItems::MAX_DEPTH;
            if (preg_match(self::ID, $id) !== 1 || $label === '' || !is_int($depth) || $depth < 1 || $depth > MenuItems::MAX_DEPTH) {
                continue;
            }
            $out[$id] = ['label' => $label, 'depth' => $depth];
        }

        return $out;
    }

    /**
     * How many items sit at $depth levels or deeper in a stored menu (level 1 is the top).
     *
     * @param array<mixed> $items
     */
    public static function itemsBeyond(array $items, int $depth, int $level = 1): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $count += ($level > $depth ? 1 : 0) + (is_array($item['children'] ?? null) ? self::itemsBeyond($item['children'], $depth, $level + 1) : 0);
        }

        return $count;
    }

    /**
     * Every menu an owner can edit: the saved ones plus the places the theme declared.
     *
     * @return string[]
     */
    public function editableIds(): array
    {
        return array_values(array_unique([...$this->menuIds(), ...array_keys($this->themePlaces())]));
    }

    /**
     * A saved menu, or null when there is none.
     *
     * @return array{label:string,items:array<int,array<string,mixed>>}|null
     */
    public function load(string $id): ?array
    {
        $raw = $this->preferences->get($id, self::SECTION);
        if ($raw === '' || preg_match(self::ID, $id) !== 1) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['items'] ?? null)) {
            return null;
        }

        return ['label' => is_string($data['label'] ?? null) ? $data['label'] : '', 'items' => $data['items']];
    }

    /**
     * The stored-item form of the automatic `main` or `footer`, for an editor to start from.
     *
     * @return array<int,array<string,mixed>>
     */
    public function automaticItems(string $id): array
    {
        if ($id === 'main') {
            $items = [];
            foreach ($this->categories($this->defaultLocale) as $ref => $row) {
                if (empty($row['fk_i_parent_id'])) {
                    $items[] = ['type' => 'category', 'ref' => $ref];
                }
            }

            return $items;
        }
        if ($id === 'footer') {
            return array_map(static fn (array $page): array => ['type' => 'page', 'ref' => (int) $page['pk_i_id']], (new PageQuery())->linked());
        }

        return [];
    }

    /**
     * Check and store a whole menu. Returns what is wrong; an empty list means it was saved.
     * A null label keeps the saved one. With $create the id must be new.
     *
     * @return string[]
     */
    public function save(string $id, mixed $items, ?string $label = '', bool $create = false): array
    {
        if (preg_match(self::ID, $id) !== 1) {
            return [__('A menu id starts with a letter and has at most 32 lowercase letters, digits, - or _.')];
        }
        if ($create && in_array($id, $this->menuIds(), true)) {
            return [__('A menu with that id already exists.')];
        }
        $label ??= ($this->load($id) ?? ['label' => ''])['label'];
        if ($this->load($id) === null && !in_array($id, self::CORE, true) && count(array_diff($this->ids(), self::CORE)) >= self::MAX_MENUS) {
            return [sprintf(__('A site can keep at most %d menus besides the main and footer menus.'), self::MAX_MENUS)];
        }
        $checker = new MenuItems(
            $this->locales,
            static fn (int $ref): bool => CategoryStore::exists($ref),
            static fn (int $ref): bool => (new PageQuery())->find((string) $ref) !== null
        );
        $clean = $checker->clean($items);
        if ($clean === null) {
            return $checker->errors();
        }
        $value = json_encode(['label' => MenuItems::plain($label), 'items' => $clean], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($value === false || !$this->preferences->replace($id, $value, self::SECTION, 'STRING')) {
            return [__('The menu could not be saved.')];
        }
        osc_purge_page_cache('menu');

        return [];
    }

    /**
     * Remove a saved menu; `main` and `footer` go back to the automatic ones.
     */
    public function delete(string $id): bool
    {
        if (preg_match(self::ID, $id) !== 1) {
            return false;
        }
        $deleted = $this->preferences->delete(['s_name' => $id, 's_section' => self::SECTION]);
        // The in-request map keeps deleted keys, so blank it here.
        $this->preferences->set($id, '', self::SECTION);
        osc_purge_page_cache('menu');

        return $deleted !== false;
    }

    /**
     * What the admin editor offers to add, names in $locale: the enabled categories in tree
     * order with their parent, and the listed pages.
     *
     * @return array{categories:array<int,array{id:int,parent:int,name:string}>,pages:array<int,array{id:int,name:string}>}
     */
    public function pickerItems(string $locale): array
    {
        $categories = [];
        foreach ($this->categories($locale) as $id => $row) {
            $categories[] = ['id' => $id, 'parent' => (int) ($row['fk_i_parent_id'] ?? 0), 'name' => html_entity_decode((string) $row['s_name'], ENT_QUOTES, 'UTF-8')];
        }
        $query = new PageQuery();
        $rows  = $query->listed();
        $texts = $query->texts(array_map(static fn (array $p): int => (int) $p['pk_i_id'], $rows));
        $pages = [];
        foreach ($rows as $row) {
            $text    = $texts[(int) $row['pk_i_id']] ?? [];
            $title   = $text[$locale]['s_title'] ?? (reset($text)['s_title'] ?? $row['s_internal_name']);
            $pages[] = ['id' => (int) $row['pk_i_id'], 'name' => html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8')];
        }

        return ['categories' => $categories, 'pages' => $pages];
    }

    /**
     * A menu as links in $locale: `[['label' => …, 'url' => …, 'children' => […]], …]`, with
     * `children` only when there are some. Items whose category or page is gone or hidden
     * are left out with their children.
     *
     * @return array<int,array<string,mixed>>
     */
    public function resolve(string $id, string $locale): array
    {
        if (preg_match(self::ID, $id) !== 1) {
            return [];
        }
        $key = implode('|', ['menu', $id, $locale, CacheGroup::generation('category'), CacheGroup::generation('page')]);

        return CacheGroup::remember('preference', $key, function () use ($id, $locale): array {
            $saved = $this->load($id);
            if ($saved !== null) {
                return $this->links($saved['items'], $locale);
            }

            return match ($id) {
                'main'   => $this->topCategories($locale),
                'footer' => $this->linkedPages($locale),
                default  => [],
            };
        }) ?? [];
    }

    /**
     * @param array<mixed> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private function links(array $items, string $locale): array
    {
        $categories = null;
        $out        = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $labels = is_array($item['label'] ?? null) ? $item['label'] : [];
            $own    = $this->pickLabel($labels, $locale, ($item['type'] ?? '') === 'link');
            $link   = null;
            switch ($item['type'] ?? '') {
                case 'link':
                    $url  = Utils::toAbsolute((string) ($item['url'] ?? ''));
                    $link = $own !== '' && $url !== '' ? ['label' => $own, 'url' => $url] : null;
                    break;
                case 'category':
                    $categories ??= $this->categories($locale);
                    $row  = $categories[(int) ($item['ref'] ?? 0)] ?? null;
                    $link = $row === null ? null : ['label' => $own !== '' ? $own : self::decoded($row['s_name'] ?? ''), 'url' => ($this->categoryUrl)((int) $row['pk_i_id'])];
                    break;
                case 'page':
                    $page = $this->page((int) ($item['ref'] ?? 0), $locale);
                    $link = $page === null ? null : ['label' => $own !== '' ? $own : $page['label'], 'url' => $page['url']];
                    break;
            }
            if ($link === null || $link['label'] === '') {
                continue;
            }
            $children = is_array($item['children'] ?? null) ? $this->links($item['children'], $locale) : [];
            if ($children !== []) {
                $link['children'] = $children;
            }
            $out[] = $link;
        }

        return $out;
    }

    /**
     * The owner's label in $locale; a link falls back to the default language, then any.
     *
     * @param array<mixed> $labels
     */
    private function pickLabel(array $labels, string $locale, bool $anyLanguage): string
    {
        $codes = $anyLanguage ? [$locale, $this->defaultLocale, ...array_keys($labels)] : [$locale];
        foreach ($codes as $code) {
            if (is_string($labels[$code] ?? null) && $labels[$code] !== '') {
                return $labels[$code];
            }
        }

        return '';
    }

    /**
     * The enabled categories reachable through enabled parents, by id.
     *
     * @return array<int,array<string,mixed>>
     */
    private function categories(string $locale): array
    {
        $out = [];
        foreach ((new CategoryQuery())->enabledTree($locale) as $row) {
            $out[(int) $row['pk_i_id']] = $row;
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function topCategories(string $locale): array
    {
        $out = [];
        foreach ($this->categories($locale) as $id => $row) {
            if (empty($row['fk_i_parent_id'])) {
                $out[] = ['label' => self::decoded($row['s_name'] ?? ''), 'url' => ($this->categoryUrl)($id)];
            }
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function linkedPages(string $locale): array
    {
        $query = new PageQuery();
        $pages = $query->linked();
        $texts = $query->texts(array_map(static fn (array $p): int => (int) $p['pk_i_id'], $pages));
        $out   = [];
        foreach ($pages as $page) {
            $link = $this->pageLink($page, $texts[(int) $page['pk_i_id']] ?? [], $locale);
            if ($link !== null) {
                $out[] = $link;
            }
        }

        return $out;
    }

    /**
     * @return array{label:string,url:string}|null
     */
    private function page(int $id, string $locale): ?array
    {
        $query = new PageQuery();
        $page  = $id > 0 ? $query->find((string) $id) : null;

        return $page === null ? null : $this->pageLink($page, $query->texts([$id])[$id] ?? [], $locale);
    }

    /**
     * A page in $locale, else the default language, else the first it has; null with no text.
     *
     * @param array<string,mixed>               $page
     * @param array<string,array<string,mixed>> $texts
     *
     * @return array{label:string,url:string}|null
     */
    private function pageLink(array $page, array $texts, string $locale): ?array
    {
        $shown = isset($texts[$locale]) ? $locale : (isset($texts[$this->defaultLocale]) ? $this->defaultLocale : array_key_first($texts));
        if ($shown === null) {
            return null;
        }
        $title = self::decoded($texts[$shown]['s_title'] ?? '');
        $id    = (int) $page['pk_i_id'];

        return ['label' => $title, 'url' => ($this->pageUrl)($id, (string) ($page['s_internal_name'] ?? ''), $title, $shown === $this->defaultLocale ? '' : (string) $shown)];
    }

    private static function decoded(mixed $value): string
    {
        return is_scalar($value) ? trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }
}
