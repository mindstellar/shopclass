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
 * A gateway that can link an order to the provider's own dashboard. Core shows the
 * link on the admin order screen.
 *
 * @package mindstellar\billing
 */
interface DashboardLinkGateway extends PaymentGateway
{
    /**
     * The https URL of this order's payment in the provider's dashboard, or null when
     * there is nothing to show yet (for example, a checkout that was never started).
     *
     * @param Order $order An order of this gateway
     *
     * @return string|null
     */
    public function dashboardUrl(Order $order): ?string;
}

/* file end: ./oc-includes/osclass/classes/billing/DashboardLinkGateway.php */
