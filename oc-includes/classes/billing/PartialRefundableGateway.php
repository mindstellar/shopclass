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

/**
 * A gateway that can refund part of a paid order itself. Core then offers a partial refund
 * through the provider on the admin order screen; see Billing::refundPart().
 *
 * @package mindstellar\billing
 */
interface PartialRefundableGateway extends RefundableGateway
{
    /**
     * Ask the provider to refund $amount of $order.
     *
     * Return CallbackResult::refunded() or CallbackResult::refundPending() with this order's id
     * once the provider has accepted it, with the provider's refund id as the external ref; core
     * then issues the credit note. Return CallbackResult::ignored() with a short reason when it
     * refused; core changes nothing. Send $key as the provider's idempotency key, so a retry of
     * the same refund is not paid twice.
     *
     * @param Order  $order  A paid order of this gateway
     * @param int    $amount Micros in the order's currency, at most what is left to refund
     * @param string $key    order:<id>:refund:<n>, the same for every try of one refund
     *
     * @return CallbackResult
     */
    public function refundPart(Order $order, int $amount, string $key): CallbackResult;
}
