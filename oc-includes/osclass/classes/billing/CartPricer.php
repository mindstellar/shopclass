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

namespace mindstellar\billing;

use mindstellar\listing\ListingQuery;
use mindstellar\listing\PhotoStore;
use mindstellar\listing\VariantStore;

/**
 * Prices a cart from the database. The request names variants and quantities only; every unit
 * price, stock check and total is read or worked out here. Three queries (variants, listings,
 * photos), then the cart filters in this order: `cart_needs_shipping`, `cart_shipping_methods`,
 * `cart_shipping_lines`, `cart_tax_lines`, `cart_discount_lines`, `cart_totals`, `cart_free_over`,
 * `cart_problems`. A cart that needs no shipping (all downloads) skips the shipping filters and
 * `cart_free_over`. A filter adds lines; the sums are always core's, so no filter can change an
 * item's price. Only discount lines take off, a tax line marked `included` is shown but not added,
 * and the total never goes below zero.
 */
final class CartPricer
{
    /** Most lines one cart holds. */
    public const MAX_LINES = 50;

    /** Most units of one variant per order. */
    public const MAX_QTY = 20;

    /** Most lines one filter may add. */
    private const MAX_EXTRA = 20;

    /** Up to this many left, a line reports its count. */
    private const LOW_STOCK = 5;

    /** Id of core's own shipping method. */
    public const STANDARD = 'standard';

    /** Where a `cart_problems` entry may point; any other entry is dropped. */
    public const PROBLEM_POINTERS = array('/coupon', '/address/postcode', '/address/state', '/address/country', '/shipping_method');

    /** Longest text of a `cart_problems` entry. */
    private const MAX_PROBLEM_TEXT = 200;

