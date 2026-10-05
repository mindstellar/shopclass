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

use mindstellar\api\read\ListingRelations;
use mindstellar\listing\ListingStatus;

/**
 * A listing in the caller's view, ending with the `api_listing` filter. The public view shows
 * what the theme shows: the e-mail only when the seller shows it, the phone unless the site
 * hides it, never the IP or the edit secret.
 */
final class ListingSerializer
{
    public const PUBLIC_MEMBERS = [
        'id', 'url', 'status', 'title', 'description', 'locale', 'category', 'price', 'location', 'contact', 'seller',
        'photos', 'fields', 'translations', 'premium', 'views', 'published_at', 'updated_at', 'expires_at',
    ];

    /** Sent to the owner and admins. */
    public const OWNER_MEMBERS = ['show_email'];

    /** Sent to admins only. */
    public const ADMIN_MEMBERS = ['ip', 'stats'];

    public const MEMBERS = [...self::PUBLIC_MEMBERS, ...self::OWNER_MEMBERS, ...self::ADMIN_MEMBERS, 'ext'];

    /** The members `?include=` switches on. */
    public const INCLUDES = ['fields', 'translations'];

    public function __construct(
        private Links $links,
        private Extensions $extensions,
        private CustomFieldSerializer $fields,
        private bool $hidePhone = false,
        private bool $originals = false
    ) {
    }

    /**
     * A page of listings. `api_listings_prefetch` runs once, with every id, before the rows
     * are shaped, so a plugin loads its data for the page in one query.
     *
     * @param array<int,array<string,mixed>> $items extended listing rows
     *
     * @return array<int,array<string,mixed>>
     */
    public function many(array $items, ListingRelations $relations, ViewContext $context): array
    {
        if ($items === []) {
            return [];
        }
        $ids = array_map(static fn (array $item): int => Format::int($item['pk_i_id'] ?? 0), $items);
        osc_run_hook('api_listings_prefetch', $ids, $context);
        $now = time();

        return array_map(fn (array $item): array => $this->one($item, $relations, $context, $now), array_values($items));
    }

    /**
     * @param array<string,mixed> $item an extended listing row (Item::extendData())
     * @param int|null            $now  the time status is judged at; now when null
     *
     * @return array<string,mixed>
     */
    public function one(array $item, ListingRelations $relations, ViewContext $context, ?int $now = null): array
    {
        $id      = Format::int($item['pk_i_id'] ?? 0);
        $context = $context->withView($context->viewFor(Format::id($item['fk_i_user_id'] ?? null), ViewContext::LISTINGS_SCOPE));
        $view    = $context->view();
        $locale  = $context->locale();
        [$textLocale, $title, $description] = self::text($item, $locale);

        // Members the fieldset leaves out are not built, so their lookups and formatting are skipped.
        $build = [
            'url'          => fn () => $this->links->listing($item),
            'status'       => static fn () => ListingStatus::of($item, $now ?? time()),
            'title'        => static fn () => $title,
            'description'  => static fn () => $description,
            'locale'       => static fn () => $textLocale,
            'category'     => fn () => $this->category($item, $relations, $locale),
            'price'        => fn () => $this->price($item, $relations),
            'location'     => static fn () => self::location($item),
            'contact'      => fn () => $this->contact($item, $view),
            'seller'       => fn () => $this->seller($item, $relations),
            'photos'       => fn () => $this->photos($relations->photos($id)),
            'fields'       => $context->includes('fields') ? fn () => $this->fields->values($relations->fields($id), $locale) : null,
            'translations' => $context->includes('translations') ? static fn () => self::translations($item) : null,
            'premium'      => static fn () => Format::bool($item['b_premium'] ?? 0),
            'views'        => static fn () => Format::int($item['i_num_views'] ?? 0),
            'published_at' => static fn () => Format::time($item['dt_pub_date'] ?? null),
            'updated_at'   => static fn () => Format::time($item['dt_mod_date'] ?? null),
            'expires_at'   => static fn () => Format::time($item['dt_expiration'] ?? null),
            'show_email'   => $view !== ViewContext::PUBLIC ? static fn () => Format::bool($item['b_show_email'] ?? 0) : null,
            'ip'           => $view === ViewContext::ADMIN ? static fn () => Format::text($item['s_ip'] ?? null) : null,
            'stats'        => $view === ViewContext::ADMIN ? static fn () => self::stats($item) : null,
        ];
        $data = ['id' => $id];
        foreach ($build as $member => $make) {
            if ($make !== null && $context->wants($member)) {
                $data[$member] = $make();
            }
        }
        $filtered = osc_apply_filter('api_listing', $data, $item, $context);

        return $this->extensions->finish('api_listing', 'listing', self::MEMBERS, $data, $filtered, [$item, $context], $context);
    }

    /**
     * The members a page needs looked up: sellers, photos, custom field values.
     *
     * @return array{seller:bool,photos:bool,fields:bool}
     */
    public static function lookups(ViewContext $context): array
    {
        return [
            'seller' => $context->wants('seller'),
            'photos' => $context->wants('photos'),
            'fields' => $context->includes('fields') && $context->wants('fields'),
        ];
    }

