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

/**
 * Interface PaymentGateway
 *
 * The entire plugin-facing surface of billing. A gateway takes money and reports one
 * thing back: this order is paid. It never learns what a premium listing is, what a
 * post quota is, or how either is enforced -- core converts credits into entitlements
 * on its own, so a gateway written today keeps working against features added later.
 *
 * Core never sees a card number, an API key, or a provider's signing secret. Those
 * live in the plugin, which is what keeps the regulatory surface out of core.
 *
 * Register an implementation on init:
 *
 *     osc_add_hook('init', function () {
 *         PaymentGatewayRegistry::instance()->register(new MyGateway());
 *     });
 *
 * @package mindstellar\billing
 */
interface PaymentGateway
{
    /**
     * Stable identifier, stored on every order this gateway handles. Lower-case slug,
     * e.g. 'stripe'. Changing it after orders exist orphans them, so treat it as
     * permanent.
     */
    public function getId(): string;

    /** Display name for the admin gateway list and the user's checkout. */
    public function getName(): string;

    /**
     * ISO 4217 codes this gateway can charge in.
     *
     * @return string[]
     */
    public function getSupportedCurrencies(): array;

    /**
     * Whether the gateway has everything it needs to take a payment right now --
     * credentials present, and in whatever mode the admin selected. A gateway that
     * returns false is listed in the admin but never offered at checkout.
     */
    public function isConfigured(): bool;

    /**
     * Begin payment for $order: either a URL to send the browser to, or markup to
     * render in place.
     *
     * The order is already persisted and pending when this is called, so its id is a
     * safe correlation handle to hand the provider and read back in handleCallback().
     */
    public function createCheckout(Order $order): CheckoutIntent;

    /**
     * Interpret an inbound webhook or return-URL hit.
     *
     * **Verify the request's signature here and return ignored() when it fails.** Only
     * this plugin knows the provider's scheme, so core cannot do it; and core will
     * happily fulfil whatever a gateway reports as paid. An unverified callback is a
     * free credit generator.
     *
     * Core independently re-checks the amount and currency against the stored order
     * before fulfilling, so echo the provider's own figures in the result rather than
     * the ones expected.
     *
     * @param array $request The request payload (typically $_POST or a decoded body)
     */
    public function handleCallback(array $request): CallbackResult;
}

/* file end: ./oc-includes/osclass/classes/billing/PaymentGateway.php */
