<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
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

/**
 * Core's reference gateway: bank transfer or cash, settled by hand from the admin
 * order screen. No API keys, no webhook -- the whole point is to prove the
 * PaymentGateway interface works end to end without depending on a third party.
 *
 * @package mindstellar\billing\gateway
 */
final class OfflineGateway implements PaymentGateway
{
    public function getId(): string
    {
        return 'offline';
    }

    public function getName(): string
    {
        return _m('Bank transfer');
    }

    public function getSupportedCurrencies(): array
    {
        return array(osc_billing_currency());
    }

    /**
     * Instructions are what makes this payable -- without them a buyer would have
     * nowhere to send the money, so an admin who has not written any must not have
     * this offered at checkout.
     */
    public function isConfigured(): bool
    {
        return osc_billing_offline_enabled() && osc_billing_offline_instructions() !== '';
    }

    /**
     * No redirect, no external call: the checkout page shows the admin's own
     * instructions plus the reference the buyer needs to quote when they pay.
     */
    public function createCheckout(Order $order): CheckoutIntent
    {
        $html  = '<div class="billing-offline-instructions">';
        $html .= '<p>' . nl2br(osc_esc_html(osc_billing_offline_instructions())) . '</p>';
        $html .= '<p><strong>' . osc_esc_html(sprintf(_m('Reference: #%d'), $order->getId())) . '</strong></p>';
        $html .= '</div>';

        return CheckoutIntent::html($html);
    }

    /**
     * There is no webhook for a bank transfer -- an admin settles the order by hand
     * with the existing "Mark paid" action, which exercises the exact same
     * Billing::markPaid() path a real gateway's callback would.
     */
    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored('offline gateway has no callback');
    }
}

/* file end: ./oc-includes/osclass/classes/billing/gateway/OfflineGateway.php */