    /**
     * Price the cart.
     *
     * @param array<int,mixed>    $lines {variant, qty} each
     * @param array<string,mixed> $ctx   address, shipping_method, gateway, user_id, locale, coupon; all
     *                                  optional. address is the full GoodsCheckout::ADDRESS set when
     *                                  placing, or quoteAddress()'s country, state and postcode
     *
     * @throws \mindstellar\database\DbException
     */
    public function quote(array $lines, array $ctx = array()): Quote
    {
        [$wanted, $problems] = self::normalise($lines);
        $variants = VariantStore::byIds(array_keys($wanted));
        $listings = self::listings(array_values(array_unique(array_map(static fn (array $v): int => (int) $v['fk_i_item_id'], $variants))), (string) ($ctx['locale'] ?? ''));
        $photos   = self::photos(array_keys($listings));

        $currency = '';
        $items    = array();
        $subtotal = 0;
        foreach ($wanted as $variantId => [$position, $qty]) {
            $variant = $variants[$variantId] ?? null;
            $listing = $variant === null ? null : ($listings[(int) $variant['fk_i_item_id']] ?? null);
            $unit    = $variant === null ? null : ($variant['i_price'] ?? $listing['i_price'] ?? null);
            $lineCur = $listing === null ? '' : strtoupper((string) ($listing['fk_c_currency_code'] ?? ''));
            if ($listing === null || !is_numeric($unit) || $lineCur === '' || ($currency !== '' && $lineCur !== $currency)) {
                $problems[] = array('line' => $position, 'code' => 'not_buyable');
                continue;
            }
            $currency  = $lineCur;
            $unit      = (int) $unit;
            $stock     = is_numeric($variant['i_stock'] ?? null) ? (int) $variant['i_stock'] : null;
            $available = $stock === null || $stock >= $qty;
            if (!$available) {
                $problems[] = array('line' => $position, 'code' => 'out_of_stock');
            }
            $options = is_string($variant['s_options'] ?? null) ? json_decode($variant['s_options'], true) : null;
            $itemId  = (int) $listing['pk_i_id'];
            $items[] = array(
                'line'           => $position,
                'variant'        => $variantId,
                'qty'            => $qty,
                'item_id'        => $itemId,
                'title'          => $listing['title'],
                'options'        => is_array($options) && $options !== array() ? $options : null,
                'sku'            => isset($variant['s_sku']) && $variant['s_sku'] !== '' ? (string) $variant['s_sku'] : null,
                'unit'           => $unit,
                'amount'         => $unit * $qty,
                'available'      => $available,
                'stock_left'     => $stock !== null && ($stock < $qty || $stock <= self::LOW_STOCK) ? $stock : null,
                'weight'         => is_numeric($variant['i_weight'] ?? null) ? (int) $variant['i_weight'] : null,
                'shipping_class' => isset($variant['s_shipping_class']) ? (string) $variant['s_shipping_class'] : null,
                'listing'        => array(
                    'id'    => $itemId,
                    'title' => $listing['title'],
                    'item'  => array(
                        'pk_i_id'          => $itemId,
                        's_title'          => $listing['title'],
                        's_city'           => (string) ($listing['s_city'] ?? ''),
                        'fk_i_category_id' => (int) $listing['fk_i_category_id'],
                    ),
                    'photo' => self::photoFor($photos[$itemId] ?? array(), $variant['fk_i_resource_id'] ?? null),
                ),
            );
            if ($available) {
                $subtotal += $unit * $qty;
            }
        }
        if ($currency === '') {
            $currency = strtoupper((string) osc_currency());
        }
        usort($problems, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        $problems = array_map(static fn (array $p): array => $p + array('pointer' => null, 'message' => null), $problems);

        $cart = array(
            'lines'           => array_values(array_map(static fn (array $l): array => array_diff_key($l, array('listing' => true)), array_filter($items, static fn (array $l): bool => $l['available']))),
            'address'         => is_array($ctx['address'] ?? null) ? $ctx['address'] : array(),
            'shipping_method' => null,
            'gateway'         => isset($ctx['gateway']) && $ctx['gateway'] !== '' ? (string) $ctx['gateway'] : null,
            'currency'        => $currency,
            'user_id'         => (int) ($ctx['user_id'] ?? 0),
            'subtotal'        => $subtotal,
            'coupon'          => self::coupon($ctx['coupon'] ?? null),
        );

        $cart['needs_shipping'] = osc_apply_filter('cart_needs_shipping', true, $cart) !== false;
        $methods = $cart['needs_shipping'] ? self::methods(osc_apply_filter('cart_shipping_methods', array(), $cart), $currency) : array();
        $asked   = (string) ($ctx['shipping_method'] ?? '');
        $chosen  = null;
        foreach ($methods as $method) {
            if ($asked === '' ? $chosen === null : $method['id'] === $asked) {
                $chosen = $method;
            }
        }
        $cart['shipping_method'] = $chosen['id'] ?? null;

        $default  = $chosen === null ? array() : array(array(
            'id'       => 'shipping.' . $chosen['id'],
            'label'    => $chosen['label'],
            'amount'   => $chosen['amount'],
            'currency' => $currency,
            'meta'     => array('method' => $chosen['id']),
        ));
        $extra                   = array();
        $extra['shipping']       = $cart['needs_shipping'] ? self::extra(osc_apply_filter('cart_shipping_lines', $default, $cart), 'shipping', $currency) : array();
        $cart['shipping_lines']  = $extra['shipping'];
        $extra['tax']            = self::extra(osc_apply_filter('cart_tax_lines', array(), $cart), 'tax', $currency);
        $cart['tax_lines']       = $extra['tax'];
        $extra['discount']       = self::extra(osc_apply_filter('cart_discount_lines', array(), $cart), 'discount', $currency);
        $cart['discount_lines']  = $extra['discount'];

        $extra['fee'] = self::fees($cart, $subtotal, $extra);
        $totals       = self::sum($subtotal, $extra);
        $freeOver     = $cart['needs_shipping'] ? self::freeOver($subtotal, $cart) : null;
        $problems     = array_merge($problems, self::problems(osc_apply_filter('cart_problems', array(), $cart)));

        return new Quote($currency, $items, $methods, $chosen, $extra, $totals, $freeOver, $problems, $cart);
    }

    /**
     * A coupon code as the cart filters see it: trimmed and upper-cased, 1-40 of A-Z, 0-9 and
     * `-`. Anything else is no code.
     *
     * @param mixed $code
     */
    public static function coupon($code): ?string
    {
        if (!is_string($code)) {
            return null;
        }
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z0-9-]{1,40}$/', $code) === 1 ? $code : null;
    }

