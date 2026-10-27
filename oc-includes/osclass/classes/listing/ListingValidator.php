<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\listing;

use mindstellar\form\builder\FieldValidator;
use mindstellar\security\SpamCheck;

/**
 * The listing form's own checks, for every way a listing is saved: lengths, places, price,
 * phone, the category's custom fields, and Akismet.
 */
final class ListingValidator
{
    /**
     * Widths of the t_item_location and t_item columns a submitted listing fills, from
     * struct.sql. A value wider than its column is cut short on a relaxed connection and
     * rejects the whole insert on a strict one, so each is refused by name first;
     * tests/strict-write-guards.php reads this and pins it against the live schema.
     */
    public const COLUMN_WIDTHS = array(
        's_country'       => 80,
        's_region'        => 100,
        's_city'          => 100,
        's_city_area'     => 200,
        's_address'       => 100,
        's_zip'           => 15,
        's_contact_phone' => 40,
    );

    /** Widths of the t_item_variant columns the "Sell this item" section fills, from struct.sql. */
    public const VARIANT_WIDTHS = array(
        's_sku'            => 64,
        's_shipping_class' => 40,
    );

    /** Widths of the t_item contact columns a listing form fills. */
    public const CONTACT_WIDTHS = array(
        's_contact_name'  => 100,
        's_contact_email' => 140,
    );

    /**
     * One refusal as the listing errors carry it.
     *
     * @param string $pointer a JSON pointer to the API member, '' for the listing as a whole
     * @param string $code    a short code, e.g. `too_short`, `too_long`, `invalid`, `required`
     *
     * @return array{pointer:string,code:string,message:string}
     */
    public static function entry(string $pointer, string $code, string $message): array
    {
        return array('pointer' => $pointer, 'code' => $code, 'message' => $message);
    }

