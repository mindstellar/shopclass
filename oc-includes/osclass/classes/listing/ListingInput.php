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

use Category;
use ItemTmpUpload;
use mindstellar\auth\Actor;
use mindstellar\currency\Money;
use mindstellar\location\LocationService;
use Params;
use Session;

/**
 * The listing form as a request carries it, read into the data ListingService takes. Fires
 * the `item_prepare_data` filter.
 */
final class ListingInput
{
    /**
     * Read the posted listing form.
     *
     * @param bool       $admin an admin posts: the owner comes from ownerId or the contact e-mail,
     *                          a new listing is active, and the expiry has no ceiling
     * @param Actor|null $actor who posts; the request's signed-in admin or user when null
     *
     * @return array<string,mixed>
     */
    public static function read(bool $admin, bool $isAdd, ?Actor $actor = null): array
    {
        $actor ??= self::sessionActor($admin);
        $data  = self::build(Params::getParamsAsArray('', false), $actor, $isAdd, Params::getFiles('photos'));
        // A signed-in seller's form posts their name and e-mail in hidden fields; plugins reading
        // the request get the account's own values instead.
        if (($data['userId'] ?? null) !== null) {
            Params::setParam('contactName', $data['contactName']);
            Params::setParam('contactEmail', $data['contactEmail']);
        }

        return $data;
    }

    /**
     * $data with the custom field values the request posted under 'meta', unless it has its own.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public static function withMeta(array $data): array
    {
        if (!isset($data['meta'])) {
            $data['meta'] = Params::getParam('meta');
        }

        return $data;
    }

    /**
     * Keep a posted listing form in the session, so it is filled in again after an error.
     * Custom field values stay kept across a clear until dropKept().
     *
     * @param array<string,mixed> $form what read() gave
     * @param mixed               $meta the posted custom field values, by field id
     */
    public static function keep(array $form, mixed $meta): void
    {
        $session = Session::getInstance();
        foreach ($form as $key => $value) {
            $session->_setForm($key, $value);
        }
        if (is_array($meta)) {
            foreach ($meta as $key => $value) {
                $session->_setForm('meta_' . $key, $value);
                $session->_keepForm('meta_' . $key);
            }
        }
    }

    /**
     * The values of the admin "Sell this item" section: what a refused save posted, or the
     * listing's variants. A new listing starts for sale in shop mode. Prices are in the site's locale format. `options` and `variants`
     * fill the option editor, `photos` its photo choice (id => label), and `errors` holds a
     * refused save's messages by row index, or under 'options' for the option names.
     *
     * @param int $itemId 0 for a new listing
     *
     * @return array{sell:bool,sku:string,price:string,compareAt:string,stock:string,weight:string,shippingClass:string,hasOptions:bool,options:array<int,array{name:string,values:array<int,string>}>,variants:array<int,array<string,mixed>>,photos:array<int,string>,errors:array<int|string,array<int,string>>}
     */
    public static function productForm(int $itemId): array
    {
        $photoIds = $itemId > 0 ? array_map(static fn (array $photo): int => (int) $photo['pk_i_id'], PhotoStore::ofItems(array($itemId))) : array();
        $kept     = Session::getInstance()->_getForm('product');
        $errors   = array();
        if (is_array($kept)) {
            $row  = (array) ($kept['fields'] ?? array());
            $sell = !empty($kept['sell']);
            $rows = is_array($kept['variants'] ?? null) ? array_values($kept['variants']) : null;
            foreach ((new ListingValidator())->product($kept, $photoIds) as $error) {
                $at            = preg_match('#^/product/variants/(\d+)/#', $error['pointer'], $m) === 1 ? (int) $m[1] : 'options';
                $errors[$at][] = $error['message'];
            }
        } else {
            $stored = $itemId > 0 ? VariantStore::ofItem($itemId) : array();
            $sell   = $itemId > 0 ? $stored !== array() : osc_is_shop_mode();
            $row    = array();
            $rows   = null;
            foreach ($stored as $one) {
                if ($one['s_options'] === null) {
                    $row = $one;
                } else {
                    $options = json_decode((string) $one['s_options'], true);
                    $rows[]  = array('options' => is_array($options) ? $options : array(), 'fields' => $one);
                }
            }
        }
        $text  = static fn ($value): string => $value === null ? '' : (string) $value;
        $price = static fn ($value): string => is_numeric($value) ? (string) osc_prepare_price($value) : $text($value);
        $shown = static fn (array $row): array => array(
            'sku'           => $text($row['s_sku'] ?? null),
            'price'         => $price($row['i_price'] ?? null),
            'compareAt'     => $price($row['i_compare_price'] ?? null),
            'stock'         => $text($row['i_stock'] ?? null),
            'weight'        => $text($row['i_weight'] ?? null),
            'shippingClass' => $text($row['s_shipping_class'] ?? null),
        );

        $options  = VariantStore::optionsOf($rows ?? array());
        $names    = array_column($options, 'name');
        $variants = array();
        foreach ($rows ?? array() as $one) {
            $values = array();
            foreach ($names as $name) {
                $values[] = (string) ($one['options'][$name] ?? '');
            }
            $fields     = (array) ($one['fields'] ?? array());
            $variants[] = array('values' => $values, 'photoId' => $text($fields['fk_i_resource_id'] ?? null)) + $shown($fields);
        }
        $photos = array();
        foreach ($photoIds as $n => $id) {
            $photos[$id] = sprintf(__('Photo %d'), $n + 1);
        }

        return array('sell' => $sell) + $shown($row) + array(
            'hasOptions' => $rows !== null,
            'options'    => $options,
            'variants'   => $variants,
            'photos'     => $photos,
            'errors'     => $errors,
        );
    }