    /**
     * The locale the text is in, the title and the description: the asked locale's when the
     * listing has it, else the first one it has.
     *
     * @param array<string,mixed> $item
     *
     * @return array{0:?string,1:string,2:string}
     */
    private static function text(array $item, string $locale): array
    {
        $texts = is_array($item['locale'] ?? null) ? $item['locale'] : [];
        if (($texts[$locale]['s_title'] ?? '') !== '') {
            return [$locale, (string) $texts[$locale]['s_title'], (string) ($texts[$locale]['s_description'] ?? '')];
        }
        foreach ($texts as $code => $text) {
            if (($text['s_title'] ?? '') !== '') {
                return [(string) $code, (string) $text['s_title'], (string) ($text['s_description'] ?? '')];
            }
        }

        return [null, (string) ($item['s_title'] ?? ''), (string) ($item['s_description'] ?? '')];
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return array<string,mixed>
     */
    private function category(array $item, ListingRelations $relations, string $locale): array
    {
        $id       = Format::int($item['fk_i_category_id'] ?? 0);
        $catalog  = $relations->categories();
        $category = $catalog->find($id);
        if ($category === null) {
            return ['id' => $id, 'slug' => null, 'name' => Format::text($item['s_category_name'] ?? null), 'path' => []];
        }

        return CategorySerializer::reference($category, $locale) + [
            'path' => array_map(static fn (array $c): array => CategorySerializer::reference($c, $locale), $catalog->ancestors($id)),
        ];
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return array{amount:string,currency:?string,formatted:string}|null
     */
    private function price(array $item, ListingRelations $relations): ?array
    {
        $category = $relations->categories()->find(Format::int($item['fk_i_category_id'] ?? 0));
        if (!is_numeric($item['i_price'] ?? null) || ($category !== null && !Format::bool($category['b_price_enabled'] ?? 1))) {
            return null;
        }
        $code     = Format::text($item['fk_c_currency_code'] ?? null);
        $currency = $code === null ? null : $relations->currency($code);
        $symbol   = Format::text($currency['s_description'] ?? null) ?? (string) $code;

        return [
            'amount'    => (string) Format::amount($item['i_price']),
            'currency'  => $code === null ? null : strtoupper($code),
            'formatted' => $this->links->price((int) $item['i_price'], $symbol),
        ];
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return array<string,mixed>
     */
    private static function location(array $item): array
    {
        return [
            'country'   => Format::place($item['fk_c_country_code'] ?? null, $item['s_country'] ?? null, 'code'),
            'region'    => Format::place($item['fk_i_region_id'] ?? null, $item['s_region'] ?? null, 'id'),
            'city'      => Format::place($item['fk_i_city_id'] ?? null, $item['s_city'] ?? null, 'id'),
            'city_area' => Format::text($item['s_city_area'] ?? null),
            'address'   => Format::text($item['s_address'] ?? null),
            'zip'       => Format::text($item['s_zip'] ?? null),
            'lat'       => Format::float($item['d_coord_lat'] ?? null),
            'lng'       => Format::float($item['d_coord_long'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return array{name:?string,email:?string,phone:?string}
     */
    private function contact(array $item, string $view): array
    {
        $public = $view === ViewContext::PUBLIC;

        return [
            'name'  => Format::text($item['s_contact_name'] ?? null),
            'email' => $public && !Format::bool($item['b_show_email'] ?? 0) ? null : Format::text($item['s_contact_email'] ?? null),
            'phone' => $public && $this->hidePhone ? null : Format::text($item['s_contact_phone'] ?? null),
        ];
    }

    /**
     * Report counters, for the admin view.
     *
     * @param array<string,mixed> $item
     *
     * @return array<string,int>
     */
    private static function stats(array $item): array
    {
        return [
            'spam'           => Format::int($item['i_num_spam'] ?? 0),
            'bad_classified' => Format::int($item['i_num_bad_classified'] ?? 0),
            'repeated'       => Format::int($item['i_num_repeated'] ?? 0),
            'offensive'      => Format::int($item['i_num_offensive'] ?? 0),
            'expired'        => Format::int($item['i_num_expired'] ?? 0),
            'premium_views'  => Format::int($item['i_num_premium_views'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return array<string,mixed>|null
     */
    private function seller(array $item, ListingRelations $relations): ?array
    {
        $userId = Format::id($item['fk_i_user_id'] ?? null);
        $user   = $userId === null ? null : $relations->user($userId);
        if ($user === null) {
            return null;
        }
        $username = (string) ($user['s_username'] ?? '');

        return [
            'id'       => $userId,
            'name'     => (string) ($user['s_name'] ?? ''),
            'username' => Format::text($username),
            'url'      => $this->links->user($userId, $username),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $resources
     *
     * @return array<int,array<string,mixed>>
     */
    public function photos(array $resources): array
    {
        $out = [];
        foreach ($resources as $resource) {
            $out[] = [
                'id'        => Format::int($resource['pk_i_id'] ?? 0),
                'thumbnail' => $this->links->photo($resource, 'thumbnail'),
                'preview'   => $this->links->photo($resource, 'preview'),
                'normal'    => $this->links->photo($resource, ''),
                'original'  => $this->originals ? $this->links->photo($resource, 'original') : null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return array<string,array{title:string,description:string}>|null
     */
    private static function translations(array $item): ?array
    {
        $out = [];
        foreach ((array) ($item['locale'] ?? []) as $code => $text) {
            if (is_array($text) && (($text['s_title'] ?? '') !== '' || ($text['s_description'] ?? '') !== '')) {
                $out[(string) $code] = ['title' => (string) ($text['s_title'] ?? ''), 'description' => (string) ($text['s_description'] ?? '')];
            }
        }

        return $out === [] ? null : $out;
    }
}