    /**
     * The length of a title or description as the poster typed it. Params::purifyText()
     * stores "&" as "&amp;" and "<" as "&lt;", so counting the stored text refused a title
     * the form's own maxlength had let through.
     *
     * @param mixed $value the purified text
     */
    public static function typedLength($value): int
    {
        return mb_strlen(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
    }

    /**
     * Whether a purified title fits the title setting as typed and the column as stored.
     * Item::fitTitle() cuts at the column width, which would split an "&amp;".
     *
     * @param mixed $value the purified title
     */
    public static function fitsTitle($value, int $max): bool
    {
        return self::typedLength($value) <= $max
            && mb_strlen((string) $value, 'UTF-8') <= \Item::TITLE_WIDTH;
    }

    /**
     * Validate common inputs while editing/publishing
     *
     * Photo checks add their own notice to $notices too, as the form shows both.
     *
     * @param array<string,mixed> $aItem    the prepared listing data
     * @param string[]            $notices
     *
     * @return array<int,array{pointer:string,code:string,message:string}> empty when there are none
     */
    public function common(array $aItem, array &$notices = array()): array
    {
        $errors = array();
        if (!PhotoService::checkTypes($aItem['photos'], $notices)) {
            $errors[] = self::entry('/photos', 'invalid', _m('Image with an incorrect extension.'));
        }
        if (!PhotoService::checkSizes($aItem['photos'], $notices)) {
            $errors[] = self::entry('/photos', 'too_large', _m('Image is too big. Max. size') . osc_max_size_kb() . ' Kb');
        }

        // One title is enough, but a too-long one is refused in every language.
        $maxTitle = osc_max_characters_per_title();
        $tooLong  = array();
        $tooShort = array();
        $hasTitle = false;
        foreach ($aItem['title'] as $key => $value) {
            if (!self::fitsTitle($value, $maxTitle)) {
                $tooLong[] = self::entry('/title', 'too_long', sprintf(_m('Title too long (%s).'), osc_locale_name_of((string) $key)));
            } elseif (osc_validate_text($value)) {
                $hasTitle = true;
            } elseif (trim((string) $value) === '') {
                $tooShort[] = self::entry('/title', 'required', sprintf(_m('Add a title (%s).'), osc_locale_name_of((string) $key)));
            } else {
                $tooShort[] = self::entry('/title', 'too_short', sprintf(_m('Title too short (%s).'), osc_locale_name_of((string) $key)));
            }
        }
        $errors = array_merge($errors, $hasTitle || $tooLong !== array() ? array() : $tooShort, $tooLong);

        $maxDescription = osc_max_characters_per_description();
        $descErrors     = array();
        foreach ($aItem['description'] as $key => $value) {
            $fits = self::typedLength($value) <= $maxDescription;
            if (osc_validate_text($value, 3) && $fits) {
                $descErrors = array();
                break;
            }
            if (trim((string) $value) === '') {
                $descErrors[] = self::entry('/description', 'required', sprintf(_m('Add a description (%s).'), osc_locale_name_of((string) $key)));
            } elseif (!osc_validate_text($value, 3)) {
                $descErrors[] = self::entry('/description', 'too_short', sprintf(_m('Description too short (%s).'), osc_locale_name_of((string) $key)));
            }
            if (!$fits) {
                $descErrors[] = self::entry('/description', 'too_long', sprintf(_m('Description too long (%s).'), osc_locale_name_of((string) $key)));
            }
        }
        $errors = array_merge($errors, $descErrors);

        if (!osc_validate_category($aItem['catId'])) {
            $errors[] = self::entry('/category_id', 'invalid', _m('Category invalid.'));
        }
        if (!osc_validate_number($aItem['price'])) {
            $errors[] = self::entry('/price', 'invalid', _m('Price must be a number.'));
        }
        if ($aItem['price'] !== null && !osc_validate_max(number_format($aItem['price'], 0, '', ''), 15)) {
            $errors[] = self::entry('/price', 'too_long', _m('Price too long.'));
        }
        if ($aItem['price'] !== null && (float)$aItem['price'] < 0) {
            $errors[] = self::entry('/price', 'invalid', _m('Price must be positive number.'));
        }
        if (!osc_validate_text($aItem['countryName'], 3, false)) {
            $errors[] = self::entry('/country', 'too_short', _m('Country too short.'));
        }
        if (!osc_validate_text($aItem['regionName'], 2, false)) {
            $errors[] = self::entry('/region', 'too_short', _m('Region too short.'));
        }
        if (!osc_validate_text($aItem['cityName'], 2, false)) {
            $errors[] = self::entry('/city', 'too_short', _m('City too short.'));
        }
        if (!osc_validate_text($aItem['cityArea'], 3, false)) {
            $errors[] = self::entry('/city_area', 'too_short', _m('Municipality too short.'));
        }
        if (!osc_validate_text($aItem['address'], 3, false)) {
            $errors[] = self::entry('/address', 'too_short', _m('Address too short.'));
        }
        // The input key each capped column is filled from, the API member and what to say when
        // it does not fit. The widths themselves are COLUMN_WIDTHS, pinned against the live schema.
        $capped = array(
            's_country'       => array('countryName', '/country', _m('Country too long.')),
            's_region'        => array('regionName', '/region', _m('Region too long.')),
            's_city'          => array('cityName', '/city', _m('City too long.')),
            's_city_area'     => array('cityArea', '/city_area', _m('Municipality too long.')),
            's_address'       => array('address', '/address', _m('Address too long.')),
            's_zip'           => array('s_zip', '/zip', _m('Zip code too long.')),
            's_contact_phone' => array('contactPhone', '/contact_phone', _m('Phone too long.')),
        );
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            [$key, $pointer, $message] = $capped[$column];
            if (!osc_validate_max((string)($aItem[$key] ?? ''), $width)) {
                $errors[] = self::entry($pointer, 'too_long', $message);
            }
        }
        // Checked after Sanitize::phone() has reduced the input to digits and a leading
        // plus, so this is the format of what would be stored, not of what was typed.
        if (!osc_validate_phone((string)($aItem['contactPhone'] ?? ''), 4)) {
            $errors[] = self::entry('/contact_phone', 'invalid', _m('Phone invalid.'));
        }

        return $errors;
    }