    /**
     * Stop keeping the custom field values keep() kept.
     *
     * @param mixed $meta the posted custom field values, by field id
     */
    public static function dropKept(mixed $meta): void
    {
        if (is_array($meta)) {
            foreach ($meta as $key => $value) {
                Session::getInstance()->_dropKeepForm('meta_' . $key);
            }
        }
    }

    /**
     * The listing data for a form given as plain values, for whoever $actor is. The names are
     * the ones the listing form posts, plus 'meta' (custom field values by id) and 'photos'
     * (local file paths, which are moved into the listing and deleted). For an edit, 'id'
     * names the listing.
     *
     * Every value is trusted as an admin's would be: for an admin actor 'id' needs no secret,
     * and each photo path is read and deleted. Check data from outside before it reaches here.
     * Custom field values are purified as the form's are.
     *
     * @param array<string,mixed> $form
     *
     * @return array<string,mixed>
     */
    public static function fromArray(array $form, Actor $actor, bool $isAdd): array
    {
        $photos = array_values(array_filter((array) ($form['photos'] ?? array()), 'is_string'));
        unset($form['photos'], $form['ajax_photos']);

        $files = array('name' => array(), 'type' => array(), 'tmp_name' => array(), 'error' => array(), 'size' => array());
        foreach ($photos as $path) {
            $files['name'][]     = basename($path);
            $files['type'][]     = 'image/*';
            $files['tmp_name'][] = $path;
            $files['error'][]    = UPLOAD_ERR_OK;
            $files['size'][]     = is_file($path) ? (int) filesize($path) : 0;
        }

        $data         = self::build($form, $actor, $isAdd, $files);
        $meta         = Params::purifyText($form['meta'] ?? '');
        $data['meta'] = is_array($meta) ? $meta : array();

        return $data;
    }

    /**
     * Days a new listing runs: the category's days plus the owner's listing.runtime extra.
     * '' means unlimited; a category of 0 days never expires and is never raised.
     */
    public static function expirationDays(int $categoryId, ?int $userId): int|string
    {
        $category     = Category::getInstance()->findByPrimaryKey($categoryId);
        $categoryDays = (int) ($category['i_expiration_days'] ?? 0);
        if ($categoryDays <= 0) {
            return $categoryDays;
        }
        $extra = osc_item_extra_runtime_days($userId);
        if ($extra === 0) {
            return $categoryDays;
        }

        return $extra === -1 ? '' : $categoryDays + $extra;
    }

