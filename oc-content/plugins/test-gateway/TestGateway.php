<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\testgateway;

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Order;
use mindstellar\billing\Orders;
use mindstellar\billing\PaymentGateway;
use mindstellar\security\SigningKey;

/**
 * A payment gateway that moves no money. The test checkout page plays the provider:
 * it signs a callback and hands it to Billing::handleCallback(), the same entry point
 * core's webhook route uses.
 */
final class TestGateway implements PaymentGateway
{
    /** Gateway id stored on every order. */
    public const ID = 'test';

    /** Settings page id, and the preference section its values live in. */
    public const PAGE = 'test-gateway';

    /** Statuses a signed callback may carry. */
    public const STATUSES = array('paid', 'declined', 'refunded');

    /** Fields covered by the signature, in signing order. */
    private const SIGNED = array('order', 'status', 'amount', 'currency', 'ref', 'ts');

    public function getId(): string
    {
        return self::ID;
    }

    /**
     * The admin's name for it, always marked as a test so no buyer mistakes it.
     *
     * @return string
     */
    public function getName(): string
    {
        return sprintf(__('%s (Test)', 'test-gateway'), (string) self::setting('name'));
    }

    /**
     * The currencies listed in settings, or the site's billing currency when none are.
     * A malformed code is skipped: the registry throws on one, and it runs on every request.
     *
     * @return string[]
     */
    public function getSupportedCurrencies(): array
    {
        $codes = array();
        foreach (explode(',', strtoupper((string) self::setting('currencies'))) as $code) {
            $code = trim($code);
            if (preg_match('/^[A-Z]{3}$/', $code)) {
                $codes[] = $code;
            }
        }

        return $codes === array() ? array(osc_billing_currency()) : array_values(array_unique($codes));
    }

    public function isConfigured(): bool
    {
        return self::enabled();
    }

    /**
     * Send the buyer to the test checkout page, or settle at once in auto mode.
     *
     * @param Order $order
     *
     * @return CheckoutIntent
     */
    public function createCheckout(Order $order): CheckoutIntent
    {
        if (self::setting('mode') !== 'auto') {
            return CheckoutIntent::redirect(self::checkoutUrl($order->getId()));
        }

        $outcome = (string) self::setting('auto_outcome');
        if ($outcome === 'paid' || $outcome === 'declined') {
            Billing::handleCallback(self::ID, self::payload($order, $outcome));
        }
        self::flashOutcome($order->getId(), $outcome, Order::STATUS_PENDING);

        return CheckoutIntent::redirect(osc_billing_orders_url());
    }

    /**
     * Read a callback. Signature, age and shape are checked here; core then checks the
     * amount and currency of a paid order against the stored one.
     *
     * @param array $request
     *
     * @return CallbackResult
     */
    public function handleCallback(array $request): CallbackResult
    {
        if (!self::enabled()) {
            return CallbackResult::ignored('test payments are switched off');
        }

        $fields = self::parse($request);
        if ($fields === null) {
            return CallbackResult::ignored('malformed callback');
        }
        if (!hash_equals(self::sign($fields), (string) $request['sig'])) {
            return CallbackResult::ignored('bad signature');
        }
        if (abs(time() - (int) $fields['ts']) > self::window()) {
            return CallbackResult::ignored('callback expired');
        }

        $orderId  = (int) $fields['order'];
        $amount   = (int) $fields['amount'];
        $currency = $fields['currency'];

        if ($fields['status'] === 'paid') {
            // Echo the signed figures; core refuses them when they differ from the order.
            return CallbackResult::paid($orderId, $fields['ref'], $amount, $currency);
        }

        // Core compares money on a paid callback only, so the other two are checked here.
        $order = Orders::find($orderId);
        if ($order === null || $order->getAmount() !== $amount || $order->getCurrency() !== $currency) {
            return CallbackResult::ignored('order does not match');
        }

        return $fields['status'] === 'refunded'
            ? CallbackResult::refunded($orderId, $fields['ref'])
            : CallbackResult::failed($orderId, 'declined at the test checkout', $fields['ref']);
    }

