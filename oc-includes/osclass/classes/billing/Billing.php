<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\billing;

use DomainException;
use Log;

/**
 * The seam between money and entitlements.
 *
 * Everything a gateway plugin can cause to happen goes through here: starting a
 * checkout, and settling an order when the provider says so. Fulfilment -- minting
 * credits, reversing them, recording the transition -- is core's, not the plugin's, so
 * a gateway cannot mint credits directly and a bug in one gateway cannot invent money.
 *
 * @package mindstellar\billing
 */
final class Billing
{
    /** Preference group (t_preference.s_section) holding the billing settings. */
    public const PREF_GROUP = 'osclass';

    /** Master switch. Off on every existing install, so an upgrade changes nothing. */
    public const PREF_ENABLED = 'billing_enabled';

    /**
     * Ask the order's gateway what the browser should do next.
     *
     * @return CheckoutIntent|null null when the gateway is unregistered or not
     *                             configured -- the caller shows "payment unavailable"
     *                             rather than a broken checkout
     */
    public static function checkout(Order $order): ?CheckoutIntent
    {
        $gateway = PaymentGatewayRegistry::instance()->get($order->getGateway());
        if ($gateway === null || !$gateway->isConfigured()) {
            return null;
        }

        return $gateway->createCheckout($order);
    }

    /**
     * Route an inbound webhook or return-URL hit to its gateway and act on the verdict.
     *
     * The gateway verifies the request's authenticity; this verifies the money. Both
     * checks have to happen, and neither can be done by the other party -- which is why
     * the amount comparison lives here rather than in a plugin that might omit it.
     *
     * @param string $gatewayId Gateway the callback route was registered for
     * @param array  $request   Request payload ($_POST, or a decoded JSON body)
     *
     * @return CallbackResult what was decided; ignored() for anything not acted on
     */
    public static function handleCallback(string $gatewayId, array $request): CallbackResult
    {
        $gateway = PaymentGatewayRegistry::instance()->get($gatewayId);
        if ($gateway === null) {
            return CallbackResult::ignored('unknown gateway');
        }

        $result = $gateway->handleCallback($request);

        switch ($result->getOutcome()) {
            case CallbackResult::OUTCOME_PAID:
                return self::settlePaid($gatewayId, $result);

            case CallbackResult::OUTCOME_FAILED:
                $order = self::resolve($gatewayId, $result);
                if ($order !== null) {
                    Orders::settle($order->getId(), Order::STATUS_FAILED, $result->getExternalRef());
                }

                return $result;

            case CallbackResult::OUTCOME_REFUNDED:
                $order = self::resolve($gatewayId, $result);
                if ($order !== null) {
                    self::refund($order);
                }

                return $result;

            default:
                return $result;
        }
    }

    /**
     * Settle an order as paid and mint its credits.
     *
     * Also the entry point for an admin approving an offline payment, so it takes an
     * Order rather than a callback -- the two paths must not diverge.
     *
     * The status transition and the credit are one transaction: an order can never be
     * marked paid without its credits landing, and the credit is keyed on the order id
     * so a retried callback cannot mint twice.
     *
     * @return bool whether this call settled the order (false if it was already settled)
     */
    public static function markPaid(Order $order, ?string $externalRef = null): bool
    {
        $settled = osc_db_transaction(static function () use ($order, $externalRef): bool {
            if (!Orders::settle($order->getId(), Order::STATUS_PAID, $externalRef)) {
                return false;
            }

            if ($order->getCredits() > 0) {
                Wallet::credit(
                    $order->getUserId(),
                    $order->getCredits(),
                    Wallet::REASON_PURCHASE,
                    'order:' . $order->getId(),
                    'order',
                    $order->getId()
                );
            }

            return true;
        });

        if ($settled) {
            self::log('paid', $order, $externalRef);
            osc_run_hook('billing_order_paid', $order->getId(), $order->getUserId(), $order->getCredits());
        }

        return $settled;
    }

    /**
     * Reverse a paid order.
     *
     * The credits come back out even if that leaves the balance negative -- see
     * Wallet::reverse(). Core never asks the provider for a refund; it records the one
     * the provider reports.
     */
    public static function refund(Order $order): bool
    {
        $reversed = osc_db_transaction(static function () use ($order): bool {
            if (!Orders::refund($order->getId())) {
                return false;
            }

            if ($order->getCredits() > 0) {
                Wallet::reverse(
                    $order->getUserId(),
                    $order->getCredits(),
                    Wallet::REASON_REFUND,
                    'order:' . $order->getId() . ':refund',
                    'order',
                    $order->getId()
                );
            }

            return true;
        });

        if ($reversed) {
            self::log('refunded', $order, $order->getExternalRef());
            osc_run_hook('billing_order_refunded', $order->getId(), $order->getUserId(), $order->getCredits());
        }

        return $reversed;
    }

