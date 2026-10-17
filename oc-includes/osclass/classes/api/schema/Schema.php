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

namespace mindstellar\api\schema;

use mindstellar\api\Problem;
use mindstellar\api\serializer\CustomFieldSerializer;
use mindstellar\api\serializer\ExtensionMembers;
use mindstellar\api\Warning;
use mindstellar\currency\CurrencyCode;
use mindstellar\listing\ListingStatus;
use mindstellar\listing\WishlistStore;
use mindstellar\location\CountryCode;
use mindstellar\user\AccountService;
use mindstellar\user\UserStatus;
use mindstellar\utility\DateInput;

/**
 * The component schemas routes `$ref`, the validator checks and the OpenAPI document lists,
 * using only keywords Validator knows. Fields declared with osc_api_register_field() appear
 * under each object's `ext`.
 */
final class Schema
{
    /** The object schemas, each written out below. */
    private const OBJECTS = [
        'Problem', 'PageMeta', 'PageLinks', 'Photo', 'CategoryRef', 'CustomFieldValue', 'Listing', 'User', 'Category', 'CustomField',
        'Country', 'Region', 'City', 'CityArea', 'Currency', 'Comment', 'Site', 'OpenApiDocument',
        'Warning', 'TokenDocument', 'TokenRequest', 'SessionToken', 'AccountInput', 'AccountDocument', 'PasswordChange', 'Session',
        'PersonalKey', 'PersonalKeyInput', 'Registration', 'NewAccount',
        'ListingInput', 'ListingPatch', 'SavedListing', 'PhotoToken', 'CommentInput', 'SavedComment',
        'AlertFilters', 'Alert', 'AlertInput', 'AlertProductInput', 'Wishlist', 'WishlistInput', 'WishlistDocument', 'SearchSuggestions', 'CategorySuggestions',
        'PageSummary', 'Page', 'Menu', 'Seo',
    ];

    /** `<name>List`: every item at once. */
    private const LISTS = ['PageSummary', 'Menu', 'Category', 'CustomField', 'Currency', 'Photo', 'Session', 'PersonalKey', 'Alert'];

    /** `<name>List` that is paged: the location lists keep their List names. */
    private const PAGED_LISTS = ['Country', 'Region', 'City', 'CityArea'];

    /** `<name>Page`. */
    private const PAGES = ['Listing', 'Comment'];

    /** `<name>Document`: one resource. */
    private const DOCUMENTS = ['Page', 'Listing', 'User', 'Category', 'Site', 'PersonalKey', 'NewAccount', 'Photo', 'PhotoToken', 'Alert', 'Comment', 'SessionToken', 'SearchSuggestions', 'CategorySuggestions', 'Seo'];

    private function __construct()
    {
    }

    /**
     * The envelope of every list answer: `data`, `meta` and `links`. A list that is not paged
     * has `meta.total` and `meta.limit` equal to its size, and a null `links.next`.
     *
     * @api
     *
     * @return array<string,mixed>
     */
    public static function wholeList(string $item): array
    {
        return self::object([
            'data'  => ['type' => 'array', 'items' => self::ref($item)],
            'meta'  => self::ref('PageMeta'),
            'links' => self::ref('PageLinks'),
        ], ['data', 'meta', 'links']);
    }

    /**
     * Every component name, known without building a schema.
     *
     * @return string[]
     */
    public static function names(): array
    {
        $suffixed = static fn (array $names, string $suffix): array => array_map(static fn (string $n): string => $n . $suffix, $names);

        return [
            ...self::OBJECTS,
            ...$suffixed(self::LISTS, 'List'),
            ...$suffixed(self::PAGED_LISTS, 'List'),
            ...$suffixed(self::PAGES, 'Page'),
            ...$suffixed(self::DOCUMENTS, 'Document'),
            ...AdminSchema::NAMES,
        ];
    }