    /**
     * Errors for the "Sell this item" members of an admin's edit. A listing that is not sold
     * has nothing to check. The default row's fields are checked under /product; option rows
     * under /product/variants/<n>, with the option rules: at most MAX_OPTIONS names and
     * MAX_ROWS rows, every row has every name, no combination twice, and a photo must be one
     * of the listing's.
     *
     * @param array<string,mixed> $product  the `product` member ListingInput::read() built
     * @param int[]               $photoIds the listing's photos; none for a new listing
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    public function product(array $product, array $photoIds = array()): array
    {
        if (empty($product['sell'])) {
            return array();
        }
        if (!array_key_exists('variants', $product) || !is_array($product['variants'])) {
            return $this->variantFields((array) ($product['fields'] ?? array()), '/product', '');
        }

        return $this->variantRows(array_values($product['variants']), array_map('intval', $photoIds));
    }

    /**
     * A listing that is sold needs a currency, or the cart cannot price it.
     *
     * @param array<string,mixed> $product  the `product` member ListingInput::read() built
     * @param mixed               $currency the listing's currency code
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    public function productCurrency(array $product, mixed $currency): array
    {
        if (empty($product['sell']) || (is_string($currency) && trim($currency) !== '')) {
            return array();
        }

        return array(self::entry('/currency', 'required', _m('Choose a currency so buyers can pay.')));
    }

    /**
     * @param array<int,mixed> $rows
     * @param int[]            $photoIds
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    private function variantRows(array $rows, array $photoIds): array
    {
        if ($rows === array()) {
            return array(self::entry('/product/variants', 'required', _m('Add at least one option row, or turn options off.')));
        }
        if (count($rows) > VariantStore::MAX_ROWS) {
            return array(self::entry('/product/variants', 'maxItems', sprintf(_m('An item has at most %d option rows.'), VariantStore::MAX_ROWS)));
        }
        $rows  = array_map(static fn ($row): array => is_array($row) ? array('options' => VariantStore::trimOptions($row['options'] ?? null)) + $row : array(), array_values($rows));
        $first = array_map('strval', array_keys($rows[0]['options']));
        $names = array();
        foreach ($first as $name) {
            $names[$name] = true;
        }
        $errors = array();
        if ($names === array()) {
            $errors[] = self::entry('/product/options', 'required', _m('Name at least one option, such as Colour or Size.'));
        }
        if (count($names) > VariantStore::MAX_OPTIONS) {
            $errors[] = self::entry('/product/options', 'maxItems', sprintf(_m('An item has at most %d options.'), VariantStore::MAX_OPTIONS));
        }
        $folded = array();
        $accent = new \mindstellar\utility\Formatting();
        foreach (array_keys($names) as $name) {
            // Case and accents fold as utf8mb4_general_ci does, which the search compares names with.
            $fold = mb_strtolower($accent->removeAccents((string) $name));
            if (isset($folded[$fold])) {
                $errors[] = self::entry('/product/options', 'taken', sprintf(_m('Option names %1$s and %2$s are the same.'), $folded[$fold], $name));
            }
            $folded[$fold] = (string) $name;
            if (trim((string) $name) === '') {
                $errors[] = self::entry('/product/options', 'required', _m('Every option needs a name.'));
            } elseif (mb_strlen((string) $name) > VariantStore::MAX_NAME) {
                $errors[] = self::entry('/product/options', 'too_long', sprintf(_m('Option name %1$s is longer than %2$d characters.'), $name, VariantStore::MAX_NAME));
            }
        }
        if ($errors !== array()) {
            return $errors;
        }

        $seen   = array();
        $values = array();
        foreach ($rows as $n => $row) {
            $base    = '/product/variants/' . $n;
            $label   = sprintf(_m('Variant %d:'), $n + 1) . ' ';
            $options = $row['options'];
            if (array_diff_key($names, $options) !== array() || array_diff_key($options, $names) !== array()) {
                $errors[] = self::entry($base . '/options', 'mismatch', $label . sprintf(_m('give a value for each option: %s.'), implode(', ', array_keys($names))));
                continue;
            }
            $bad = false;
            foreach ($options as $name => $value) {
                $value = is_scalar($value) ? (string) $value : '';
                if (trim($value) === '') {
                    $errors[] = self::entry($base . '/options', 'required', $label . sprintf(_m('%s has no value.'), $name));
                    $bad      = true;
                } elseif (mb_strlen($value) > VariantStore::MAX_VALUE) {
                    $errors[] = self::entry($base . '/options', 'too_long', $label . sprintf(_m('%1$s is longer than %2$d characters.'), $name, VariantStore::MAX_VALUE));
                    $bad      = true;
                } else {
                    $values[(string) $name][$value] = true;
                }
            }
            if (!$bad) {
                $key = array();
                foreach (array_keys($names) as $name) {
                    $key[] = (string) $options[$name];
                }
                $key = (string) json_encode($key);
                if (isset($seen[$key])) {
                    $errors[] = self::entry($base . '/options', 'taken', $label . sprintf(_m('the same options as variant %d.'), $seen[$key] + 1));
                } else {
                    $seen[$key] = $n;
                }
            }
            $fields = is_array($row['fields'] ?? null) ? $row['fields'] : array();
            $errors = array_merge($errors, $this->variantFields($fields, $base, $label));
            $photo  = $fields['fk_i_resource_id'] ?? null;
            if ($photo !== null && (!is_int($photo) || !in_array($photo, $photoIds, true))) {
                $errors[] = self::entry($base . '/photo_id', 'invalid', $label . _m('the photo must be one of this listing\'s photos.'));
            }
        }
        foreach ($values as $name => $list) {
            if (count($list) > VariantStore::MAX_VALUES) {
                $errors[] = self::entry('/product/options', 'maxItems', sprintf(_m('Option %1$s has more than %2$d values.'), $name, VariantStore::MAX_VALUES));
            }
        }

        return $errors;
    }

    /**
     * SKU and shipping class widths, and a price, stock and weight that are whole, in range numbers.
     *
     * @param array<string,mixed> $fields
     * @param string              $base   the pointer the members sit under
     * @param string              $label  what each message starts with
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    private function variantFields(array $fields, string $base, string $label): array
    {
        $errors = array();
        $capped = array(
            's_sku'            => array('/sku', _m('SKU too long.')),
            's_shipping_class' => array('/shipping_class', _m('Shipping class too long.')),
        );
        foreach (self::VARIANT_WIDTHS as $column => $width) {
            if (!osc_validate_max((string) ($fields[$column] ?? ''), $width)) {
                $errors[] = self::entry($base . $capped[$column][0], 'too_long', $label . $capped[$column][1]);
            }
        }
        foreach (array('i_price' => array('/price', _m('Price must be a positive number.')), 'i_compare_price' => array('/compare_at', _m('Old price must be a positive number.'))) as $column => $failure) {
            $price = $fields[$column] ?? null;
            if (is_string($price) || (is_int($price) && ($price < 0 || $price > 999999999999999999))) {
                $errors[] = self::entry($base . $failure[0], 'invalid', $label . $failure[1]);
            }
        }
        $stock = $fields['i_stock'] ?? null;
        if ($stock !== null && (!is_int($stock) || $stock < 0 || $stock > VariantStore::MAX_STOCK)) {
            $errors[] = self::entry($base . '/stock', 'invalid', $label . _m('Stock must be a whole number, 0 or more.'));
        }
        $weight = $fields['i_weight'] ?? null;
        if ($weight !== null && (!is_int($weight) || $weight < 0 || $weight > VariantStore::MAX_WEIGHT)) {
            $errors[] = self::entry($base . '/weight', 'invalid', $label . _m('Weight must be a whole number of grams, 0 or more.'));
        }

        return $errors;
    }

    /**
     * Errors for contact values wider than the t_item columns that hold them.
     *
     * @param array<string,mixed> $aItem
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    public function contactWidths(array $aItem): array
    {
        $errors = array();
        if (!osc_validate_max((string)($aItem['contactName'] ?? ''), self::CONTACT_WIDTHS['s_contact_name'])) {
            $errors[] = self::entry('/contact_name', 'too_long', _m('Name too long.'));
        }
        if (!osc_validate_max((string)($aItem['contactEmail'] ?? ''), self::CONTACT_WIDTHS['s_contact_email'])) {
            $errors[] = self::entry('/contact_email', 'too_long', _m('Email too long.'));
        }

        return $errors;
    }

    /**
     * Whether Akismet judges any locale of this listing to be spam.
     *
     * @param array<string,string> $title       Title per locale
     * @param array<string,string> $description Description per locale
     * @param string               $author
     * @param string               $email
     * @param string               $ip          the poster's address
     *
     * @return bool
     *
     */
    public function isSpam($title, $description, $author, $email, string $ip): bool
    {
        $spam = false;
        if (osc_akismet_key()) {
            foreach ($title as $k => $_data) {
                $_title       = $_data;
                $_description = $description[$k];
                $content      = $_title . ' ' . $_description;

                if (SpamCheck::akismet((string) $author, (string) $email, (string) $content, $ip)) {
                    $spam = true;
                    break;
                }
            }
        }

        return $spam;
    }

    /**
     * Validate Item meta field and check required fields are not empty
     *
     * @param array<int,array<string,mixed>> $_meta       The category's field definitions
     * @param array<int,mixed>|mixed          $meta        Submitted values, sanitised in place
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    public function meta(array $_meta, &$meta): array
    {
        // A category with no custom fields takes no values, or they would be stored under
        // other categories' field ids unchecked.
        if (empty($_meta)) {
            $meta = array();

            return array();
        }
        if (!is_array($meta)) {
            return array();
        }
        $types = array_column($_meta, 'e_type', 'pk_i_id');
        foreach ($types as $id => $type) {
            if ($type === 'CHECKBOX') {
                $meta[$id] ??= 0;
            }
        }
        foreach ($meta as $k => $v) {
            if (isset($types[$k])) {
                $meta[$k] = FieldValidator::sanitizeValue($types[$k], $v);
            } else {
                unset($meta[$k]);
            }
        }
        $result = FieldValidator::check($_meta, $meta);
        $meta   = $result['values'];

        return array_map(
            static fn (array $e): array => self::entry('/custom_fields/' . $e['field'], $e['code'], $e['message']),
            $result['errors']
        );
    }
}