    /**
     * read() from plain values, for $actor or else the signed-in user or admin of this request.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public static function fromValues(array $input, bool $admin, bool $isAdd, ?Actor $actor = null): array
    {
        return self::fromArray($input, $actor ?? self::sessionActor($admin), $isAdd);
    }

    /**
     * The "Sell this item" members of a form: `sell` (a checkbox) and the columns the form
     * sent, each as the validator reads them. A number that is not one stays text, so
     * ListingValidator::product() can refuse it. With `hasOptions` set it also carries
     * `variants`, the option rows: each row's `options` is a map of name => value, or a list
     * matched to `optionNames` as the admin form posts it. Null when the form has no such members.
     *
     * @return array{sell:bool,fields:array<string,int|string|null>,variants?:array<int,array{options:array<string,string>,fields:array<string,int|string|null>}>}|null
     */
    private static function product(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $product = array('sell' => !empty($raw['sell']), 'fields' => self::variantFields($raw));
        if (empty($raw['hasOptions'])) {
            return $product;
        }
        $names = null;
        if (is_array($raw['optionNames'] ?? null)) {
            $names = array_map(static fn ($name): string => self::optionText($name), array_values(array_slice($raw['optionNames'], 0, VariantStore::MAX_OPTIONS + 1)));
        }
        $rows = array();
        // One past the cap, so the validator can still say there are too many.
        foreach (array_slice(is_array($raw['variants'] ?? null) ? $raw['variants'] : array(), 0, VariantStore::MAX_ROWS + 1) as $row) {
            $row     = is_array($row) ? $row : array();
            $given   = is_array($row['options'] ?? null) ? $row['options'] : array();
            $options = array();
            if ($names !== null) {
                $given = array_values($given);
                foreach ($names as $k => $name) {
                    $options[$name] = self::optionText($given[$k] ?? '');
                }
            } else {
                foreach (array_slice($given, 0, VariantStore::MAX_OPTIONS + 1, true) as $name => $value) {
                    $options[self::optionText($name)] = self::optionText($value);
                }
            }
            $fields = self::variantFields($row);
            if (array_key_exists('photoId', $row)) {
                $photo                      = is_scalar($row['photoId']) ? trim((string) $row['photoId']) : '';
                $fields['fk_i_resource_id'] = $photo === '' ? null : (preg_match('/^\d{1,10}$/', $photo) === 1 ? (int) $photo : $photo);
            }
            $rows[] = array('options' => $options, 'fields' => $fields);
        }
        $product['variants'] = $rows;

        return $product;
    }

    /**
     * One row's columns, each as the validator reads them.
     *
     * @param array<string,mixed> $raw
     *
     * @return array<string,int|string|null>
     */
    private static function variantFields(array $raw): array
    {
        $text = static function (string $key) use ($raw): ?string {
            $value = isset($raw[$key]) && is_scalar($raw[$key]) ? trim((string) Params::purifyText($raw[$key])) : '';

            return $value === '' ? null : $value;
        };
        $whole = static fn (?string $value): int|string|null => $value === null ? null
            : (preg_match('/^-?\d{1,15}$/', $value) === 1 ? (int) $value : $value);

        $fields = array();
        foreach (array('sku' => 's_sku', 'shippingClass' => 's_shipping_class') as $key => $column) {
            if (array_key_exists($key, $raw)) {
                $fields[$column] = $text($key);
            }
        }
        foreach (array('price' => 'i_price', 'compareAt' => 'i_compare_price') as $key => $column) {
            if (array_key_exists($key, $raw)) {
                $price           = $text($key);
                $fields[$column] = $price === null ? null : (Money::parse($price) ?? $price);
            }
        }
        foreach (array('stock' => 'i_stock', 'weight' => 'i_weight') as $key => $column) {
            if (array_key_exists($key, $raw)) {
                $fields[$column] = $whole($text($key));
            }
        }

        return $fields;
    }

