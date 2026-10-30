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
use mindstellar\billing\event\CartFees;
use mindstellar\billing\event\Line;
use mindstellar\billing\Order;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\Receipts;
use mindstellar\currency\CurrencyService;

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

    /**
     * Money is paid by hand, so a cart in any currency can pay this way.
     *
     * @return bool
     */
    public function acceptsAnyCurrency(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return osc_billing_cod_enabled();
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        $html  = '<div class="billing-cod-instructions">';
        $html .= '<p>' . osc_esc_html(sprintf(_m('Pay %s when it arrives.'), CurrencyService::make()->money($order->getAmount(), $order->getCurrency()))) . '</p>';
        $html .= '<p><strong>' . osc_esc_html(sprintf(_m('Order %s'), Receipts::number($order))) . '</strong></p>';
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
     * by cash on delivery. Like the delivery charge, the fee is an amount in the cart's currency.
     */
    public static function feeLine(CartFees $event): void
    {
        $fee  = osc_billing_cod_fee();
        $cart = $event->cart();
        if (
            $fee <= 0 || !osc_billing_cod_enabled() || $cart->gateway() !== self::ID || !$cart->needsShipping()
        ) {
            return;
        }
        $event->addFee(new Line('fee.cod', _m('Cash on delivery fee'), $fee, $cart->currency()));
    }
}
