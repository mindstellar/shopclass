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

namespace mindstellar\billing\gateway;

use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Order;
use mindstellar\billing\PaymentGateway;
use mindstellar\currency\Money;

/**
 * Cash on delivery: the buyer pays the courier. The admin ships the order unpaid, and marking
 * it delivered marks it paid. No callback. The fee from the settings is added as a `fee` line by feeLine().
 */
final class CashOnDeliveryGateway implements PaymentGateway
{
    public const ID = 'cod';

    public function getId(): string
    {
        return self::ID;
    }

    public function getName(): string
    {
        return _m('Cash on delivery');
    }

    /**
     * @return string[]
     */
    public function getSupportedCurrencies(): array
    {
        return array(osc_billing_currency());
    }

    public function isConfigured(): bool
    {
        return osc_billing_cod_enabled();
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        $html  = '<div class="billing-cod-instructions">';
        $html .= '<p>' . osc_esc_html(sprintf(_m('Pay %s when it arrives.'), Money::format($order->getAmount(), $order->getCurrency()))) . '</p>';
        $html .= '<p><strong>' . osc_esc_html(sprintf(_m('Order %s'), osc_billing_order_number($order->getId()))) . '</strong></p>';
        $html .= '</div>';

        return CheckoutIntent::html($html);
    }

    /**
     * @param array<mixed> $request
     */
    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored('cash on delivery has no callback');
    }

    /**
     * Core's `cart_totals` listener: the cash on delivery fee as a fee line when the cart pays
     * by cash on delivery in the billing currency.
     *
     * @param mixed               $totals
     * @param array<string,mixed> $cart
     *
     * @return mixed
     */
    public static function feeLine($totals, $cart = array())
    {
        $fee = osc_billing_cod_fee();
        if (
            !is_array($totals) || !is_array($cart) || $fee <= 0 || !osc_billing_cod_enabled()
            || ($cart['gateway'] ?? null) !== self::ID || ($cart['currency'] ?? '') !== osc_billing_currency()
            || ($cart['needs_shipping'] ?? true) === false
        ) {
            return $totals;
        }
        $totals['fees']   = is_array($totals['fees'] ?? null) ? $totals['fees'] : array();
        $totals['fees'][] = array('id' => 'fee.cod', 'label' => _m('Cash on delivery fee'), 'amount' => $fee);

        return $totals;
    }
}