    /**
     * An option name or value as stored: tags stripped, spaces trimmed and collapsed. Not
     * entity-encoded, so "Black & White" matches as typed; every output escapes it.
     */
    private static function optionText(mixed $value): string
    {
        $value = is_scalar($value) ? strip_tags(mb_scrub((string) $value, 'UTF-8')) : '';

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function sessionActor(bool $admin): Actor
    {
        if ($admin) {
            return Actor::fromSession(true);
        }
        $ip = (string) Params::getServerParam('REMOTE_ADDR');

        // Resolves a remember-me cookie into the session identity before it is read.
        osc_is_web_user_logged_in();
        $userId = (int) Session::getInstance()->_get('userId');

        return $userId > 0 ? Actor::user($userId, $ip) : Actor::guest($ip);
    }

    /**
     * @param array<string,mixed> $form  the request, or the values given
     * @param array<string,mixed> $files photos in the $_FILES shape
     *
     * @return array<string,mixed>
     */
    private static function build(array $form, Actor $actor, bool $isAdd, array $files): array
    {
        $admin = $actor->isAdmin();
        $get   = static fn (string $key): mixed => isset($form[$key])
            && (is_scalar($form[$key]) || in_array($key, array('title', 'description'), true))
            ? Params::purifyText($form[$key]) : '';
        $int   = static fn (string $key): ?int => isset($form[$key]) ? (is_scalar($form[$key]) ? (int) $form[$key] : 0) : null;
        $aItem = array();

        $ownerId = $admin ? $int('ownerId') : null;
        $owner   = ListingPolicy::owner(
            $actor,
            $ownerId,
            (string) $get('contactEmail')
        );
        $userId = $owner['pk_i_id'] ?? null;

        if ($userId !== null) {
            $aItem['contactName']  = $owner['s_name'];
            $aItem['contactEmail'] = $owner['s_email'];
        } else {
            $aItem['contactName']  = $get('contactName');
            $aItem['contactEmail'] = $get('contactEmail');
        }
        $aItem['userId']  = $userId;
        // The owner the admin asked for, which ListingService checks still exists.
        $aItem['ownerId'] = $ownerId;

        if ($isAdd) {
            $aItem['active'] = ListingPolicy::newListingStatus($actor);
        } else {
            $aItem['secret'] = $get('secret');
            $aItem['idItem'] = (int) $int('id');
        }

        // get params
        $aItem['catId']        = $get('catId');
        $aItem['countryId']    = $get('countryId');
        $aItem['country']      = $get('country');
        $aItem['region']       = $get('region');
        $aItem['regionId']     = $get('regionId');
        $aItem['city']         = $get('city');
        $aItem['cityId']       = $get('cityId');
        $aItem['price']        = $get('price') ?: null;
        $aItem['cityArea']     = $get('cityArea');
        $aItem['address']      = $get('address');
        $aItem['currency']     = $get('currency');
        $aItem['showEmail']    = $get('showEmail') ? 1 : 0;
        $aItem['title']        = $get('title');
        // A rich editor needs its markup to survive, so Params' XSS check -- which strips
        // every tag -- is off on that path; osc_sanitize_html() is what keeps it safe, an
        // allow-list of exactly what the toolbars emit. Without it a description was stored
        // as submitted, and a <script> in one ran for every visitor who opened the listing.
        // The plain-textarea path keeps stripping everything, as it always has.
        $aItem['description']  =
            (osc_tinymce_frontend() || (defined('OC_ADMIN') && OC_ADMIN))
                ? osc_sanitize_html($form['description'] ?? '')
                : $get('description');
        $aItem['photos']       = $files;
        $ajax_photos           = isset($form['ajax_photos']) ? Params::purifyText($form['ajax_photos']) : '';
        $aItem['s_ip']         = get_ip();
        $aItem['d_coord_lat']  = $get('d_coord_lat') ?: null;
        $aItem['d_coord_long'] = $get('d_coord_long') ?: null;
        $aItem['s_zip']        = $get('zip') ?: null;
        $aItem['contactPhone'] = $get('contactPhone');

        // Photos uploaded by ajax arrive as names in uploads/temp/, to be folded in with the
        // form-uploaded ones. The name is the poster's to choose, so two things are checked
        // before it becomes a path: it is a bare filename, and it was staged under this
        // form's own upload token. Without the second, any readable image on the server could
        // be attached to a listing -- and would then be unlinked once the post finished.
        if (is_array($ajax_photos) && !empty($ajax_photos)) {
            $tmpDir = osc_content_path() . 'uploads/temp/';
            $staged = ItemTmpUpload::getInstance();
            $token  = osc_upload_token();
            // This runs before the CSRF check, so an anonymous POST decides how many
            // lookups it costs. A zero cap means unlimited, which still needs a ceiling
            // here -- this bounds the work, it is not the site's photo limit.
            $cap       = (int)osc_max_images_per_item();
            $remaining = $cap > 0 ? $cap : 100;
            foreach ($ajax_photos as $photo) {
                if ($remaining-- <= 0) {
                    break;
                }
                if (!is_string($photo) || $photo === '' || basename($photo) !== $photo) {
                    continue;
                }
                if (!$staged->belongsToToken($token, $photo) || !is_file($tmpDir . $photo)) {
                    continue;
                }
                $aItem['photos']['name'][]     = $photo;
                $aItem['photos']['type'][]     = 'image/*';
                $aItem['photos']['tmp_name'][] = $tmpDir . $photo;
                $aItem['photos']['error'][]    = UPLOAD_ERR_OK;
                $aItem['photos']['size'][]     = 0;
            }
        }

        if ($isAdd || $admin) {
            $dt_expiration = $get('dt_expiration');
            if ($dt_expiration == -1) {
                $aItem['dt_expiration'] = '';
            } elseif (
                $dt_expiration != ''
                && (
                    ctype_digit($dt_expiration)
                    || preg_match(
                        '|^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})$|',
                        $dt_expiration,
                        $match
                    )
                    || preg_match('|^([0-9]{4})-([0-9]{2})-([0-9]{2})$|', $dt_expiration, $match)
                )
            ) {
                $aItem['dt_expiration'] = $dt_expiration;
                // Unlimited runtime ('') leaves no ceiling.
                $expirationCeiling      = self::expirationDays((int) $aItem['catId'], $aItem['userId'] ?? null);
                $expirationCeiling      = $expirationCeiling === '' ? null : (int) $expirationCeiling;
                if (ctype_digit($dt_expiration)) {
                    if (!$admin && $expirationCeiling !== null && $dt_expiration > $expirationCeiling) {
                        $aItem['dt_expiration'] = $expirationCeiling;
                    }
                } else {
                    if (preg_match('|^([0-9]{4})-([0-9]{2})-([0-9]{2})$|', $dt_expiration, $match)) {
                        $aItem['dt_expiration'] .= ' 23:59:59';
                    }
                    if (
                        !$admin
                        && $expirationCeiling !== null
                        && strtotime($dt_expiration) > (time() + $expirationCeiling * 24 * 3600)
                    ) {
                        $aItem['dt_expiration'] = $expirationCeiling;
                    }
                }
            } else {
                // No expiration asked for, which is every public posting form -- the
                // runtime a seller paid for has to land here or it never applies at all.
                $aItem['dt_expiration'] = self::expirationDays((int) $aItem['catId'], $aItem['userId'] ?? null);
            }
            unset($dt_expiration);
        } else {
            $aItem['dt_expiration'] = '';
        }

        $places = LocationService::resolve([
            'countryCode' => $aItem['countryId'],
            'country'     => $aItem['country'],
            'regionId'    => $aItem['regionId'],
            'region'      => $aItem['region'],
            'cityId'      => $aItem['cityId'],
            'city'        => $aItem['city'],
        ], true);
        foreach ($places as $key => $value) {
            if ($value !== null || str_ends_with($key, 'Id')) {
                $aItem[$key] = $value;
            }
        }

        if ($aItem['cityArea'] == '') {
            $aItem['cityArea'] = null;
        }

        if ($aItem['address'] == '') {
            $aItem['address'] = null;
        }

        if ($aItem['price'] !== null) {
            // A price that is not a number after the locale's separators are read is no price.
            $aItem['price'] = Money::parse((string) $aItem['price']);
        }

        if ($aItem['catId'] == '') {
            $aItem['catId'] = 0;
        }

        // Only an admin sells a listing; for anyone else the member is ignored.
        $product = $admin ? self::product($form['product'] ?? null) : null;
        if ($product !== null) {
            $aItem['product'] = $product;
        }

        if ($aItem['currency'] == '') {
            $aItem['currency'] = null;
        }

        return osc_apply_filter('item_prepare_data', $aItem);
    }
}
