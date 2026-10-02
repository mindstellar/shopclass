<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\billing;

/**
 * A gateway that can refund a paid order itself. Core shows a Refund button on the
 * admin order screen for orders whose gateway implements this.
 *
 * @package mindstellar\billing
 */
interface RefundableGateway extends PaymentGateway
{
    /**
     * Ask the provider to refund $order in full.
     *
     * Return CallbackResult::refunded() with this order's id once the provider has
     * accepted the refund; core then marks the order refunded and takes the credits back.
     * Return CallbackResult::ignored() with a short reason when it refused or could not
     * be reached; core changes nothing and shows the reason to the admin. A thrown
     * exception is logged and also changes nothing. Use an idempotency key, so a
     * double click refunds once.
     *
     * @param Order $order A paid order of this gateway
     *
     * @return CallbackResult
     */
    public function refund(Order $order): CallbackResult;
}

/* file end: ./oc-includes/osclass/classes/billing/RefundableGateway.php */
