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

use InvalidArgumentException;
use mindstellar\base\Registry;
use mindstellar\currency\CurrencyCode;

/**
 * Registry of payment gateways.
 *
 * Plugins register an implementation on init and core looks it up by the id stored on
 * an order. Deliberately mirrors mindstellar\widgets\WidgetRegistry and
 * mindstellar\fields\FieldTypeRegistry so the three read the same to a plugin author.
 *
 * Nothing is registered by core. With no gateway plugin installed the registry is
 * empty, checkout is unavailable, and the rest of billing -- wallets, ledger,
 * entitlements, admin credit grants -- works exactly as it otherwise would.
 *
 * @package mindstellar\billing
 */
final class PaymentGatewayRegistry extends Registry
{
    /**
     * Register a gateway. Re-registering an id replaces the previous implementation,
     * which is what lets a site override a bundled gateway with its own.
     *
     * @param PaymentGateway $gateway
     *
     * @return void
     * @throws InvalidArgumentException on an invalid id or an unusable currency list
     */
    public function register(PaymentGateway $gateway): void
    {
        $id = $gateway->getId();

        if (!self::isValidId($id)) {
            throw new InvalidArgumentException('PaymentGatewayRegistry: invalid gateway id "' . $id . '"');
        }

        foreach ($gateway->getSupportedCurrencies() as $currency) {
            if (!is_string($currency) || !CurrencyCode::valid($currency)) {
                throw new InvalidArgumentException(
                    'PaymentGatewayRegistry: gateway "' . $id . '" lists a non-ISO-4217 currency'
                );
            }
        }

        $this->entries[$id] = $gateway;
    }

    /**
     * One registered gateway, or null when nothing registered that id.
     *
     * @param string $id
     *
     * @return PaymentGateway|null
     */
    public function get(string $id): ?PaymentGateway
    {
        return $this->entries[$id] ?? null;
    }

    /**
     * Gateways that are both registered and ready to take money. This is what checkout
     * offers; all() is what the admin lists, so a half-configured gateway stays visible
     * where it can be finished rather than vanishing.
     *
     * @param string|null $currency Restrict to gateways supporting this ISO 4217 code
     *
     * @return array<string,PaymentGateway>
     */
    public function available(?string $currency = null): array
    {
        $out = array();
        foreach ($this->entries as $id => $gateway) {
            if (!$gateway->isConfigured()) {
                continue;
            }
            if ($currency !== null && !in_array($currency, $gateway->getSupportedCurrencies(), true)) {
                continue;
            }
            $out[$id] = $gateway;
        }

        return $out;
    }
}

/* file end: ./oc-includes/osclass/classes/billing/PaymentGatewayRegistry.php */