    /**
     * The part of an address a shopper gives before checkout, for a quote: a two-letter
     * country code, a state of up to 100 characters and a postcode of up to 20. A value that
     * does not fit, or is empty, is left out.
     *
     * @return array{country?:string,state?:string,postcode?:string}
     */
    public static function quoteAddress(string $country, string $state, string $postcode): array
    {
        $clean   = static fn (string $v): string => trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v) ?? '');
        $out     = array();
        $country = strtoupper($clean($country));
        if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            $out['country'] = $country;
        }
        foreach (array('state' => array($state, 100), 'postcode' => array($postcode, 20)) as $field => [$value, $max]) {
            $value = $clean($value);
            if ($value !== '' && mb_strlen($value, 'UTF-8') <= $max) {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /**
     * What paying through this gateway adds, in micros: the fee lines `cart_totals` returns
     * for the quote's cart with the gateway swapped in. No queries of its own.
     */
    public function feeFor(Quote $quote, string $gatewayId): int
    {
        $cart            = $quote->cart();
        $cart['gateway'] = $gatewayId;
        $extra           = array(
            'shipping' => $cart['shipping_lines'] ?? array(),
            'tax'      => $cart['tax_lines'] ?? array(),
            'discount' => $cart['discount_lines'] ?? array(),
        );

        return (int) array_sum(array_column(self::fees($cart, (int) ($cart['subtotal'] ?? 0), $extra), 'amount'));
    }

    /**
     * The fee lines the `cart_totals` filter adds, given the totals the other lines make.
     *
     * @param array<string,mixed>                          $cart
     * @param array<string,array<int,array<string,mixed>>> $extra shipping, tax and discount lines
     *
     * @return array<int,array{id:string,label:string,amount:int,currency:string,meta:array<string,mixed>}>
     */
    private static function fees(array $cart, int $subtotal, array $extra): array
    {
        $totals         = self::sum($subtotal, $extra + array('fee' => array()));
        $totals['fees'] = array();
        $filtered       = osc_apply_filter('cart_totals', $totals, $cart);

        return self::extra(is_array($filtered) && is_array($filtered['fees'] ?? null) ? $filtered['fees'] : array(), 'fee', (string) $cart['currency']);
    }

    /**
     * Core's `cart_shipping_methods` listener: "Standard delivery" at the flat amount from the
     * billing settings, free once the subtotal reaches the free-over amount. Zero when unset.
     *
     * @param mixed               $methods
     * @param array<string,mixed> $cart
     *
     * @return mixed
     */
    public static function standardMethod($methods, $cart = array())
    {
        if (!is_array($methods)) {
            return $methods;
        }
        $flat     = osc_billing_shipping_flat();
        $freeOver = osc_billing_free_over();
        $subtotal = is_array($cart) ? (int) ($cart['subtotal'] ?? 0) : 0;
        $methods[] = array(
            'id'     => self::STANDARD,
            'label'  => _m('Standard delivery'),
            'eta'    => null,
            'amount' => $freeOver > 0 && $subtotal >= $freeOver ? 0 : $flat,
        );

        return $methods;
    }

    /**
     * The request's lines merged by variant, each at most MAX_QTY, at most MAX_LINES of them,
     * keyed by variant id with their first position. A bad entry is a problem, not an error.
     *
     * @param array<int,mixed> $lines
     *
     * @return array{0:array<int,array{0:int,1:int}>,1:array<int,array{line:int,code:string}>}
     */
    private static function normalise(array $lines): array
    {
        $wanted   = array();
        $problems = array();
        foreach (array_values($lines) as $position => $line) {
            $variant = is_array($line) ? ($line['variant'] ?? null) : null;
            $qty     = is_array($line) ? ($line['qty'] ?? 1) : null;
            $variant = is_int($variant) || (is_string($variant) && ctype_digit($variant)) ? (int) $variant : 0;
            if ($variant <= 0) {
                $problems[] = array('line' => $position, 'code' => 'not_buyable');
                continue;
            }
            $qty = is_int($qty) || (is_string($qty) && ctype_digit($qty)) ? (int) $qty : 0;
            if (isset($wanted[$variant])) {
                $qty += $wanted[$variant][1];
            } elseif (count($wanted) >= self::MAX_LINES) {
                $problems[] = array('line' => $position, 'code' => 'qty');
                continue;
            }
            if ($qty < 1 || $qty > self::MAX_QTY) {
                $problems[] = array('line' => $position, 'code' => 'qty');
                $qty        = max(1, min(self::MAX_QTY, $qty));
            }
            $wanted[$variant] = array($wanted[$variant][0] ?? $position, $qty);
        }

        return array($wanted, $problems);
    }

    /**
     * The live ones of these listings with their city and title, by id.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function listings(array $ids, string $locale): array
    {
        if ($ids === array()) {
            return array();
        }
        $rows = (new ListingQuery())->liveWithTitles($ids);
        if ($locale === '' && function_exists('osc_current_user_locale')) {
            $locale = (string) osc_current_user_locale();
        }
        $out = array();
        foreach ($rows as $row) {
            $id    = (int) $row['pk_i_id'];
            $title = trim((string) ($row['s_title'] ?? ''));
            if (!isset($out[$id]) || ($title !== '' && ($out[$id]['title'] === '' || $row['fk_c_locale_code'] === $locale))) {
                $row['title'] = $title;
                $out[$id]     = $row;
            }
        }
        foreach ($out as $id => $row) {
            if ($row['title'] === '') {
                $out[$id]['title'] = sprintf(_m('Listing #%d'), $id);
            }
        }

        return $out;
    }

    /**
     * @param int[] $itemIds
     *
     * @return array<int,array<int,array<string,mixed>>> photo rows by listing id, in upload order
     * @throws \mindstellar\database\DbException
     */
    public static function photos(array $itemIds): array
    {
        $out = array();
        foreach (PhotoStore::ofItems($itemIds) as $row) {
            $out[(int) $row['fk_i_item_id']][] = $row;
        }

        return $out;
    }

    /**
     * The variant's own photo when it has one, else the listing's first.
     *
     * @param array<int,array<string,mixed>> $photos
     * @param mixed                          $photoId
     *
     * @return array<string,mixed>|null
     */
    private static function photoFor(array $photos, $photoId): ?array
    {
        foreach ($photos as $photo) {
            if (is_numeric($photoId) && (int) $photo['pk_i_id'] === (int) $photoId) {
                return $photo;
            }
        }

        return $photos[0] ?? null;
    }

    /**
     * The shipping methods a filter returned, kept when well formed: {id, label, eta, amount}.
     *
     * @param mixed $methods
     *
     * @return array<int,array{id:string,label:string,eta:?string,amount:int}>
     */
    private static function methods($methods, string $currency): array
    {
        $out = array();
        foreach (is_array($methods) ? $methods : array() as $method) {
            if (!is_array($method) || count($out) >= self::MAX_EXTRA) {
                continue;
            }
            $id     = $method['id'] ?? null;
            $label  = is_string($method['label'] ?? null) ? trim($method['label']) : '';
            $amount = self::micros($method['amount'] ?? null);
            $cur    = $method['currency'] ?? $currency;
            if (!is_string($id) || !preg_match('/^[a-z0-9_.-]{1,64}$/', $id) || isset($out[$id]) || $label === '' || $amount === null || $amount < 0 || $cur !== $currency) {
                continue;
            }
            $out[$id] = array(
                'id'     => $id,
                'label'  => mb_substr($label, 0, 200, 'UTF-8'),
                'eta'    => is_string($method['eta'] ?? null) && $method['eta'] !== '' ? mb_substr($method['eta'], 0, 100, 'UTF-8') : null,
                'amount' => $amount,
            );
        }

        return array_values($out);
    }

    /**
     * The lines a filter returned, kept when well formed: {id, label, amount, currency, meta}.
     * A line in another currency is dropped; a discount always takes off, and any other line
     * below zero is dropped, so only a discount lowers the total.
     *
     * @param mixed $lines
     *
     * @return array<int,array{id:string,label:string,amount:int,currency:string,meta:array<string,mixed>}>
     */
    private static function extra($lines, string $kind, string $currency): array
    {
        $out = array();
        foreach (is_array($lines) ? $lines : array() as $n => $line) {
            if (!is_array($line) || count($out) >= self::MAX_EXTRA) {
                continue;
            }
            $label  = is_string($line['label'] ?? null) ? trim($line['label']) : '';
            $amount = self::micros($line['amount'] ?? null);
            $cur    = $line['currency'] ?? $currency;
            if ($label === '' || $amount === null || $cur !== $currency || ($amount < 0 && $kind !== OrderLineStore::KIND_DISCOUNT)) {
                continue;
            }
            if ($kind === OrderLineStore::KIND_DISCOUNT) {
                $amount = -abs($amount);
            }
            $id    = is_string($line['id'] ?? null) && preg_match('/^[a-z0-9_.-]{1,64}$/', $line['id']) ? $line['id'] : $kind . '.' . $n;
            $meta  = is_array($line['meta'] ?? null) ? $line['meta'] : array();
            $row   = array(
                'id'       => $id,
                'label'    => mb_substr($label, 0, 200, 'UTF-8'),
                'amount'   => $amount,
                'currency' => $currency,
                'meta'     => $meta,
            );
            if ($kind === OrderLineStore::KIND_TAX) {
                $row['included'] = ($meta['included'] ?? false) === true;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * The entries a `cart_problems` filter returned, kept when well formed: a code of 1-32 of
     * a-z and `_`, a pointer from PROBLEM_POINTERS and a message of 1-200 characters.
     *
     * @param mixed $problems
     *
     * @return array<int,array{line:null,code:string,pointer:string,message:string}>
     */
    private static function problems($problems): array
    {
        $out = array();
        foreach (is_array($problems) ? $problems : array() as $problem) {
            if (!is_array($problem) || count($out) >= self::MAX_EXTRA) {
                continue;
            }
            $code    = $problem['code'] ?? null;
            $pointer = $problem['pointer'] ?? null;
            $message = is_string($problem['message'] ?? null) ? trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $problem['message']) ?? '') : '';
            if (!is_string($code) || preg_match('/^[a-z_]{1,32}$/', $code) !== 1 || !in_array($pointer, self::PROBLEM_POINTERS, true)
                || $message === '' || mb_strlen($message, 'UTF-8') > self::MAX_PROBLEM_TEXT) {
                continue;
            }
            $out[] = array('line' => null, 'code' => $code, 'pointer' => $pointer, 'message' => $message);
        }

        return $out;
    }

    /**
     * The totals from the lines. The discount is what comes off; `tax` is the tax added on top
     * and `tax_included` the tax already in the prices, which the total leaves alone. The total is
     * never below zero.
     *
     * @param array<string,array<int,array<string,mixed>>> $extra
     *
     * @return array{subtotal:int,shipping:int,discount:int,tax:int,tax_included:int,fee:int,total:int}
     */
    private static function sum(int $subtotal, array $extra): array
    {
        $add = static fn (string $kind): int => (int) array_sum(array_column($extra[$kind] ?? array(), 'amount'));
        $tax = array(0, 0);
        foreach ($extra['tax'] ?? array() as $line) {
            $tax[($line['included'] ?? false) === true ? 1 : 0] += (int) $line['amount'];
        }
        $totals = array(
            'subtotal'     => $subtotal,
            'shipping'     => $add('shipping'),
            'discount'     => -$add('discount'),
            'tax'          => $tax[0],
            'tax_included' => $tax[1],
            'fee'          => $add('fee'),
        );
        $totals['total'] = max(0, $subtotal + $totals['shipping'] + $totals['tax'] + $totals['fee'] - $totals['discount']);

        return $totals;
    }

    /**
     * Free delivery from the settings' amount while core's flat rate is set, or from the amount a
     * `cart_free_over` filter returns: {amount, remaining} to replace it, null for none. A
     * malformed answer keeps core's; `remaining` is always worked out here from the subtotal.
     *
     * @param array<string,mixed> $cart
     *
     * @return array{amount:int,remaining:int}|null
     */
    private static function freeOver(int $subtotal, array $cart): ?array
    {
        $amount   = osc_billing_free_over();
        $freeOver = $amount <= 0 || osc_billing_shipping_flat() <= 0 ? null : array('amount' => $amount, 'remaining' => max(0, $amount - $subtotal));
        $asked    = osc_apply_filter('cart_free_over', $freeOver, $cart);
        if ($asked === null) {
            return null;
        }
        $amount = is_array($asked) ? self::micros($asked['amount'] ?? null) : null;
        if ($amount === null || $amount <= 0) {
            return $freeOver;
        }

        return array('amount' => $amount, 'remaining' => max(0, $amount - $subtotal));
    }

    /**
     * A whole number of micros, or null.
     *
     * @param mixed $value
     */
    private static function micros($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && abs($value) < 9.0e15) {
            return (int) round($value);
        }

        return null;
    }

}
