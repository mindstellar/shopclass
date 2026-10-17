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

namespace mindstellar\seo;

use mindstellar\listing\ListingStatus;
use mindstellar\routing\CoreRoutes;

/**
 * The head data of a public page: canonical URL, title, description, robots, breadcrumbs,
 * hreflang and Open Graph basics. It works from rows it is given and reads nothing from the
 * current web request. Only a listing or a page has a different URL per language, so only
 * those carry hreflang.
 *
 * Titles are "<name> - <site title>"; the home page is the site title alone.
 */
final class SeoResolver
{
    private const DESCRIPTION_MAX = 160;

    /**
     * @param string[]                                  $locales  the site's locale codes
     * @param \Closure(string,array<string,mixed>,string):string $url builds a URL from a kind (`home`, `listing`, `page`, `category`, `search`), its arguments and a locale code ('' for the default language)
     */
    public function __construct(
        private string $defaultLocale,
        private array $locales,
        private string $siteTitle,
        private string $siteDescription,
        private \Closure $url
    ) {
    }

    /**
     * A resolver using this site's settings and URL helpers.
     */
    public static function fromSite(): self
    {
        $locales = array_map(static fn (array $l): string => (string) $l['pk_c_code'], (array) osc_get_locales());

        return new self(
            (string) osc_language(),
            $locales,
            (string) osc_page_title(),
            (string) osc_page_description(),
            static function (string $kind, array $args, string $locale): string {
                return match ($kind) {
                    'listing'  => (string) osc_item_url_from_item($args['item'], $locale),
                    'page'     => self::pageUrl((int) $args['id'], (string) $args['slug'], (string) $args['title'], $locale),
                    'category' => (string) osc_search_url(['sCategory' => (string) $args['slug']]),
                    'search'   => (string) osc_search_url($args['q'] !== '' ? ['sPattern' => (string) $args['q']] : []),
                    default    => (string) osc_base_url(),
                };
            }
        );
    }

    private static function pageUrl(int $id, string $slug, string $title, string $locale): string
    {
        if (osc_rewrite_enabled()) {
            return osc_base_url() . ($locale !== '' ? $locale . '/' : '') . CoreRoutes::expand('page', ['PAGE_ID' => $id, 'PAGE_SLUG' => $slug, 'PAGE_TITLE' => $title]);
        }

        return (string) osc_core_url('page', ['id' => $id, 'lang' => $locale]);
    }

    /**
     * @return array<string,mixed>
     */
    public function home(string $locale): array
    {
        $url = $this->url('home', [], '');

        return $this->document('home', $locale, $url, $this->plain($this->siteTitle), '', 'index, follow', [['label' => $this->homeLabel(), 'url' => $url]], [], 'website', null, false);
    }

    /**
     * @param array<string,mixed>              $item         a listing row with its texts in every language under `locale`
     * @param array<int,array<string,mixed>>   $categoryPath the listing's category and its ancestors, root first
     * @param string|null                      $image        the first photo's URL
     *
     * @return array<string,mixed>|null null when the listing is neither live nor expired
     */
    public function listing(array $item, array $categoryPath, string $locale, ?string $image): ?array
    {
        $status = ListingStatus::of($item);
        if ($status !== ListingStatus::ACTIVE && $status !== ListingStatus::EXPIRED) {
            return null;
        }
        $texts                    = (array) ($item['locale'] ?? []);
        [$shown, $title, $desc]   = self::pickText($texts, $locale);
        $shown                    = $shown ?? $this->defaultLocale;
        $title                    = $title !== '' ? $title : (string) ($item['s_title'] ?? '');
        $item['s_title']          = $title;
        $withTitle                = fn (string $code): string => $this->url('listing', ['item' => $this->itemTitled($item, $texts, $code)], $this->prefix($code));
        $url                      = $withTitle($shown);
        $crumbs                   = $this->categoryTrail($categoryPath, $locale);
        $crumbs[]                 = ['label' => $this->plain($title), 'url' => $url];
        $hreflang                 = $this->hreflang(array_keys(array_filter($texts, static fn ($t): bool => is_array($t) && ($t['s_title'] ?? '') !== '')), $withTitle);

        return $this->document(
            'listing',
            $shown,
            $url,
            $this->plain($title),
            $this->excerpt($desc),
            $status === ListingStatus::ACTIVE ? 'index, follow' : 'noindex, follow',
            $crumbs,
            $hreflang,
            'product',
            $image,
            true
        );
    }

    /**
     * @param array<int,array<string,mixed>> $categoryPath the category and its ancestors, root first
     *
     * @return array<string,mixed>
     */
    public function category(array $categoryPath, string $locale): array
    {
        $category = $categoryPath[array_key_last($categoryPath)];
        $url      = $this->categoryUrl($category, $locale);
        $name     = $this->plain(self::text($category, 's_name', $locale));
        $crumbs   = $this->categoryTrail($categoryPath, $locale);

        return $this->document('category', $locale, $url, $name, $this->excerpt(self::text($category, 's_description', $locale)), 'index, follow', $crumbs, [], 'website', null, true);
    }

