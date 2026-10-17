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

namespace mindstellar\listing;

use mindstellar\exception\InvalidException;
use mindstellar\exception\NotFoundException;
use mindstellar\job\Job;
use mindstellar\search\AlertEnvelope;
use mindstellar\search\UserAlerts;

/**
 * Back-in-stock and price-drop alerts on a product. They are t_alerts rows of type
 * \Alerts::PRODUCT holding an AlertEnvelope::product(), so the account page lists them and
 * they go with the account. Each one is sent once, by a job queued from `variant_stock_changed`
 * (0 to some) or `variant_price_changed` (a drop), and then stopped.
 */
final class ProductAlerts
{
    public const JOB = 'shop.product_alert';

    /** Alerts read and mailed per job run. */
    public const BATCH = 200;

    private UserAlerts $alerts;

    public function __construct(?UserAlerts $alerts = null)
    {
        $this->alerts = $alerts ?? new UserAlerts();
    }

    /**
     * Save a product alert for a user. The listing must be live and for sale, and the variant
     * one of its own. A price alert goes off below today's selling price: the variant's, or the
     * lowest of the listing's when no variant is named.
     *
     * @param string $kind stock or price
     *
     * @return array{status:string,alert:array<string,mixed>|null} as UserAlerts::subscribe()
     * @throws InvalidException    on a bad kind, a listing not for sale or a variant not its own
     * @throws NotFoundException   when the listing is not live
     * @throws \mindstellar\database\DbException
     */
    public function subscribe(int $userId, int $listingId, ?int $variantId, string $kind): array
    {
        if (!in_array($kind, AlertEnvelope::PRODUCT_KINDS, true)) {
            throw new InvalidException('/product/kind', 'enum', _m('The kind is stock or price.'));
        }
        $rows = $listingId > 0 ? ProductAlertStore::listingPrices($listingId) : array();
        if ($rows === array() || !ListingStatus::isLive($rows[0])) {
            throw new NotFoundException(_m('The listing was not found.'));
        }
        $prices = array();
        foreach ($rows as $row) {
            if ($row['variant_id'] !== null) {
                $price = $row['variant_price'] ?? $row['i_price'];
                $prices[(int) $row['variant_id']] = $price === null ? null : (int) $price;
            }
        }
        if ($prices === array()) {
            throw new InvalidException('/product/listing', 'invalid', _m('This listing is not for sale.'));
        }
        if ($variantId !== null && !array_key_exists($variantId, $prices)) {
            throw new InvalidException('/product/variant', 'unknown', _m('The variant is not one of this listing\'s.'));
        }
        $below = null;
        if ($kind === 'price') {
            $known = array_filter($variantId === null ? $prices : array($prices[$variantId]), static fn ($p): bool => $p !== null);
            $below = $known === array() ? 0 : min($known);
            if ($below <= 0) {
                throw new InvalidException('/product/kind', 'invalid', _m('This listing has no price to drop.'));
            }
        }

        return $this->alerts->subscribe($userId, AlertEnvelope::product($listingId, $variantId, $kind, $below), \Alerts::PRODUCT);
    }

    /**
     * `variant_stock_changed`: a variant that was sold out has stock again.
     */
    public static function stockChanged($variantId, $itemId, $from, $to): void
    {
        if (self::whole($from) === 0 && ($to === null || self::whole($to) > 0)) {
            self::queue('stock', (int) $variantId, (int) $itemId, null);
        }
    }

    /**
     * `variant_price_changed`: a variant's selling price went down.
     */
    public static function priceChanged($variantId, $itemId, $from, $to): void
    {
        $to   = self::whole($to);
        $from = self::whole($from);
        if ($to !== null && $from !== null && $to < $from) {
            self::queue('price', (int) $variantId, (int) $itemId, $to);
        }
    }

    public static function registerJobs(): void
    {
        osc_job_register_handler(self::JOB, static function (Job $job): void {
            (new self())->run($job);
        });
        osc_job_describe(self::JOB, __('Product alerts'), static fn (array $payload): string => sprintf(__('Listing #%d'), (int) ($payload['item'] ?? 0)));
    }

    /**
     * One batch of the alerts a change answers: each is mailed through `hook_email_product_alert`
     * and stopped. Nothing goes out while the listing is not live.
     *
     * @throws \mindstellar\database\DbException
     */
    public function run(Job $job): void
    {
        $kind    = (string) $job->get('kind', '');
        $itemId  = (int) $job->get('item', 0);
        $variant = (int) $job->get('variant', 0);
        $price   = self::whole($job->get('price'));
        $after   = (int) $job->get('after', 0);
        if (!in_array($kind, AlertEnvelope::PRODUCT_KINDS, true) || $itemId <= 0 || ($kind === 'price' && $price === null)) {
            return;
        }
        $rows = ProductAlertStore::waiting($kind, $itemId, $after, self::BATCH);
        if ($rows === array()) {
            return;
        }
        $item = \Item::getInstance()->findByPrimaryKey($itemId);
        if (!is_array($item) || $item === array() || !ListingStatus::isLive($item)) {
            return;
        }
        foreach ($rows as $row) {
            $product = AlertEnvelope::productOf((string) $row['s_search']);
            if ($product === null || ($product['variant'] !== null && $product['variant'] !== $variant)
                || ($kind === 'price' && $price >= $product['below'])
            ) {
                continue;
            }
            osc_run_hook('hook_email_product_alert', $row, $item, $kind, $price);
            \Alerts::getInstance()->unsub((int) $row['pk_i_id']);
        }
        if (count($rows) === self::BATCH) {
            $job->repeat(array('after' => (int) end($rows)['pk_i_id']) + $job->payload());
        }
    }

    private static function queue(string $kind, int $variantId, int $itemId, ?int $price): void
    {
        if ($variantId <= 0 || $itemId <= 0) {
            return;
        }
        osc_job_enqueue(
            self::JOB,
            array('variant' => $variantId, 'item' => $itemId, 'kind' => $kind, 'price' => $price),
            array('unique_key' => $kind . ':' . $variantId . ':' . ($price ?? ''))
        );
    }

    private static function whole(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
