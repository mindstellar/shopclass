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

namespace mindstellar\api\write;

use mindstellar\api\serializer\Format;

/**
 * A listing body as the listing form posts it, for ListingInput::fromArray().
 *
 * An edit needs the whole form, or every field left out would be saved empty. So patch()
 * starts from the stored listing (stored()) and lays the sent members over it.
 */
final class ListingBody
{
    /** Field types ListingService stores as is; every other type is stored HTML-encoded. */
    private const RAW_TYPES = ['DATE', 'DATEINTERVAL', 'CHECKBOX', 'URL'];

    /** API member => form field, for the members copied as text. */
    private const TEXT = [
        'region'        => 'region',
        'city'          => 'city',
        'city_area'     => 'cityArea',
        'address'       => 'address',
        'zip'           => 'zip',
        'contact_phone' => 'contactPhone',
        'currency'      => 'currency',
    ];

    /**
     * @param string $decPoint      the decimal point the site's price parser expects
     * @param string $defaultLocale the locale `title` and `description` are in when none is named
     */
    public function __construct(private string $decPoint = '.', private string $defaultLocale = 'en_US')
    {
    }

    /**
     * The form for a new listing.
     *
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    public function create(array $body): array
    {
        return $this->apply(['title' => [], 'description' => [], 'meta' => []], $body);
    }

    /**
     * The whole form for an edit: the stored form with the sent members over it.
     *
     * @param array<string,mixed> $stored from stored()
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    public function patch(array $stored, array $body): array
    {
        return $this->apply($stored, $body);
    }

    /**
     * The form a stored listing would post.
     *
     * @param OwnedListing                   $listing read with its texts
     * @param array<int,array<string,mixed>> $meta    t_item_meta rows, with the field's e_type
     *
     * @return array<string,mixed>
     */
    public function stored(OwnedListing $listing, array $meta): array
    {
        $item = $listing->row();
        $form = [
            'catId'        => (string) ($item['fk_i_category_id'] ?? ''),
            'title'        => [],
            'description'  => [],
            'price'        => $this->price(Format::amount($item['i_price'] ?? null)),
            'currency'     => (string) ($item['fk_c_currency_code'] ?? ''),
            'countryId'    => (string) ($item['fk_c_country_code'] ?? ''),
            'country'      => (string) ($item['s_country'] ?? ''),
            'regionId'     => (string) ($item['fk_i_region_id'] ?? ''),
            'region'       => (string) ($item['s_region'] ?? ''),
            'cityId'       => (string) ($item['fk_i_city_id'] ?? ''),
            'city'         => (string) ($item['s_city'] ?? ''),
            'cityArea'     => (string) ($item['s_city_area'] ?? ''),
            'address'      => (string) ($item['s_address'] ?? ''),
            'zip'          => (string) ($item['s_zip'] ?? ''),
            'd_coord_lat'  => (string) ($item['d_coord_lat'] ?? ''),
            'd_coord_long' => (string) ($item['d_coord_long'] ?? ''),
            'contactPhone' => (string) ($item['s_contact_phone'] ?? ''),
            'showEmail'    => (string) ($item['b_show_email'] ?? '0'),
            'meta'         => [],
        ];
        foreach ($listing->texts() as $locale => [$title, $description]) {
            $form['title'][$locale]       = $title;
            $form['description'][$locale] = $description;
        }
        foreach ($meta as $row) {
            $field = (int) $row['fk_i_field_id'];
            $multi = (string) ($row['s_multi'] ?? '');
            $value = (string) ($row['s_value'] ?? '');
            // Posted again, an encoded value would be encoded once more on every edit.
            if (!in_array(strtoupper((string) ($row['e_type'] ?? 'TEXT')), self::RAW_TYPES, true)) {
                $value = htmlspecialchars_decode($value, ENT_QUOTES);
            }
            if ($multi === '') {
                $form['meta'][$field] = $value;
            } else {
                $form['meta'][$field]         = is_array($form['meta'][$field] ?? null) ? $form['meta'][$field] : [];
                $form['meta'][$field][$multi] = $value;
            }
        }

        return $form;
    }

    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function apply(array $form, array $body): array
    {
        if (array_key_exists('category_id', $body)) {
            $form['catId'] = (string) (int) $body['category_id'];
        }
        $locale = is_string($body['locale'] ?? null) && $body['locale'] !== '' ? $body['locale'] : $this->defaultLocale;
        $texts  = [$locale => array_intersect_key($body, ['title' => true, 'description' => true])];
        foreach ((array) ($body['translations'] ?? []) as $code => $text) {
            if (is_string($code) && is_array($text)) {
                $texts[$code] = array_intersect_key($text, ['title' => true, 'description' => true]) + ($texts[$code] ?? []);
            }
        }
        foreach ($texts as $code => $text) {
            foreach ($text as $member => $value) {
                $form[$member][$code] = (string) $value;
                $other                = $member === 'title' ? 'description' : 'title';
                $form[$other][$code]  = $form[$other][$code] ?? '';
            }
        }
        if (array_key_exists('price', $body)) {
            $form['price'] = $body['price'] === null ? '' : $this->price(self::decimal($body['price']));
        }
        foreach (self::TEXT as $member => $field) {
            if (array_key_exists($member, $body)) {
                $form[$field] = (string) ($body[$member] ?? '');
            }
        }
        if (array_key_exists('country', $body)) {
            $form['countryId'] = strtoupper((string) ($body['country'] ?? ''));
            $form['country']   = '';
        }
        foreach (['region_id' => ['regionId', 'region'], 'city_id' => ['cityId', 'city']] as $member => [$idField, $nameField]) {
            if (!array_key_exists($member, $body)) {
                continue;
            }
            $id             = (int) ($body[$member] ?? 0);
            $form[$idField] = $id > 0 ? (string) $id : '';
            if ($id > 0 || !array_key_exists($nameField, $body)) {
                $form[$nameField] = '';
            }
        }
        foreach (['lat' => 'd_coord_lat', 'lng' => 'd_coord_long'] as $member => $field) {
            if (array_key_exists($member, $body)) {
                $form[$field] = $body[$member] === null ? '' : (string) $body[$member];
            }
        }
        if (array_key_exists('show_email', $body)) {
            $form['showEmail'] = $body['show_email'] === true ? '1' : '0';
        }
        foreach ((array) ($body['custom_fields'] ?? []) as $field => $value) {
            if (!ctype_digit((string) $field)) {
                continue;
            }
            if ($value === null) {
                unset($form['meta'][(int) $field]);
            } else {
                $form['meta'][(int) $field] = is_bool($value) ? ($value ? '1' : '0') : (is_array($value) ? $value : (string) $value);
            }
        }

        return $form;
    }

    /**
     * A decimal string in the site's own format, which the listing form's parser expects.
     */
    private function price(?string $decimal): string
    {
        return $decimal === null ? '' : str_replace('.', $this->decPoint, $decimal);
    }

    /**
     * A price member as a plain decimal string: "12.5" or 12.5 both give "12.5".
     */
    private static function decimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }

        return trim((string) $value);
    }
}