    /**
     * Spend credits on a registered feature.
     *
     * The debit and the feature's own effect are one transaction: if apply() reports
     * failure, an exception is the only thing that rolls the debit back (Db::transaction
     * commits on a normal return, whatever value it carries), so failure is signalled by
     * throwing rather than returning false from inside the closure. A zero-price feature
     * skips the debit but still applies -- price and enforcement are independent.
     *
     * @param int    $userId
     * @param string $featureId Id registered with FeatureRegistry
     * @param array  $ctx       Passed to the feature's apply(); 'ref_type'/'ref_id' also
     *                          become the ledger row's reference when present
     *
     * @return bool false on an unknown feature, insufficient credit, or a failed apply --
     *              all normal outcomes, not exceptions
     */
    public static function spend(int $userId, string $featureId, array $ctx = array()): bool
    {
        $feature = FeatureRegistry::instance()->get($featureId);
        if ($feature === null) {
            return false;
        }

        $price   = $feature->price($userId);
        $refType = isset($ctx['ref_type']) ? (string) $ctx['ref_type'] : null;
        $refId   = isset($ctx['ref_id']) ? (int) $ctx['ref_id'] : null;

        try {
            $applied = osc_db_transaction(static function () use ($userId, $price, $feature, $ctx, $refType, $refId): bool {
                if ($price > 0 && !Wallet::debit($userId, $price, Wallet::REASON_SPEND, null, $refType, $refId)) {
                    return false; // insufficient credit -- nothing was written
                }

                if (!$feature->apply($userId, $ctx)) {
                    throw new DomainException('billing_feature_apply_failed');
                }

                return true;
            });
        } catch (DomainException $e) {
            return false;
        }

        if ($applied) {
            osc_run_hook('billing_feature_applied', $featureId, $userId, $price);
        }

        return $applied;
    }

    /**
     * Verify a paid callback against the stored order, then fulfil it.
     */
    private static function settlePaid(string $gatewayId, CallbackResult $result): CallbackResult
    {
        $order = self::resolve($gatewayId, $result);
        if ($order === null) {
            return CallbackResult::ignored('no matching order');
        }

        // A callback is attacker-reachable. Where the provider echoes its own figures,
        // they have to match what we asked for -- otherwise a request claiming a paid
        // order for one cent settles an order for a hundred.
        if ($result->getAmount() !== null && $result->getAmount() !== $order->getAmount()) {
            self::log('amount mismatch', $order, $result->getExternalRef());

            return CallbackResult::ignored('amount mismatch');
        }

        if (
            $result->getCurrency() !== null
            && strtoupper($result->getCurrency()) !== strtoupper($order->getCurrency())
        ) {
            self::log('currency mismatch', $order, $result->getExternalRef());

            return CallbackResult::ignored('currency mismatch');
        }

        self::markPaid($order, $result->getExternalRef());

        return $result;
    }

    /**
     * Find the order a callback refers to, by our id or by the provider's reference,
     * and confirm it belongs to the gateway the callback arrived on.
     *
     * The gateway check is what stops one installed gateway from settling another's
     * orders, whether by a bug or by a forged payload aimed at the weaker of the two.
     */
    private static function resolve(string $gatewayId, CallbackResult $result): ?Order
    {
        $order = null;

        if ($result->getOrderId() !== null) {
            $order = Orders::find($result->getOrderId());
        } elseif ($result->getExternalRef() !== null) {
            $order = Orders::findByGatewayRef($gatewayId, $result->getExternalRef());
        }

        if ($order === null || $order->getGateway() !== $gatewayId) {
            return null;
        }

        return $order;
    }

    private static function log(string $action, Order $order, ?string $externalRef): void
    {
        Log::newInstance()->insertLog(
            'billing',
            $action,
            $order->getId(),
            $order->getGateway() . ' ' . (string) $externalRef,
            'user',
            $order->getUserId()
        );
    }
}

/* file end: ./oc-includes/osclass/classes/billing/Billing.php */
