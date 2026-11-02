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

namespace mindstellar\api\serializer;

use mindstellar\menu\MenuItems;
use mindstellar\menu\MenuService;
use mindstellar\pages\PageQuery;

/**
 * Static pages and the site's menus. A page is shown in the asked language, else the site's
 * default, else the first it has. Page text is HTML the site's editors wrote, passed through
 * the `static_page_text` filter as the page template does; titles and menu labels are plain.
 */
final class PageSerializer
{
    /**
     * @param \Closure(int,string,string,string):string $url builds a page's URL from id, slug, title and locale
     */
    public function __construct(private string $defaultLocale, private \Closure $url)
    {
    }

    /**
     * A page's URL on this site.
     */
    public static function siteUrl(int $id, string $slug, string $title, string $locale): string
    {
        return PageQuery::siteUrl($id, $slug, $title, $locale);
    }

    /**
     * The locale a page is shown in, or null when it has no text at all.
     *
     * @param array<string,array<string,mixed>> $texts
     */
    public function pick(array $texts, string $locale): ?string
    {
        foreach ([$locale, $this->defaultLocale] as $code) {
            if (isset($texts[$code])) {
                return $code;
            }
        }

        return $texts === [] ? null : (string) array_key_first($texts);
    }

    /**
     * A page for a list: no text.
     *
     * @param array<string,mixed>               $page
     * @param array<string,array<string,mixed>> $texts the page's texts by locale
     *
     * @return array<string,mixed>|null null for a page with no text
     */
    public function summary(array $page, array $texts, string $locale): ?array
    {
        $shown = $this->pick($texts, $locale);
        if ($shown === null) {
            return null;
        }
        $title = Format::plain($texts[$shown]['s_title'] ?? '');
        $id    = Format::int($page['pk_i_id'] ?? 0);
        $slug  = Format::text($page['s_internal_name'] ?? null);

        return [
            'id'       => $id,
            'slug'     => $slug,
            'title'    => $title,
            'url'      => ($this->url)($id, (string) $slug, $title, $shown === $this->defaultLocale ? '' : $shown),
            'locale'   => $shown,
            'position' => Format::int($page['i_order'] ?? 0),
        ];
    }

    /**
     * One page with its text and meta.
     *
     * @param array<string,mixed>               $page
     * @param array<string,array<string,mixed>> $texts
     *
     * @return array<string,mixed>|null null for a page with no text
     */
    public function one(array $page, array $texts, string $locale): ?array
    {
        $data = $this->summary($page, $texts, $locale);
        if ($data === null) {
            return null;
        }
        $shown = (string) $data['locale'];
        $text  = osc_apply_filter('static_page_text', (string) ($texts[$shown]['s_text'] ?? ''), $shown);
        $meta  = [];
        $raw   = json_decode((string) ($page['s_meta'] ?? ''), true);
        foreach (is_array($raw) ? $raw : [] as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $meta[(string) $key] = Format::plain($value);
            }
        }

        return $data + [
            'text'       => osc_autop($text),
            'meta'       => (object) $meta,
            'updated_at' => Format::time($page['dt_mod_date'] ?? $page['dt_pub_date'] ?? null),
        ];
    }

    /**
     * The menus from MenuService, `main` and `footer` first. The `api_menus` filter may add a
     * menu or change one; it gets and returns `id => [['label' => string, 'url' => string,
     * 'children' => [...]], ...]`, where `children` is optional and has the same shape.
     *
     * @param array<string,array<int,array<string,mixed>>> $menus id => resolved items
     *
     * @return array<int,array{id:string,items:array<int,array<string,mixed>>}>
     */
    public function menus(array $menus, string $locale): array
    {
        $menus = osc_apply_filter('api_menus', $menus, $locale);
        $out   = [];
        foreach (is_array($menus) ? $menus : [] as $id => $items) {
            if (!is_array($items) || preg_match(MenuService::ID, (string) $id) !== 1) {
                continue;
            }
            $out[] = ['id' => (string) $id, 'items' => $this->menuItems($items, 1)];
        }

        return $out;
    }

    /**
     * @param array<mixed> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private function menuItems(array $items, int $depth): array
    {
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['label'], $item['url']) || !is_scalar($item['label']) || !is_scalar($item['url'])) {
                continue;
            }
            $row      = ['label' => Format::plain($item['label']), 'url' => (string) $item['url']];
            $children = $depth < MenuItems::MAX_DEPTH && is_array($item['children'] ?? null) ? $this->menuItems($item['children'], $depth + 1) : [];
            if ($children !== []) {
                $row['children'] = $children;
            }
            $clean[] = $row;
        }

        return $clean;
    }
}
