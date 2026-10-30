<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\billing\gateway;

use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Order;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\Receipts;

/**
 * Core's reference gateway: bank transfer or cash, settled by hand from the admin
 * order screen. No API keys, no webhook -- the whole point is to prove the
 * PaymentGateway interface works end to end without depending on a third party.
 *
 * @package mindstellar\billing\gateway
 */
final class OfflineGateway implements PaymentGateway
{
    public const ID = 'offline';

    /**
     * Stable identifier stored on every order this gateway handles.
     *
     * @return string
     */
    public function getId(): string
    {
        return self::ID;
    }

    /**
     * Display name for the admin gateway list and the user's checkout.
     *
     * @return string
     */
    public function getName(): string
    {
        return _m('Bank transfer');
    }

    /**
     * The billing currency, which credit packages are sold in. Carts in any currency pay this way too.
     *
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

    /**
     * Instructions are what makes this payable -- without them a buyer would have
     * nowhere to send the money, so an admin who has not written any must not have
     * this offered at checkout.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return osc_billing_offline_enabled() && osc_billing_offline_instructions() !== '';
    }

    /**
     * No redirect, no external call: the checkout page shows the admin's own
     * instructions plus the reference the buyer needs to quote when they pay.
     *
     * @param Order $order
     *
     * @return CheckoutIntent
     */
    public function createCheckout(Order $order): CheckoutIntent
    {
        $html  = '<div class="billing-offline-instructions">';
        $html .= '<p>' . nl2br(osc_esc_html(osc_billing_offline_instructions())) . '</p>';
        $html .= '<p><strong>' . osc_esc_html(sprintf(_m('Reference: %s'), Receipts::number($order))) . '</strong></p>';
        $html .= '</div>';

        return CheckoutIntent::html($html);
    }

    /**
     * What a buyer needs to pay a pending order later: the admin's instructions and the
     * reference to quote. Null once the order is no longer waiting for money.
     *
     * @param Order $order
     *
     * @return string|null
     */
    public function instructions(Order $order): ?string
    {
        $text = osc_billing_offline_instructions();
        if (!$order->isPending() || $order->getGateway() !== $this->getId() || $text === '') {
            return null;
        }

        return $text . "\n" . sprintf(_m('Reference: %s'), Receipts::number($order));
    }

    /**
     * There is no webhook for a bank transfer -- an admin settles the order by hand
     * with the existing "Mark paid" action, which exercises the exact same
     * Billing::markPaid() path a real gateway's callback would.
     *
     * @param array $request
     *
     * @return CallbackResult
     */
    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored('offline gateway has no callback');
    }
}

/* file end: ./oc-includes/osclass/classes/billing/gateway/OfflineGateway.php */