    /**
     * Whether test payments are on. Billing must be on too.
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return osc_billing_enabled() && (bool) self::setting('enabled');
    }

    /**
     * A signed callback for $order, as the provider would post it.
     *
     * @param Order    $order
     * @param string   $status One of STATUSES
     * @param int|null $time   Signing time, for tests
     *
     * @return array<string,string>
     */
    public static function payload(Order $order, string $status, ?int $time = null): array
    {
        $fields = array(
            'order'    => (string) $order->getId(),
            'status'   => $status,
            'amount'   => (string) $order->getAmount(),
            'currency' => $order->getCurrency(),
            'ref'      => 'test_' . $order->getId() . '_' . bin2hex(random_bytes(6)),
            'ts'       => (string) ($time ?? time()),
        );
        $fields['sig'] = self::sign($fields);

        return $fields;
    }

    /**
     * HMAC-SHA256 over the signed fields.
     *
     * @param array<string,string> $fields
     *
     * @return string
     */
    public static function sign(array $fields): string
    {
        $parts = array();
        foreach (self::SIGNED as $name) {
            $parts[] = (string) ($fields[$name] ?? '');
        }

        return hash_hmac('sha256', implode("\n", $parts), self::key());
    }

    /**
     * URL of the hosted checkout page for one order.
     *
     * @param int $orderId
     *
     * @return string
     */
    public static function checkoutUrl(int $orderId): string
    {
        return osc_route_url('test-gateway-checkout', array('order' => $orderId));
    }

    /**
     * Tell the buyer what happened to their order.
     *
     * @param int    $orderId
     * @param string $outcome paid, declined, refunded, pending or error
     * @param string $before  The order's status before the callback, so a repeated press is not reported as a success
     *
     * @return void
     */
    public static function flashOutcome(int $orderId, string $outcome, string $before): void
    {
        $order   = Orders::find($orderId);
        $status  = $order === null ? '' : $order->getStatus();
        $pending = $before === Order::STATUS_PENDING;

        if ($outcome === 'pending') {
            osc_add_flash_info_message(sprintf(__('Order #%d is left pending.', 'test-gateway'), $orderId));
        } elseif ($outcome === 'error') {
            osc_add_flash_error_message(sprintf(__('The test provider returned an error. Order #%d was not changed.', 'test-gateway'), $orderId));
        } elseif ($outcome === 'paid' && $pending && $status === Order::STATUS_PAID) {
            osc_add_flash_ok_message(sprintf(__('Test payment for order #%d succeeded. Credits added.', 'test-gateway'), $orderId));
        } elseif ($outcome === 'declined' && $pending && $status === Order::STATUS_FAILED) {
            osc_add_flash_error_message(sprintf(__('Test payment for order #%d was declined.', 'test-gateway'), $orderId));
        } elseif ($outcome === 'refunded' && $before === Order::STATUS_PAID && $status === Order::STATUS_REFUNDED) {
            osc_add_flash_info_message(sprintf(__('Order #%d was refunded. Credits removed.', 'test-gateway'), $orderId));
        } else {
            osc_add_flash_warning_message(sprintf(__('Order #%d was not changed.', 'test-gateway'), $orderId));
        }
    }

    /**
     * One saved setting, or its declared default.
     *
     * @param string $name
     *
     * @return mixed
     */
    public static function setting(string $name)
    {
        return osc_settings_value(self::PAGE, $name);
    }

    /**
     * Validate the shape of every signed field. Null when anything is missing or malformed.
     *
     * @param array $request
     *
     * @return array<string,string>|null
     */
    private static function parse(array $request): ?array
    {
        $rules = array(
            'order'    => '/^[1-9][0-9]{0,18}$/',
            'status'   => '/^(' . implode('|', self::STATUSES) . ')$/',
            'amount'   => '/^[0-9]{1,18}$/',
            'currency' => '/^[A-Z]{3}$/',
            'ref'      => '/^[A-Za-z0-9_]{1,64}$/',
            'ts'       => '/^[0-9]{1,12}$/',
            'sig'      => '/^[0-9a-f]{64}$/',
        );

        $fields = array();
        foreach ($rules as $name => $rule) {
            $value = $request[$name] ?? null;
            if (!is_string($value) || !preg_match($rule, $value)) {
                return null;
            }
            $fields[$name] = $value;
        }

        return $fields;
    }

    /**
     * How old a callback may be, in seconds.
     *
     * @return int
     */
    private static function window(): int
    {
        return max(1, (int) self::setting('window')) * 60;
    }

    /**
     * A key for this plugin only, derived from the install's signing key.
     *
     * @return string
     */
    private static function key(): string
    {
        return hash_hmac('sha256', 'test-gateway-callback', SigningKey::get());
    }
}