    /**
     * @param array<string,mixed>               $page  the PageSerializer::one() answer
     * @param array<string,array<string,mixed>> $texts the page's texts by locale
     *
     * @return array<string,mixed>
     */
    public function page(array $page, array $texts): array
    {
        $shown = (string) $page['locale'];
        $meta  = (array) ($page['meta'] ?? []);
        $desc  = (string) ($meta['description'] ?? '');
        $desc  = $desc !== '' ? $desc : (string) $page['text'];
        $make  = fn (string $code): string => $this->url('page', ['id' => $page['id'], 'slug' => (string) $page['slug'], 'title' => $this->plain((string) ($texts[$code]['s_title'] ?? $page['title']))], $this->prefix($code));
        $url   = (string) $page['url'];

        return $this->document(
            'page',
            $shown,
            $url,
            $this->plain((string) $page['title']),
            $this->excerpt($desc),
            'index, follow',
            [$this->homeCrumb(), ['label' => $this->plain((string) $page['title']), 'url' => $url]],
            $this->hreflang(array_keys($texts), $make),
            'article',
            null,
            true
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function search(string $locale, string $query): array
    {
        $url   = $this->url('search', ['q' => $query], '');
        $label = $query !== '' ? sprintf(__('Search results: %s'), $query) : __('Search results');

        return $this->document('search', $locale, $url, $label, '', 'noindex, follow', [$this->homeCrumb(), ['label' => $label, 'url' => $url]], [], 'website', null, true);
    }

    /**
     * A category's URL in the asked language's slug.
     *
     * @param array<string,mixed> $category
     */
    public function categoryUrl(array $category, string $locale): string
    {
        return $this->url('category', ['slug' => self::text($category, 's_slug', $locale)], '');
    }

    /**
     * @param array<string,mixed> $args
     */
    private function url(string $kind, array $args, string $locale): string
    {
        return ($this->url)($kind, $args, $locale);
    }

    /** '' for the default language, whose URLs carry no prefix. */
    private function prefix(string $code): string
    {
        return $code === $this->defaultLocale ? '' : $code;
    }

    private function homeLabel(): string
    {
        return __('Home');
    }

    /**
     * @return array{label:string,url:string}
     */
    private function homeCrumb(): array
    {
        return ['label' => $this->homeLabel(), 'url' => $this->url('home', [], '')];
    }

    /**
     * Home, then the category's ancestors, then the category itself.
     *
     * @param array<int,array<string,mixed>> $path category rows, root first
     *
     * @return array<int,array{label:string,url:string}>
     */
    private function categoryTrail(array $path, string $locale): array
    {
        $trail = [$this->homeCrumb()];
        foreach ($path as $step) {
            $trail[] = ['label' => $this->plain(self::text($step, 's_name', $locale)), 'url' => $this->categoryUrl($step, $locale)];
        }

        return $trail;
    }

    /**
     * A translated text of a category row: the locale's own, else the row's default.
     *
     * @param array<string,mixed> $row
     */
    private static function text(array $row, string $key, string $locale): string
    {
        $value = $row['locale'][$locale][$key] ?? null;

        return is_string($value) && $value !== '' ? $value : (string) ($row[$key] ?? '');
    }

    /**
     * A listing's title and description in the asked language, else in the first that has a title.
     *
     * @param array<string,array<string,mixed>> $texts
     *
     * @return array{0:?string,1:string,2:string}
     */
    private static function pickText(array $texts, string $locale): array
    {
        foreach ([$locale, ...array_keys($texts)] as $code) {
            if (($texts[$code]['s_title'] ?? '') !== '') {
                return [(string) $code, (string) $texts[$code]['s_title'], (string) ($texts[$code]['s_description'] ?? '')];
            }
        }

        return [null, '', ''];
    }

    /**
     * @param array<string,mixed>              $item
     * @param array<string,array<string,mixed>> $texts
     *
     * @return array<string,mixed> the row with the title of the given language, for its URL
     */
    private function itemTitled(array $item, array $texts, string $code): array
    {
        $title = (string) ($texts[$code]['s_title'] ?? '');
        if ($title !== '') {
            $item['s_title'] = $title;
        }

        return $item;
    }

    /**
     * One entry per language the thing has text in, plus `x-default`; none when it has one language.
     *
     * @param string[]                  $codes
     * @param \Closure(string):string   $make  the URL for a locale code
     *
     * @return array<int,array{locale:string,url:string}>
     */
    private function hreflang(array $codes, \Closure $make): array
    {
        $codes = array_values(array_intersect($this->locales, $codes));
        if (count($codes) < 2) {
            return [];
        }
        $out = [];
        foreach ($codes as $code) {
            $out[] = ['locale' => str_replace('_', '-', $code), 'url' => $make($code)];
        }
        $default = in_array($this->defaultLocale, $codes, true) ? $this->defaultLocale : $codes[0];
        $out[]   = ['locale' => 'x-default', 'url' => $make($default)];

        return $out;
    }

    /**
     * @param array<int,array{label:string,url:string}>  $breadcrumbs
     * @param array<int,array{locale:string,url:string}> $hreflang
     *
     * @return array<string,mixed>
     */
    private function document(string $for, string $locale, string $canonical, string $name, string $description, string $robots, array $breadcrumbs, array $hreflang, string $ogType, ?string $image, bool $suffix): array
    {
        $title       = $suffix && $this->siteTitle !== '' ? $name . ' - ' . $this->plain($this->siteTitle) : $name;
        $description = $description !== '' ? $description : $this->excerpt($this->siteDescription);

        return [
            'for'         => $for,
            'locale'      => $locale,
            'canonical'   => $canonical,
            'title'       => $title,
            'description' => $description,
            'robots'      => $robots,
            'breadcrumbs' => $breadcrumbs,
            'hreflang'    => $hreflang,
            'og'          => [
                'type'        => $ogType,
                'title'       => $name,
                'description' => $description,
                'url'         => $canonical,
                'site_name'   => $this->plain($this->siteTitle),
                'locale'      => $locale,
                'image'       => $image,
            ],
        ];
    }

    private function plain(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function excerpt(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $this->plain($html)));
        if (mb_strlen($text, 'UTF-8') <= self::DESCRIPTION_MAX) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::DESCRIPTION_MAX - 1, 'UTF-8')) . '…';
    }
}