    /**
     * The components, built only when a `$ref` is followed or the whole set is asked for.
     */
    public static function definitions(?ExtensionMembers $ext = null): Definitions
    {
        return Definitions::lazy(self::names(), static fn (): array => self::components($ext));
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function components(?ExtensionMembers $ext = null): array
    {
        $ext ??= new ExtensionMembers();

        $schemas = [
            'Problem'      => self::problem(),
            'PageMeta'     => self::object([
                'total' => self::nullable('integer', 'Matches across every page; null unless the request sent count=true, and on a page reached by a keyset cursor or a location list. A list that is not paged always has it, equal to its size.'),
                'limit' => ['type' => 'integer'],
                'page'  => ['type' => 'integer', 'description' => 'The page number, when the request sent `page`.'],
                'pages' => ['type' => 'integer', 'description' => 'How many pages the matches fill, when the request sent `page` and `count=true`.'],
                'truncated' => ['type' => 'boolean', 'description' => 'Present and true when more matches exist but paging stops here: links.next is null. Narrow the filters, or sort by created or id.'],
                'facets'     => self::object([
                    'category' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'description' => 'Category id => listings found in it, as the search engine counted them.'],
                ], []) + ['description' => 'Listing search only, when a search engine plugin sent counts.'],
                'suggestion' => ['type' => 'string', 'description' => 'Listing search only: a corrected query from a search engine plugin.'],
                'relaxed'    => ['type' => 'boolean', 'description' => 'Listing search only: present and true when a search engine plugin widened the search to find these results.'],
            ], ['limit']),
            'PageLinks'    => self::object([
                'self' => ['type' => 'string', 'format' => 'uri'],
                'next' => self::nullable('string', 'The next page; null on the last one.'),
            ], ['self', 'next']),
            'Photo'        => self::object([
                'id'        => ['type' => 'integer'],
                'thumbnail' => ['type' => 'string'],
                'preview'   => ['type' => 'string'],
                'normal'    => ['type' => 'string'],
                'original'  => self::nullable('string', 'Only when the site keeps original images.'),
            ], ['id', 'thumbnail', 'preview', 'normal', 'original']),
            'CategoryRef'  => self::object([
                'id'   => ['type' => 'integer'],
                'slug' => self::nullable('string'),
                'name' => self::nullable('string'),
            ], ['id', 'slug', 'name']),
            'CustomFieldValue'   => self::object([
                'id'    => ['type' => 'integer'],
                'slug'  => ['type' => 'string'],
                'name'  => ['type' => 'string'],
                'type'  => ['type' => 'string', 'enum' => CustomFieldSerializer::TYPES],
                'value' => [
                    'type'        => ['string', 'number', 'boolean', 'object', 'null'],
                    'description' => 'Text, a number, a boolean (checkbox), an RFC 3339 time (date) or {from, to} (date interval).',
                ],
            ], ['id', 'slug', 'name', 'type', 'value']),
            'Listing'      => self::listing($ext),
            'User'         => self::user($ext),
            'Category'     => self::category($ext),
            'CustomField'        => self::object([
                'id'         => ['type' => 'integer'],
                'slug'       => ['type' => 'string'],
                'name'       => ['type' => 'string'],
                'type'       => ['type' => 'string', 'enum' => CustomFieldSerializer::TYPES],
                'required'   => ['type' => 'boolean'],
                'searchable' => ['type' => 'boolean'],
                'options'    => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
            ], ['id', 'slug', 'name', 'type', 'required', 'searchable', 'options']),
            'Country'      => self::object([
                'code' => ['type' => 'string', 'pattern' => CountryCode::PATTERN],
                'name' => ['type' => 'string'],
                'slug' => self::nullable('string'),
            ], ['code', 'name', 'slug']),
            'Region'       => self::object([
                'id'           => ['type' => 'integer'],
                'country_code' => ['type' => 'string'],
                'name'         => ['type' => 'string'],
                'slug'         => self::nullable('string'),
                'lat'          => self::nullable('number'),
                'lng'          => self::nullable('number'),
            ], ['id', 'country_code', 'name', 'slug']),
            'City'         => self::object([
                'id'           => ['type' => 'integer'],
                'region_id'    => ['type' => 'integer'],
                'country_code' => self::nullable('string'),
                'name'         => ['type' => 'string'],
                'slug'         => self::nullable('string'),
                'lat'          => self::nullable('number'),
                'lng'          => self::nullable('number'),
            ], ['id', 'region_id', 'name', 'slug']),
            'CityArea'     => self::object([
                'id'      => ['type' => 'integer'],
                'city_id' => ['type' => 'integer'],
                'name'    => ['type' => 'string'],
            ], ['id', 'city_id', 'name']),
            'Currency'     => self::object([
                'code'   => ['type' => 'string', 'pattern' => CurrencyCode::PATTERN],
                'name'   => ['type' => 'string'],
                'symbol' => self::nullable('string'),
            ], ['code', 'name', 'symbol']),
            'Comment'      => self::object([
                'id'           => ['type' => 'integer'],
                'listing_id'   => ['type' => 'integer'],
                'title'        => self::nullable('string'),
                'body'         => ['type' => 'string'],
                'author'       => self::object(['name' => self::nullable('string'), 'user_id' => self::nullable('integer')], ['name', 'user_id']),
                'rating'       => self::nullable('integer', 'Stars from 1 to 5; null for a comment with no rating.'),
                'published_at' => self::time(),
            ], ['id', 'listing_id', 'title', 'body', 'author', 'rating', 'published_at']),
            'Site'         => self::site(),
            'OpenApiDocument' => ['type' => 'object', 'description' => 'An OpenAPI 3.1 document.'],
        ] + self::account() + self::writes() + self::suggestions() + self::pages();

        $page = static fn (string $name): array => self::wholeList($name);
        foreach (self::LISTS as $name) {
            $schemas[$name . 'List'] = self::wholeList($name);
        }
        foreach (self::PAGED_LISTS as $name) {
            $schemas[$name . 'List'] = $page($name);
        }
        foreach (self::PAGES as $name) {
            $schemas[$name . 'Page'] = $page($name);
        }
        foreach (self::DOCUMENTS as $name) {
            $schemas[$name . 'Document'] = self::object(['data' => self::ref($name)], ['data']);
        }

        return $schemas + AdminSchema::components();
    }

    /**
     * `{"$ref": "#/components/schemas/<name>"}`
     *
     * @return array{'$ref':string}
     *
     * @api
     */
    public static function ref(string $name): array
    {
        return ['$ref' => Validator::REF_PREFIX . $name];
    }

    /** A day, `2027-03-01`, or an RFC 3339 date-time: what every date input takes. */
    public const DATE_INPUT = DateInput::PATTERN;

    /**
     * A date input: a day or an RFC 3339 date-time, or with $days also a number of days, `90d`.
     *
     * @return array<string,mixed>
     *
     * @api
     */
    public static function dateInput(string $description, bool $days = false, bool $nullable = false): array
    {
        return [
            'type'        => $nullable ? ['string', 'null'] : 'string',
            'pattern'     => '^(' . self::DATE_INPUT . ($days ? '|[0-9]{1,4}d' : '') . ')$',
            'description' => $description,
        ];
    }

