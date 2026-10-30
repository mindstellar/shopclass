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
 * Plugins register an implementation on the `register_payment_gateways` hook, which fires
 * once, the first time anything reads the registry, so a page that never asks for payment
 * methods builds no gateway. Core looks a gateway up by the id stored on an order.
 * Deliberately mirrors mindstellar\widgets\WidgetRegistry and
 * mindstellar\fields\FieldTypeRegistry so the three read the same to a plugin author.
 *
 * Core registers bank transfer (`offline`) and cash on delivery (`cod`) while billing is
 * on; each offers itself only once the admin sets it up. With neither set up and no gateway
 * plugin, checkout is unavailable and the rest of billing works as it otherwise would.
 *
 * @package mindstellar\billing
 */
final class PaymentGatewayRegistry extends Registry
{
    /** Fired once, on the first read, with the registry, for gateways to register. */
    public const HOOK = 'register_payment_gateways';

    private bool $loaded = false;

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
        $this->load();

        return $this->entries[$id] ?? null;
    }

    /**
     * How to pay a pending order, from a gateway that offers it (a bank transfer does), or
     * null. A gateway opts in by having an instructions(Order) method; it is not part of
     * PaymentGateway, so plugin gateways written before it keep working.
     *
     * @param Order $order
     *
     * @return string|null
     */
    public function instructions(Order $order): ?string
    {
        $gateway = $this->get($order->getGateway());

        return $gateway !== null && method_exists($gateway, 'instructions') ? $gateway->instructions($order) : null;
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
        $this->load();
        $out = array();
        foreach ($this->entries as $id => $gateway) {
            if (!$gateway->isConfigured()) {
                continue;
            }
            // Delivery only exists in shop mode; the gateway stays registered for old orders.
            if ($id === gateway\CashOnDeliveryGateway::ID && !osc_is_shop_mode()) {
                continue;
            }
            if ($currency !== null && !self::anyCurrency($gateway) && !in_array($currency, $gateway->getSupportedCurrencies(), true)) {
                continue;
            }
            $out[$id] = $gateway;
        }

        return $out;
    }

    /**
     * Whether a gateway takes money in any currency, as a bank transfer or cash does. A gateway
     * opts in with an acceptsAnyCurrency() method; getSupportedCurrencies() then lists only what
     * credit packages are sold in.
     *
     * @param PaymentGateway $gateway
     *
     * @return bool
     */
    public static function anyCurrency(PaymentGateway $gateway): bool
    {
        return method_exists($gateway, 'acceptsAnyCurrency') && $gateway->acceptsAnyCurrency() === true;
    }

    /**
     * Every registered gateway, configured or not.
     *
     * @return array<string,PaymentGateway>
     */
    public function all(): array
    {
        $this->load();

        return $this->entries;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        // Set first, so a listener that reads the registry does not fire the hook again.
        $this->loaded = true;
        osc_run_hook('register_payment_gateways', $this);
    }
}

/* file end: ./oc-includes/osclass/classes/billing/PaymentGatewayRegistry.php */