    /**
     * A query parameter that takes some of $values: one, a comma list, or repeated. The
     * pattern checks a comma list, the items a repeated one.
     *
     * @param string[] $values
     *
     * @return array<string,mixed>
     *
     * @api
     */
    public static function listOf(array $values): array
    {
        $one = '\\s*(?:' . implode('|', array_map(static fn (string $v): string => preg_quote($v, '~'), $values)) . ')?\\s*';

        return [
            'type'        => ['string', 'array'],
            'items'       => ['type' => 'string', 'enum' => $values],
            'pattern'     => '^' . $one . '(?:,' . $one . ')*$',
            'description' => 'One value, a comma list, or repeated.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function listing(ExtensionMembers $ext): array
    {
        return self::object([
            'id'           => ['type' => 'integer'],
            'url'          => ['type' => 'string', 'format' => 'uri'],
            'status'       => ['type' => 'string', 'enum' => ListingStatus::ALL],
            'title'        => ['type' => 'string'],
            'description'  => ['type' => 'string'],
            'snippet'      => ['type' => 'string', 'description' => 'Keyword searches only: a short piece of the text with the searched words in <mark>. Escaped HTML; <mark> is its only tag.'],
            'locale'       => self::nullable('string', 'The locale the title and description are in.'),
            'category'     => self::object([
                'id'   => ['type' => 'integer'],
                'slug' => self::nullable('string'),
                'name' => self::nullable('string'),
                'path' => ['type' => 'array', 'items' => self::ref('CategoryRef'), 'description' => 'Ancestors, root first.'],
            ], ['id', 'slug', 'name', 'path']),
            'price'        => [
                'type'        => ['object', 'null'],
                'description' => 'Null when the listing has no price or its category does not show prices.',
                'properties'  => [
                    'amount'    => ['type' => 'string', 'pattern' => '^-?[0-9]+\.[0-9]{2,6}$', 'example' => '12.50'],
                    'currency'  => ['type' => ['string', 'null']],
                    'formatted' => ['type' => 'string'],
                ],
                'required'    => ['amount', 'currency', 'formatted'],
            ],
            'location'     => self::object([
                'country'   => self::place('code', 'string'),
                'region'    => self::place('id', 'integer'),
                'city'      => self::place('id', 'integer'),
                'city_area' => self::place('id', 'integer'),
                'address'   => self::nullable('string'),
                'zip'       => self::nullable('string'),
                'lat'       => self::nullable('number'),
                'lng'       => self::nullable('number'),
                'distance_km' => self::nullable('integer', 'Km from lat/lng, rounded; null outside a distance search.'),
            ], ['country', 'region', 'city', 'city_area', 'address', 'zip', 'lat', 'lng', 'distance_km']),
            'contact'      => self::object([
                'name'  => self::nullable('string'),
                'email' => self::nullable('string', 'Null unless the seller shows it; always sent to the owner and admins.'),
                'phone' => self::nullable('string', 'Shown as the theme shows it, unless the site hides it from the API.'),
            ], ['name', 'email', 'phone']),
            'seller'       => [
                'type'       => ['object', 'null'],
                'properties' => [
                    'id'       => ['type' => 'integer'],
                    'name'     => ['type' => 'string'],
                    'username' => ['type' => ['string', 'null']],
                    'url'      => ['type' => 'string'],
                ],
            ],
            'photos'       => ['type' => 'array', 'items' => self::ref('Photo')],
            'custom_fields' => ['type' => 'array', 'items' => self::ref('CustomFieldValue'), 'description' => 'With `include=custom_fields`.'],
            'translations' => [
                'type'                 => ['object', 'null'],
                'description'          => 'With `include=translations`: locale => {title, description}.',
                'additionalProperties' => self::object(['title' => ['type' => 'string'], 'description' => ['type' => 'string']]),
            ],
            'product'      => self::object([
                'buyable'  => ['type' => 'boolean'],
                'variants' => ['type' => 'array', 'items' => self::object([
                    'id'             => ['type' => 'integer'],
                    'sku'            => self::nullable('string'),
                    'options'        => ['type' => ['object', 'null'], 'additionalProperties' => ['type' => 'string'], 'description' => 'Option name => value; null for the default variant.'],
                    'price'          => self::nullable('string', 'A decimal such as "12.50"; null uses the listing price.'),
                    'compare_at'     => self::nullable('string', 'The "was" price, a decimal such as "20.00"; null when none is set.'),
                    'on_sale'        => ['type' => 'boolean', 'description' => 'true when compare_at is above the price the variant sells at.'],
                    'in_stock'       => ['type' => 'boolean', 'description' => 'true when stock is not tracked.'],
                    'low_stock'      => self::nullable('integer', 'The units left when 1 to 5 are, for everyone; null otherwise or when stock is not tracked.'),
                    'stock'          => self::nullable('integer', 'Owner and admin view. Null when stock is not tracked.'),
                    'weight'         => self::nullable('integer', 'Grams.'),
                    'shipping_class' => self::nullable('string'),
                    'photo_id'       => self::nullable('integer'),
                    // C1: the variant's photo.
                    'photo'          => [
                        'type'        => ['object', 'null'],
                        'description' => 'The variant\'s photo, one of the listing\'s `photos`; null when it has none.',
                        'properties'  => [
                            'id'        => ['type' => 'integer'],
                            'thumbnail' => ['type' => 'string'],
                            'preview'   => ['type' => 'string'],
                            'normal'    => ['type' => 'string'],
                            'original'  => ['type' => ['string', 'null']],
                        ],
                        'required'    => ['id', 'thumbnail', 'preview', 'normal', 'original'],
                    ],
                    'position'       => ['type' => 'integer'],
                ], ['id', 'sku', 'options', 'price', 'compare_at', 'on_sale', 'in_stock', 'low_stock', 'weight', 'shipping_class', 'photo_id', 'photo', 'position'])],
                // C1: option names and values.
                'options'  => ['type' => 'array', 'description' => 'The option names and their values, in first-seen order; empty for a plain product.', 'items' => self::object([
                    'name'   => ['type' => 'string'],
                    'values' => ['type' => 'array', 'items' => ['type' => 'string']],
                ], ['name', 'values'])],
            ], ['buyable', 'options', 'variants']) + ['description' => 'Left out when the listing is not for sale.'],
            'rating'       => self::object([
                'average'      => self::nullable('number', 'The average of the approved ratings to one decimal; null when there is none.'),
                'count'        => ['type' => 'integer'],
                'distribution' => ['type' => 'object', 'description' => 'One listing only: star ("5" to "1") => how many gave it.', 'additionalProperties' => ['type' => 'integer']],
            ], ['average', 'count']) + ['description' => 'With `include=rating`.'],
            'premium'      => ['type' => 'boolean'],
            'views'        => ['type' => 'integer'],
            'published_at' => self::time(),
            'updated_at'   => self::time(),
            'expires_at'   => self::time('Null when the listing never expires.'),
            'show_email'   => ['type' => 'boolean', 'description' => 'Owner and admin view.'],
            'approved'     => ['type' => 'boolean', 'description' => 'Admin view: false while it waits for moderation.'],
            'blocked'      => ['type' => 'boolean', 'description' => 'Admin view.'],
            'spam'         => ['type' => 'boolean', 'description' => 'Admin view.'],
            'ip'           => self::nullable('string', 'Admin view.'),
            'stats'        => [
                'type'                 => 'object',
                'description'          => 'Admin view: report counters.',
                'additionalProperties' => ['type' => 'integer'],
            ],
            'ext'          => $ext->schemaFor('listing'),
        ], ['id', 'url', 'status', 'title', 'description', 'category', 'price', 'location', 'contact', 'seller', 'photos']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function user(ExtensionMembers $ext): array
    {
        $private = static fn (string $type): array => ['type' => [$type, 'null'], 'description' => 'The user themself and admins.'];

        return self::object([
            'id'             => ['type' => 'integer'],
            'name'           => ['type' => 'string'],
            'username'       => self::nullable('string'),
            'url'            => ['type' => 'string'],
            'avatar'         => ['type' => 'string'],
            'is_company'     => ['type' => 'boolean'],
            'website'        => self::nullable('string'),
            'location'       => self::object([
                'country' => self::place('code', 'string'),
                'region'  => self::place('id', 'integer'),
                'city'    => self::place('id', 'integer'),
            ], ['country', 'region', 'city']),
            'listings_count' => ['type' => 'integer'],
            'registered_at'  => self::time(),
            'email'          => $private('string'),
            'phone_land'     => $private('string'),
            'phone_mobile'   => $private('string'),
            'address'        => $private('string'),
            'zip'            => $private('string'),
            'lat'            => $private('number'),
            'lng'            => $private('number'),
            'status'         => ['type' => 'string', 'enum' => UserStatus::ALL, 'description' => 'The user themself and admins. disabled: blocked; pending: not confirmed yet.'],
            'confirmed'      => $private('boolean'),
            'blocked'        => $private('boolean'),
            'last_access_at' => self::time('The user themself and admins.'),
            'last_access_ip' => $private('string'),
            'ext'            => $ext->schemaFor('user'),
        ], ['id', 'name', 'url']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function category(ExtensionMembers $ext): array
    {
        return self::object([
            'id'             => ['type' => 'integer'],
            'parent_id'      => self::nullable('integer'),
            'slug'           => ['type' => 'string'],
            'name'           => ['type' => 'string'],
            'description'    => self::nullable('string'),
            'position'       => ['type' => 'integer'],
            'listings_count' => ['type' => 'integer'],
            'price_enabled'  => ['type' => 'boolean'],
            'children'       => ['type' => 'array', 'items' => self::ref('Category'), 'description' => 'With `tree=1`.'],
            'custom_fields'  => ['type' => 'array', 'items' => self::ref('CustomField'), 'description' => 'On a single category.'],
            'cover'          => array_merge(self::object([
                'id'        => ['type' => 'integer'],
                'thumbnail' => ['type' => 'string'],
                'preview'   => ['type' => 'string'],
                'normal'    => ['type' => 'string'],
                'original'  => self::nullable('string', 'Only when the site keeps original images.'),
            ], ['id', 'thumbnail', 'preview', 'normal', 'original']), ['type' => ['object', 'null'], 'description' => 'With `include=cover`: a photo, shaped as a listing\'s, of the newest live listing in the category or below it; null when none has one.']),
            'ext'            => $ext->schemaFor('category'),
        ], ['id', 'parent_id', 'slug', 'name']);
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * GET /search/suggest and GET /search/categories.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function suggestions(): array
    {
        return [
            'SearchSuggestions' => self::object([
                'listings' => ['type' => 'array', 'items' => self::object([
                    'id'        => ['type' => 'integer'],
                    'title'     => ['type' => 'string'],
                    'url'       => ['type' => 'string', 'format' => 'uri'],
                    'thumbnail' => self::nullable('string', 'The first photo\'s thumbnail URL.'),
                    'price'     => self::nullable('string', 'The formatted price; null when the listing shows none.'),
                    'place'     => self::nullable('string', 'City and region, comma separated.'),
                ], ['id', 'title', 'url', 'thumbnail', 'price', 'place'])],
                'searches' => ['type' => 'array', 'description' => 'Searches a search plugin suggests; core sends none.', 'items' => self::object([
                    'term'        => ['type' => 'string'],
                    'count'       => ['type' => 'integer'],
                    'count_label' => ['type' => 'string', 'description' => 'The count as text in the request\'s language, e.g. "3 listings".'],
                ], ['term', 'count', 'count_label'])],
            ], ['listings', 'searches']),
            'CategorySuggestions' => self::object([
                'categories' => ['type' => 'array', 'maxItems' => 3, 'items' => self::object([
                    'id'   => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ], ['id', 'name'])],
            ], ['categories']),
        ];
    }

    private static function site(): array
    {
        return self::object([
            'name'           => ['type' => 'string'],
            'description'    => self::nullable('string'),
            'url'            => ['type' => 'string', 'format' => 'uri'],
            'mode'           => ['type' => 'string', 'enum' => ['classifieds', 'shop', 'both'], 'description' => 'What the site is for.'],
            'api_version'    => ['type' => 'string'],
            'default_locale' => ['type' => 'string'],
            'locales'        => ['type' => 'array', 'items' => self::object([
                'code'      => ['type' => 'string'],
                'name'      => ['type' => 'string'],
                'direction' => ['type' => 'string', 'enum' => ['ltr', 'rtl']],
            ], ['code', 'name', 'direction'])],
            'currency'       => self::nullable('string'),
            'timezone'       => ['type' => 'string'],
            'friendly_urls'  => ['type' => 'boolean'],
            'features'       => self::object([
                'users'        => ['type' => 'boolean', 'description' => 'Whether the site has user accounts.'],
                'registration' => ['type' => 'boolean', 'description' => 'Whether the site\'s own sign-up form is open.'],
                'comments'     => ['type' => 'boolean', 'description' => 'Whether listings take comments.'],
                'shop'         => ['type' => 'boolean', 'description' => 'Whether the site sells products: its mode is shop or both.'],
            ], ['users', 'registration', 'comments', 'shop']) + ['description' => 'What the site itself offers.'],
            'api'            => self::object([
                'registration'  => ['type' => 'boolean', 'description' => 'Whether POST /users signs up new accounts.'],
                'personal_keys' => ['type' => 'boolean', 'description' => 'Whether users may make their own keys at /account/keys.'],
                'photo_urls'    => ['type' => 'boolean', 'description' => 'Whether a listing may name photos by URL in photo_urls.'],
            ], ['registration', 'personal_keys', 'photo_urls']) + ['description' => 'What the API allows on this site.'],
            'links'          => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ], ['name', 'url', 'mode', 'api_version', 'default_locale', 'locales', 'features', 'api', 'links']);
    }

    /**
     * Static pages and menus.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function pages(): array
    {
        $summary = [
            'id'       => ['type' => 'integer'],
            'slug'     => self::nullable('string', 'The page\'s internal name.'),
            'title'    => ['type' => 'string'],
            'url'      => ['type' => 'string'],
            'locale'   => ['type' => 'string', 'description' => 'The language shown: the asked one, else the site\'s default, else the first the page has.'],
            'position' => ['type' => 'integer'],
        ];
        $link = self::object(['label' => ['type' => 'string'], 'url' => ['type' => 'string']], ['label', 'url']);

        return [
            'PageSummary' => self::object($summary, array_keys($summary)),
            'Page'        => self::object($summary + [
                'text'       => ['type' => 'string', 'description' => 'HTML, after the static_page_text filter.'],
                'meta'       => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                'updated_at' => self::nullable('string', 'RFC 3339.'),
            ], [...array_keys($summary), 'text', 'meta', 'updated_at']),
            'Menu'        => self::object([
                'id'    => ['type' => 'string', 'description' => '`main`, `footer` or a plugin\'s menu.'],
                'items' => ['type' => 'array', 'items' => $link],
            ], ['id', 'items']),
            'Seo'         => self::object([
                'for'         => ['type' => 'string', 'enum' => ['home', 'listing', 'category', 'page', 'search']],
                'locale'      => ['type' => 'string', 'description' => 'The language of the title and description.'],
                'canonical'   => ['type' => 'string'],
                'title'       => ['type' => 'string', 'description' => 'Plain text, ending with the site title except on the home page.'],
                'description' => ['type' => 'string', 'description' => 'Plain text, at most 160 characters.'],
                'robots'      => ['type' => 'string', 'description' => 'A robots meta value such as `index, follow`.'],
                'breadcrumbs' => ['type' => 'array', 'items' => $link, 'description' => 'Home first, the current page last.'],
                'hreflang'    => ['type' => 'array', 'items' => self::object(['locale' => ['type' => 'string'], 'url' => ['type' => 'string']], ['locale', 'url']), 'description' => 'Listings and pages with text in more than one language only; ends with `x-default`.'],
                'og'          => self::object([
                    'type'        => ['type' => 'string'],
                    'title'       => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'url'         => ['type' => 'string'],
                    'site_name'   => ['type' => 'string'],
                    'locale'      => ['type' => 'string'],
                    'image'       => self::nullable('string', 'The first photo of a listing.'),
                ], ['type', 'title', 'description', 'url', 'site_name', 'locale', 'image']),
            ], ['for', 'locale', 'canonical', 'title', 'description', 'robots', 'breadcrumbs', 'hreflang', 'og']),
        ];
    }

    /**
     * Sign-in, the user's own account, their sessions and keys, and sign-up.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function account(): array
    {
        $text   = static fn (int $max, int $min = 0): array => ['type' => 'string', 'minLength' => $min, 'maxLength' => $max];
        $scopes = ['type' => 'array', 'items' => ['type' => 'string']];
        $input  = static fn (array $properties, array $required = []): array => self::object($properties, $required) + ['additionalProperties' => false];

        return [
            'Warning'            => self::object(['code' => ['type' => 'string', 'enum' => Warning::CODES], 'message' => ['type' => 'string']], ['code', 'message']),
            // An OAuth 2 token answer (RFC 6749 §5.1): the members at the top level, no `data`.
            'TokenDocument'      => self::object([
                'access_token'       => ['type' => 'string', 'description' => '`sca_...`, sent as `Authorization: Bearer <token>`.'],
                'token_type'         => ['type' => 'string', 'enum' => ['Bearer']],
                'expires_in'         => ['type' => 'integer', 'description' => 'Seconds the access token lives.'],
                'scope'              => ['type' => 'string', 'description' => 'The scopes granted, space separated.'],
                'refresh_token'      => ['type' => 'string', 'description' => '`scr_...`, single use: each use returns a new one. Keep it secret.'],
                'refresh_expires_in' => ['type' => 'integer', 'description' => 'Seconds the refresh token lives unused.'],
            ], ['access_token', 'token_type', 'expires_in', 'scope']),
            'TokenRequest'       => $input([
                'grant_type'    => ['type' => 'string', 'maxLength' => 100, 'description' => '`password` or `refresh_token`; any other answers 400 `unsupported_grant_type`.'],
                'username'      => $text(255) + ['description' => 'E-mail address or username, for the password grant.'],
                'password'      => $text(4096) + ['description' => 'For the password grant.'],
                'scope'         => $text(500) + ['description' => 'Scopes to ask for, space separated; every user scope when left out.'],
                'label'         => $text(100) + ['description' => 'A name for this sign-in, such as the device, shown in the session list.'],
                'refresh_token' => $text(200) + ['description' => 'For the refresh_token grant.'],
            ], ['grant_type']),
            'SessionToken'       => self::object([
                'token'      => ['type' => 'string', 'description' => '`scs_...`, sent as the X-Shopclass-Token header with the sign-in cookie.'],
                'header'     => ['type' => 'string', 'enum' => ['X-Shopclass-Token']],
                'expires_at' => self::time(),
            ], ['token', 'header', 'expires_at']),
            'AccountInput'       => $input(self::profileMembers() + [
                'current_password' => $text(4096, 1) + ['description' => 'The account\'s password, asked again when `email` changes.'],
            ]),
            'AccountDocument'    => self::object([
                'data'     => self::ref('User'),
                'warnings' => ['type' => 'array', 'items' => self::ref('Warning')],
            ], ['data']),
            'PasswordChange'     => $input([
                'current_password' => $text(4096, 1),
                'new_password'     => $text(4096, AccountService::MIN_PASSWORD_LENGTH),
            ], ['current_password', 'new_password']),
            'Session'            => self::object([
                'id'           => ['type' => 'string', 'description' => 'The sign-in\'s id.'],
                'name'         => ['type' => 'string', 'description' => 'The label the client signed in with; may be empty.'],
                'scopes'       => $scopes,
                'last_used_at' => self::time(),
                'last_ip'      => self::nullable('string'),
                'expires_at'   => self::time(),
                'current'      => ['type' => 'boolean', 'description' => 'The sign-in making this request.'],
            ], ['id', 'name', 'scopes', 'current']),
            'PersonalKey'        => self::object([
                'id'           => ['type' => 'integer'],
                'name'         => ['type' => 'string'],
                'prefix'       => ['type' => 'string'],
                'scopes'       => $scopes,
                'status'       => ['type' => 'string', 'enum' => ['active', 'revoked', 'expired', 'disabled', 'orphaned']],
                'created_at'   => self::time(),
                'last_used_at' => self::time(),
                'expires_at'   => self::time(),
                'token'        => ['type' => 'string', 'description' => 'Only in the answer that makes the key. It is not stored, so keep it.'],
            ], ['id', 'name', 'prefix', 'scopes', 'status']),
            'PersonalKeyInput'   => $input([
                'name'       => $text(100, 1),
                'scopes'     => $scopes + ['minItems' => 1],
                'expires_at' => self::dateInput('The last day the key works, within a year, or a number of days such as 90d.', true),
                'current_password' => $text(4096, 1) + ['description' => 'The account\'s password, asked again before a key is made.'],
            ], ['name', 'scopes', 'expires_at', 'current_password']),
            'Registration'       => $input([
                'name'         => $text(AccountService::maxLength('s_name'), 1),
                'email'        => ['type' => 'string', 'format' => 'email', 'maxLength' => AccountService::maxLength('s_email')],
                'password'     => $text(4096, AccountService::MIN_PASSWORD_LENGTH),
                'username'     => $text(AccountService::maxLength('s_username')),
                'phone_land'   => $text(AccountService::maxLength('s_phone_land')),
                'phone_mobile' => $text(AccountService::maxLength('s_phone_mobile')),
            ], ['name', 'email', 'password']),
            'NewAccount' => self::object([
                'confirmed' => ['type' => 'boolean', 'description' => 'False until the link in the activation e-mail is opened.'],
            ], ['confirmed']),
        ];
    }

    /**
     * Listing, photo, comment and alert writes.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function writes(): array
    {
        $text    = static fn (int $max, int $min = 0): array => ['type' => 'string', 'minLength' => $min, 'maxLength' => $max];
        $input   = static fn (array $properties, array $required = []): array => self::object($properties, $required) + ['additionalProperties' => false];
        $saved   = static fn (string $name): array => self::object([
            'data'     => self::ref($name),
            'warnings' => ['type' => 'array', 'items' => self::ref('Warning')],
        ], ['data']);
        $locale  = ['type' => 'string', 'pattern' => '^[A-Za-z]{2,3}_[A-Za-z]{2}$'];
        $list    = ['type' => ['string', 'integer', 'array'], 'items' => ['type' => ['string', 'integer']], 'description' => 'One value, a comma list, or a list.'];
        $listing = self::listingMembers();

        return [
            'ListingInput' => $input($listing, ['category_id', 'title', 'description']),
            'ListingPatch' => $input($listing),
            'SavedListing' => $saved('Listing'),
            'PhotoToken'   => self::object([
                'token'      => ['type' => 'string', 'description' => 'Send it in photo_tokens when making the listing.'],
                'expires_at' => self::time(),
            ], ['token', 'expires_at']),
            'CommentInput' => $input(['title' => $text(200), 'body' => $text(5000) + ['description' => 'Needed unless `rating` is sent.'], 'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'description' => 'Stars. Needs a signed-in user, one rated comment per listing (409 `already_reviewed`).']]),
            'SavedComment' => $saved('Comment'),
            'AlertFilters' => $input([
                'q'           => $text(200),
                'category'    => $list,
                'country'     => $list,
                'region'      => $list,
                'city'        => $list,
                'city_area'   => $list,
                'user'        => $list,
                'locale'      => $locale,
                'price_min'   => ['type' => 'integer', 'minimum' => 0],
                'price_max'   => ['type' => 'integer', 'minimum' => 0],
                'with_photos' => ['type' => 'boolean'],
                'premium'     => ['type' => 'boolean'],
                'custom_field' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer', 'object']]],
            ]),
            'Alert'        => self::object([
                'id'         => ['type' => 'integer'],
                'filters'    => self::ref('AlertFilters'),
                'type'       => ['type' => 'string', 'description' => 'How often it mails: daily or weekly; custom for a product alert, which mails once.'],
                'active'     => ['type' => 'boolean'],
                'created_at' => self::time(),
                // C4: a product alert.
                'product'    => [
                    'type'        => ['object', 'null'],
                    'description' => 'What a back-in-stock or price-drop alert waits for; null for a saved search, whose filters are empty here.',
                    'properties'  => [
                        'listing' => ['type' => 'integer'],
                        'variant' => self::nullable('integer', 'null: any variant of the listing.'),
                        'kind'    => ['type' => 'string', 'enum' => ['stock', 'price']],
                        'below'   => self::nullable('string', 'A price alert mails when the price goes under this decimal, the price when it was set; null for a stock alert.'),
                    ],
                    'required'    => ['listing', 'variant', 'kind', 'below'],
                ],
            ], ['id', 'filters', 'type', 'active', 'product']),
            'AlertInput'   => $input(['filters' => self::ref('AlertFilters'), 'product' => self::ref('AlertProductInput')]) + ['description' => 'Send filters for a saved search, or product for a product alert.'],
            // C4: product alerts and the wishlist.
            'AlertProductInput' => $input([
                'listing' => ['type' => 'integer', 'minimum' => 1, 'description' => 'A live listing that is for sale.'],
                'variant' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'One of the listing\'s variants; leave out for any.'],
                'kind'    => ['type' => 'string', 'enum' => ['stock', 'price'], 'description' => 'stock: mail once when it is back in stock. price: mail once when it sells below today\'s price.'],
            ], ['listing', 'kind']),
            'Wishlist'          => self::object([
                'ids'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Listing ids, newest first.'],
                'count' => ['type' => 'integer'],
            ], ['ids', 'count']),
            'WishlistInput'     => $input([
                'ids' => ['type' => 'array', 'maxItems' => WishlistStore::MAX, 'items' => ['type' => 'integer', 'minimum' => 1], 'description' => 'Newest first. Listings that are not live are dropped.'],
            ], ['ids']),
            'WishlistDocument'  => self::object(['data' => self::ref('Wishlist')], ['data']),
        ];
    }

    /**
     * The members of a profile edit: the user's own, and the admin's with a few more.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function profileMembers(): array
    {
        $text     = static fn (int $max, int $min = 0): array => ['type' => 'string', 'minLength' => $min, 'maxLength' => $max];
        $optional = static fn (int $max): array => ['type' => ['string', 'null'], 'maxLength' => $max, 'description' => 'null or "" clears it.'];

        return [
            'name'         => $text(AccountService::maxLength('s_name'), 1),
            'email'        => ['type' => 'string', 'format' => 'email', 'maxLength' => AccountService::maxLength('s_email'), 'description' => 'A new address is applied once the link e-mailed to it is opened.'],
            'website'      => $optional(AccountService::maxLength('s_website')),
            'phone_land'   => $optional(AccountService::maxLength('s_phone_land')),
            'phone_mobile' => $optional(AccountService::maxLength('s_phone_mobile')),
            'country'      => ['type' => ['string', 'null'], 'pattern' => '^([A-Za-z]{2})?$', 'description' => 'Country code; null or "" clears it.'],
            'region_id'    => ['type' => ['integer', 'null'], 'minimum' => 1],
            'city_id'      => ['type' => ['integer', 'null'], 'minimum' => 1],
            'city_area'    => $optional(AccountService::maxLength('s_city_area')),
            'address'      => $optional(AccountService::maxLength('s_address')),
            'zip'          => $optional(AccountService::maxLength('s_zip')),
            'lat'          => ['type' => ['number', 'null'], 'minimum' => -90, 'maximum' => 90],
            'lng'          => ['type' => ['number', 'null'], 'minimum' => -180, 'maximum' => 180],
            'is_company'   => ['type' => 'boolean'],
        ];
    }

    /**
     * The members of a listing write: the seller's, and the admin's edit with a few more.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function listingMembers(): array
    {
        $text     = static fn (int $max, int $min = 0): array => ['type' => 'string', 'minLength' => $min, 'maxLength' => $max];
        $optional = static fn (int $max): array => ['type' => ['string', 'null'], 'maxLength' => $max, 'description' => 'null or "" clears it.'];
        $locale   = ['type' => 'string', 'pattern' => '^[A-Za-z]{2,3}_[A-Za-z]{2}$'];

        return [
            'category_id'   => ['type' => 'integer', 'minimum' => 1],
            'title'         => $text(1000) + ['description' => 'In `locale`, or the site\'s default language.'],
            'description'   => $text(20000),
            'locale'        => $locale + ['description' => 'The language of `title` and `description`.'],
            'translations'  => [
                'type'                 => 'object',
                'description'          => 'Locale => {title, description}, for other languages; null removes that language.',
                'additionalProperties' => ['type' => ['object', 'null'], 'properties' => ['title' => $text(1000), 'description' => $text(20000)], 'additionalProperties' => false],
            ],
            'price'         => [
                'type'        => ['string', 'number', 'null'],
                'pattern'     => '^[0-9]{1,15}([.][0-9]{1,6})?$',
                'minimum'     => 0,
                'description' => 'A decimal such as "12.50"; null for no price.',
            ],
            'currency'      => ['type' => 'string', 'pattern' => CurrencyCode::PATTERN],
            'country'       => ['type' => ['string', 'null'], 'pattern' => '^([A-Za-z]{2})?$', 'description' => 'Country code; null or "" clears it.'],
            'region_id'     => ['type' => ['integer', 'null'], 'minimum' => 1],
            'region'        => ['description' => 'A region name, when it has no id; null or "" clears it.'] + $optional(100),
            'city_id'       => ['type' => ['integer', 'null'], 'minimum' => 1],
            'city'          => ['description' => 'A city name, when it has no id; null or "" clears it.'] + $optional(100),
            'city_area'     => $optional(200),
            'address'       => $optional(100),
            'zip'           => $optional(15),
            'lat'           => ['type' => ['number', 'null'], 'minimum' => -90, 'maximum' => 90],
            'lng'           => ['type' => ['number', 'null'], 'minimum' => -180, 'maximum' => 180],
            'contact_phone' => $optional(45),
            'show_email'    => ['type' => 'boolean'],
            'custom_fields' => [
                'type'                 => 'object',
                'description'          => 'Custom field id => value; {from, to} for a date range; null removes it.',
                'additionalProperties' => ['type' => ['string', 'number', 'boolean', 'object', 'null']],
            ],
            'photo_tokens'  => [
                'type'        => 'array',
                'maxItems'    => 50,
                'items'       => ['type' => 'string', 'pattern' => '^[0-9a-f]{32}$'],
                'description' => 'Tokens from POST /photos, added in this order.',
            ],
            'photo_urls'    => [
                'type'        => 'array',
                'maxItems'    => 20,
                'items'       => ['type' => 'string', 'format' => 'uri', 'maxLength' => 2048],
                'description' => 'Public image addresses the site downloads, when it allows them.',
            ],
            'ext'           => ['type' => 'object', 'description' => 'Plugin members, under the plugin\'s slug.'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function problem(): array
    {
        return self::object([
            'type'     => ['type' => 'string', 'format' => 'uri'],
            'title'    => ['type' => 'string'],
            'status'   => ['type' => 'integer'],
            'detail'   => ['type' => 'string'],
            'instance' => ['type' => 'string'],
            'code'     => ['type' => 'string', 'description' => 'A stable machine-readable code. New codes may be added, so treat an unknown one as its HTTP status.', 'examples' => array_keys(Problem::CATALOGUE)],
            'errors'   => ['type' => 'array', 'items' => self::object([
                'pointer' => ['type' => 'string'],
                'code'    => ['type' => 'string', 'description' => 'A field error code. New codes may be added, so treat an unknown one as invalid.', 'examples' => Problem::FIELD_CODES],
                'message' => ['type' => 'string'],
                'in'      => ['type' => 'string', 'enum' => ['query', 'body']],
            ], ['pointer', 'code', 'message'])],
            'error'             => ['type' => 'string', 'description' => 'On POST /auth/token only: the RFC 6749 error, such as invalid_grant.'],
            'error_description' => ['type' => 'string', 'description' => 'On POST /auth/token only: the same text as detail.'],
        ], ['type', 'title', 'status', 'code']);
    }

    /**
     * A nullable `{code|id, name}` place reference.
     *
     * @return array<string,mixed>
     */
    private static function place(string $key, string $type): array
    {
        return [
            'type'       => ['object', 'null'],
            'properties' => [$key => ['type' => [$type, 'null']], 'name' => ['type' => ['string', 'null']]],
        ];
    }

    /**
     * @param array<string,mixed> $properties
     * @param string[]            $required
     *
     * @return array<string,mixed>
     *
     * @api
     */
    public static function object(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string,mixed>
     *
     * @api
     */
    public static function nullable(string $type, string $description = ''): array
    {
        $schema = ['type' => [$type, 'null']];
        if ($description !== '') {
            $schema['description'] = $description;
        }

        return $schema;
    }

    /**
     * @return array<string,mixed>
     *
     * @api
     */
    public static function time(string $description = ''): array
    {
        return self::nullable('string', $description === '' ? 'RFC 3339, UTC.' : $description . ' RFC 3339, UTC.') + ['format' => 'date-time'];
    }
}
