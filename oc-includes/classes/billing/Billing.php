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

use DomainException;
use Log;
use mindstellar\billing\event\OrderFailed;
use mindstellar\billing\event\OrderPaid;
use mindstellar\boot\CoreServices;
use mindstellar\database\Db;
use RuntimeException;
use Throwable;

/**
 * The seam between money and entitlements.
 *
 * Everything a gateway plugin can cause to happen goes through here: starting a
 * checkout, and settling an order when the provider says so. Fulfilment -- minting
 * credits, reversing them, recording the transition -- is core's, not the plugin's, so
 * a gateway cannot mint credits directly and a bug in one gateway cannot invent money.
 *
 * @package mindstellar\billing
 */
final class Billing
{
    /** Preference group (t_preference.s_section) holding the billing settings. */
    public const PREF_GROUP = 'osclass';

    /** Master switch. Off on every existing install, so an upgrade changes nothing. */
    public const PREF_ENABLED = 'billing_enabled';

    /** Gateways paid outside the site: their unpaid goods orders keep stock for billing_hold_days. */
    public const HOLDS_STOCK = array('offline', 'cod');

    /** Daily job that cancels goods orders left unpaid past billing_hold_days. */
    public const JOB_RELEASE = 'billing.release_stock';

    /** Job queued at placement that cancels a card goods order still unpaid after billing_card_hold_minutes. */
    public const JOB_CARD_HOLD = 'billing.card_hold_expire';

    /** Order meta: the Unix time a card order's stock hold ends. Pay again moves it later. */
    public const CARD_HOLD_UNTIL = '_card_hold_until';

    /** Held orders the release job cancels per run. */
    private const RELEASE_BATCH = 100;

    /**
     * Stack of hook batches queued by deferHook(), one per spend() call currently on
     * the stack -- a plugin's apply() could itself call spend() for another feature,
     * so this has to nest rather than assume a single caller at a time.
     *
     * @var array<int,array<int,array{0:string,1:array}>>
     */
    private static array $hookBatches = array();

    /**
     * Fire $hook now, unless called from inside spend()'s own transaction -- in which
     * case it is queued and fired only once that transaction has actually committed.
     *
     * apply() runs while spend() still holds the wallet row's write lock (see
     * Wallet::debit(), called just before it in the same transaction), and a hook is
     * arbitrary plugin code with no business running while that lock is held, so
     * item_premium_on and item_bumped go through here rather than firing from inside
     * their own apply(). A caller invoking a feature's apply() directly, outside
     * spend(), sees no difference: nothing is deferring, so the hook fires in place.
     *
     * @param string $hook Hook name
     * @param array  $args Arguments spread into osc_run_hook()
     *
     * @return void
     */
    public static function deferHook(string $hook, array $args = array()): void
    {
        if (self::$hookBatches === array()) {
            osc_run_hook($hook, ...$args);

            return;
        }

        $top = count(self::$hookBatches) - 1;
        self::$hookBatches[$top][] = array($hook, $args);
    }

    /**
     * Ask the order's gateway what the browser should do next.
     *
     * A gateway that throws is logged and treated as unavailable. The order stays
     * pending, like an abandoned checkout, so a late paid callback can still settle it.
     *
     * @param Order $order
     *
     * @return CheckoutIntent|null null when the gateway is unregistered, not configured
     *                             or threw -- the caller shows "payment unavailable"
     *                             rather than a broken checkout
     */
    public static function checkout(Order $order): ?CheckoutIntent
    {
        $gateway = PaymentGatewayRegistry::getInstance()->get($order->getGateway());
        if ($gateway === null || !$gateway->isConfigured()) {
            return null;
        }

        try {
            return $gateway->createCheckout($order);
        } catch (Throwable $e) {
            error_log(sprintf(
                'Billing: checkout for order #%d through %s threw %s (code %s)',
                $order->getId(),
                $order->getGateway(),
                get_class($e),
                (string) $e->getCode()
            ));
            self::log('checkout failed', $order, get_class($e));

            return null;
        }
    }

    /**
     * Route an inbound webhook or return-URL hit to its gateway and act on the verdict.
     *
     * The gateway verifies the request's authenticity; this verifies the money. Both
     * checks have to happen, and neither can be done by the other party -- which is why
     * the amount comparison lives here rather than in a plugin that might omit it.
     *
     * @param string $gatewayId Gateway the callback route was registered for
     * @param array  $request   Request payload ($_POST, or a decoded JSON body)
     *
     * @return CallbackResult what was decided; ignored() for anything not acted on
     */
    public static function handleCallback(string $gatewayId, array $request): CallbackResult
    {
        $gateway = PaymentGatewayRegistry::getInstance()->get($gatewayId);
        if ($gateway === null) {
            // Gateways register only while billing is enabled, so this is usually an
            // "off" window rather than a bogus request -- core never got to look at
            // the payload at all. Retryable, so the provider tries again once billing
            // (and the gateway) is back, instead of marking a real payment delivered
            // and forgetting it.
            return CallbackResult::ignored('unknown gateway', true);
        }

        $result = $gateway->handleCallback($request);

        switch ($result->getOutcome()) {
            case CallbackResult::OUTCOME_PAID:
                return self::settlePaid($gatewayId, $result);

            case CallbackResult::OUTCOME_FAILED:
                $order = self::resolve($gatewayId, $result);
                if ($order !== null && OrderStore::settle($order->getId(), Order::STATUS_FAILED, $result->getExternalRef())) {
                    CoreServices::dispatcher()->dispatch(new OrderFailed($order->getId(), $order->getUserId()));
                }

                return $result;

            case CallbackResult::OUTCOME_REFUNDED:
                $order = self::resolve($gatewayId, $result);
                if ($order !== null) {
                    self::refund($order);
                }

                return $result;

            default:
                return $result;
        }
    }

    /**
     * Settle an order as paid and mint its credits.
     *
     * Also the entry point for an admin approving an offline payment, so it takes an
     * Order rather than a callback -- the two paths must not diverge.
     *
     * The status transition and the credit are one transaction: an order can never be
     * marked paid without its credits landing, and the credit is keyed on the order id
     * so a retried callback cannot mint twice.
     *
     * @param Order       $order
     * @param string|null $externalRef The gateway's own reference for the payment
     * @param bool $allowFailed Also settle an order currently `failed`, not only
     *                          `pending` -- the admin "mark paid" escape hatch only,
     *                          for a provider retrying payment after an earlier
     *                          failure. No gateway callback route may ever pass true
     *                          here; a webhook re-deciding a failed order by itself
     *                          is not the ordinary case a human confirming it is.
     *
     * @return bool whether this call settled the order (false if it was already settled)
     */
    public static function markPaid(Order $order, ?string $externalRef = null, bool $allowFailed = false): bool
    {
        $settled = Db::transaction(static function () use ($order, $externalRef, $allowFailed): bool {
            $from = $allowFailed
                ? array(Order::STATUS_PENDING, Order::STATUS_FAILED)
                : array(Order::STATUS_PENDING);
            if (!OrderStore::settle($order->getId(), Order::STATUS_PAID, $externalRef, $from)) {
                return false;
            }

            // Goods orders took their stock at placement, so this takes nothing; it is for an order
            // reopened from failed by hand. settle() holds the row, so a replayed callback stops above.
            if ($order->isGoods() && OrderStock::take($order->getId()) !== array()) {
                self::log('oversold', $order, $externalRef);
            }

            if ($order->getCredits() > 0) {
                Wallet::credit(
                    $order->getUserId(),
                    $order->getCredits(),
                    Wallet::REASON_PURCHASE,
                    'order:' . $order->getId(),
                    'order',
                    $order->getId()
                );
            }
            // Committed with the paid status, so a request that dies before the invoice still leaves the job.
            Invoices::queueForPaid($order);

            return true;
        });

        if ($settled) {
            self::log('paid', $order, $externalRef);
            // The hook runs before the e-mail, so a slow mail server cannot keep plugins from hearing about the payment.
            try {
                CoreServices::dispatcher()->dispatch(new OrderPaid($order->getId(), $order->getUserId(), $order->getCredits()));
            } finally {
                Invoices::issueOrQueue($order);
                Receipts::afterPaid($order);
            }
        }

        return $settled;
    }

    /**
     * Mark a shipped goods order delivered. A cash on delivery order is paid now: the
     * `billing_order_paid` hook and the receipt follow, as for markPaid().
     *
     * @param Order $order
     *
     * @return bool false when the order is not shipped
     */
    public static function deliver(Order $order): bool
    {
        $collected = false;
        $delivered = Db::transaction(static function () use ($order, &$collected): bool {
            if (!OrderStore::deliver($order->getId(), $collected)) {
                return false;
            }
            if ($collected) {
                Invoices::queueForPaid($order);
            }

            return true;
        });
        if (!$delivered) {
            return false;
        }
        if ($collected) {
            self::log('paid', $order, $order->getExternalRef());
            try {
                CoreServices::dispatcher()->dispatch(new OrderPaid($order->getId(), $order->getUserId(), $order->getCredits()));
            } finally {
                Invoices::issueOrQueue($order);
                Receipts::afterPaid($order);
            }
        }

        return true;
    }

    /**
     * Reverse a paid order.
     *
     * The credits come back out even if that leaves the balance negative -- see
     * Wallet::reverse(). This only records a refund; refundThroughGateway() is the
     * path that asks the provider for the money back.
     *
     * @param Order $order
     *
     * Runs under the same per-order lock as refundPart(), so a provider's refund webhook waits
     * for a part refund that is still storing its note. If the lock stays busy it goes ahead: the
     * note is still checked against the stored notes under the order row lock.
     *
     * @return bool whether this call reversed the order
     */
    public static function refund(Order $order): bool
    {
        $run = static fn (): bool => self::refundRecord($order);

        return (bool) Db::withNamedLock(self::refundLockName($order->getId()), 15, $run, $run);
    }

    /**
     * The body of refund().
     */
    private static function refundRecord(Order $order): bool
    {
        // The credit note for what is left is composed first and stored with the status write.
        $draft    = Invoices::remainderDraft($order);
        $reversed = Db::transaction(static function () use ($order, &$draft): bool {
            if (!OrderStore::refund($order->getId())) {
                return false;
            }
            if ($order->isGoods()) {
                OrderStock::release($order->getId());
            }

            if ($order->getCredits() > 0) {
                Wallet::reverse(
                    $order->getUserId(),
                    $order->getCredits(),
                    Wallet::REASON_REFUND,
                    'order:' . $order->getId() . ':refund',
                    'order',
                    $order->getId()
                );
            }

            if ($draft !== null && Invoices::storeNote($draft) === false) {
                // Another note was stored since the draft, so the remainder is issued after commit.
                $draft = false;
            }

            return true;
        });
        if ($reversed && $draft === false) {
            Invoices::issueOrQueue($order);
        }

        if ($reversed) {
            self::log('refunded', $order, $order->getExternalRef());
            osc_run_hook('billing_order_refunded', $order->getId(), $order->getUserId(), $order->getCredits());
        }

        return $reversed;
    }

    /**
     * The order's gateway when it can refund the order itself: the order is paid, no
     * refund was already sent to the provider, and its gateway is registered, configured
     * and implements RefundableGateway.
     *
     * @param Order $order
     *
     * @return RefundableGateway|null
     */
    public static function refundableGateway(Order $order): ?RefundableGateway
    {
        if (!$order->isPaid() || $order->meta(OrderStore::REFUND_REQUESTED) !== null) {
            return null;
        }

        $gateway = PaymentGatewayRegistry::getInstance()->get($order->getGateway());

        return $gateway instanceof RefundableGateway && $gateway->isConfigured() ? $gateway : null;
    }

    /**
     * The order's page in the provider's dashboard, when its gateway offers one.
     * Only an absolute https URL with a host is accepted; anything else, or a gateway
     * that throws, gives null.
     *
     * @param Order $order
     *
     * @return string|null
     */
    public static function dashboardUrl(Order $order): ?string
    {
        $gateway = PaymentGatewayRegistry::getInstance()->get($order->getGateway());
        if (!$gateway instanceof DashboardLinkGateway) {
            return null;
        }

        try {
            $url = $gateway->dashboardUrl($order);
        } catch (Throwable $e) {
            error_log(sprintf(
                'Billing: dashboard link for order #%d through %s threw %s (code %s)',
                $order->getId(),
                $order->getGateway(),
                get_class($e),
                (string) $e->getCode()
            ));

            return null;
        }

        if ($url === null || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }

        return $url;
    }

    /**
     * Ask the order's gateway to refund it, and record the refund when the provider
     * accepts it.
     *
     * Runs under a per-order lock and reads the order again first, so two presses or two
     * admins cannot refund twice. Anything other than refunded() for this same order
     * changes nothing.
     *
     * @param Order     $order
     * @param-out bool  $providerAccepted
     * @param bool|null $providerAccepted Set to true when the provider accepted the refund,
     *                                    including when it could not be recorded here
     *
     * @return CallbackResult refunded() when this call refunded the order, refundPending()
     *                        when the provider is still processing it, otherwise
     *                        ignored() with a reason the admin can read
     */
    public static function refundThroughGateway(Order $order, ?bool &$providerAccepted = null): CallbackResult
    {
        $providerAccepted = false;

        return Db::withNamedLock(
            self::refundLockName($order->getId()),
            2,
            static function () use ($order, &$providerAccepted): CallbackResult {
                return self::refundLocked($order, $providerAccepted);
            },
            static fn (): CallbackResult => CallbackResult::ignored(__('A refund for this order is already running'))
        );
    }

    /**
     * Name of the lock refundThroughGateway() holds for one order. Scoped to this
     * database, since GET_LOCK names are server-wide.
     *
     * @param int $orderId
     *
     * @return string
     */
    public static function refundLockName(int $orderId): string
    {
        $site = (defined('DB_NAME') ? DB_NAME : '') . '|' . DB_TABLE_PREFIX;

        return 'osc_refund_' . substr(md5($site), 0, 12) . '_' . $orderId;
    }

    /**
     * The body of refundThroughGateway(), run while the order's lock is held.
     *
     * @param Order $order
     * @param bool  $providerAccepted
     *
     * @return CallbackResult
     */
    private static function refundLocked(Order $order, bool &$providerAccepted): CallbackResult
    {
        $fresh = OrderStore::find($order->getId());
        if ($fresh === null) {
            return CallbackResult::ignored(__('That order no longer exists'));
        }

        $gateway = self::refundableGateway($fresh);
        if ($gateway === null) {
            if (!$fresh->isPaid()) {
                return CallbackResult::ignored(__('Only a paid order can be refunded'));
            }

            return CallbackResult::ignored($fresh->meta(OrderStore::REFUND_REQUESTED) !== null
                ? __('A refund was already sent to the payment provider. Check its dashboard, then use Record a refund.')
                : __('This payment method cannot refund from here'));
        }

        if (DocumentStore::refundedTotal($fresh->getId()) > 0) {
            // Part is already refunded, so only the rest may go back through the provider.
            if (!$gateway instanceof PartialRefundableGateway || !$fresh->isGoods()) {
                return CallbackResult::ignored(__('Part of this order was already refunded. Refund the rest in the payment provider\'s dashboard, then record it.'));
            }
            $result           = self::refundPartLocked($fresh->getId(), 0, null, '', false, true);
            $providerAccepted = $result->getOutcome() !== CallbackResult::OUTCOME_IGNORED;

            return $result;
        }

        // Marked before the call: if the provider takes the refund and anything after
        // fails, even a crash mid-call, a later press cannot send a second one.
        if (!OrderStore::markRefundRequested($fresh->getId())) {
            return CallbackResult::ignored(__('The refund could not be started. Try again.'));
        }

        try {
            $result = $gateway->refund($fresh);
        } catch (Throwable $e) {
            error_log(sprintf(
                'Billing: refund of order #%d through %s threw %s (code %s)',
                $fresh->getId(),
                $fresh->getGateway(),
                get_class($e),
                (string) $e->getCode()
            ));
            self::log('refund failed', $fresh, get_class($e));

            return CallbackResult::ignored(__('The payment provider returned an error'));
        }

        if ($result->getOutcome() === CallbackResult::OUTCOME_IGNORED) {
            // A clear no from the provider: nothing was sent, so the button comes back.
            OrderStore::markRefundRequested($fresh->getId(), false);
        }

        if ($result->getOutcome() === CallbackResult::OUTCOME_PENDING && $result->getOrderId() === $fresh->getId()) {
            // Sent and accepted: the sent mark stays so it is never sent twice, and the
            // provider's webhook refunds the order when it finishes.
            $providerAccepted = true;
            self::log('refund pending', $fresh, $result->getExternalRef());

            return $result;
        }

        if ($result->getOutcome() !== CallbackResult::OUTCOME_REFUNDED) {
            self::log('refund refused', $fresh, $result->getMessage());

            return $result->getOutcome() === CallbackResult::OUTCOME_IGNORED
                ? $result
                : CallbackResult::ignored(__('The payment provider gave an answer that does not match this order'));
        }

        if ($result->getOrderId() !== $fresh->getId()) {
            self::log('refund mismatch', $fresh, $result->getExternalRef());

            return CallbackResult::ignored(__('The payment provider gave an answer that does not match this order'));
        }

        $providerAccepted = true;

        try {
            $recorded = self::refund($fresh);
        } catch (Throwable $e) {
            error_log(sprintf(
                'Billing: order #%d was refunded at %s (%s) but recording it threw %s (code %s)',
                $fresh->getId(),
                $fresh->getGateway(),
                (string) $result->getExternalRef(),
                get_class($e),
                (string) $e->getCode()
            ));
            // The write can commit and a hook after it throw; then the refund is recorded.
            try {
                $now = OrderStore::find($fresh->getId());
            } catch (Throwable $ignored) {
                $now = null;
            }
            if ($now !== null && $now->getStatus() === Order::STATUS_REFUNDED) {
                return $result;
            }
            self::log('refund unrecorded', $fresh, $result->getExternalRef());

            return CallbackResult::ignored(
                __('Refunded at the provider but not recorded here. Record it with Record a refund.')
            );
        }

        if (!$recorded) {
            self::log('refund already recorded', $fresh, $result->getExternalRef());

            return CallbackResult::ignored(
                __('Refunded at the provider, but the order had already been marked refunded. Check the credit movements.')
            );
        }

        return $result;
    }

    /**
     * Refund part of a paid goods order with a credit note: an amount, or units of its item lines.
     * Runs under the same per-order lock as refundThroughGateway(). When the notes reach the paid
     * amount the order moves to refunded and gives back the stock it still holds, as refund() does.
     *
     * @param Order          $order
     * @param int            $amount     Micros; 0 takes the value of $lines with their tax share
     * @param array<int,int> $lines      Item line id => units refunded
     * @param string         $reason     Plain text, printed on the credit note
     * @param bool           $restock    Put the refunded units back in stock
     * @param bool           $viaGateway Have the order's gateway send the money back; else only record it
     *
     * @return CallbackResult refunded(), or refundPending() from the provider, once the credit note
     *                        is issued; otherwise ignored() with a reason the admin can read
     */
    public static function refundPart(Order $order, int $amount, array $lines, string $reason, bool $restock, bool $viaGateway): CallbackResult
    {
        $clean = array();
        foreach ($lines as $lineId => $qty) {
            if (is_numeric($lineId) && (int) $lineId > 0 && is_numeric($qty) && (int) $qty > 0) {
                $clean[(int) $lineId] = (int) $qty;
            }
        }

        return Db::withNamedLock(
            self::refundLockName($order->getId()),
            2,
            static fn (): CallbackResult => self::refundPartLocked($order->getId(), $amount, $clean, $reason, $restock, $viaGateway),
            static fn (): CallbackResult => CallbackResult::ignored(__('A refund for this order is already running'))
        );
    }

    /**
     * The order's gateway when it can refund part of the order itself: a paid goods order with no
     * full refund sent, and a configured gateway that implements PartialRefundableGateway.
     *
     * @param Order $order
     *
     * @return PartialRefundableGateway|null
     */
    public static function partRefundableGateway(Order $order): ?PartialRefundableGateway
    {
        if (!$order->isGoods()) {
            return null;
        }
        $gateway = self::refundableGateway($order);

        return $gateway instanceof PartialRefundableGateway ? $gateway : null;
    }

    /**
     * The body of refundPart(), run while the order's lock is held.
     *
     * @param array<int,int>|null $lines Null refunds every unit and all money not refunded yet
     */
    private static function refundPartLocked(int $orderId, int $amount, ?array $lines, string $reason, bool $restock, bool $viaGateway): CallbackResult
    {
        $order = OrderStore::find($orderId);
        if ($order === null) {
            return CallbackResult::ignored(__('That order no longer exists'));
        }
        if (!$order->isPaid()) {
            return CallbackResult::ignored(__('Only a paid order can be refunded'));
        }
        if (!$order->isGoods()) {
            return CallbackResult::ignored(__('Only a shop order can be refunded in part'));
        }
        $invoice = DocumentStore::invoiceOf($orderId);
        if ($invoice === null) {
            return CallbackResult::ignored(__('This order has no invoice, so it can only be refunded in full'));
        }
        $left = $invoice->total - DocumentStore::refundedTotal($orderId);
        if ($lines === null) {
            $amount = $left;
        } elseif ($amount < 1 && $lines !== array()) {
            $amount = min($left, (int) Invoices::linesAmount($orderId, $lines));
        }
        if ($amount < 1) {
            return CallbackResult::ignored(__('Enter an amount or choose what to refund'));
        }
        if ($amount > $left) {
            return CallbackResult::ignored(sprintf(__('At most %s is left to refund'), \mindstellar\currency\Money::format($left, $order->getCurrency())));
        }

        $gateway = null;
        if ($viaGateway) {
            $gateway = self::partRefundableGateway($order);
            if ($gateway === null) {
                return CallbackResult::ignored(__('This payment method cannot refund part of an order from here'));
            }
        }

        $restocked = array();
        if ($restock && $lines !== null) {
            $held = OrderStock::held($order);
            foreach ($lines as $lineId => $qty) {
                $n = min($qty, $held[$lineId] ?? 0);
                if ($n > 0) {
                    $restocked[$lineId] = $n;
                }
            }
        }

        try {
            $draft = Invoices::partDraft($order, $amount, $lines, $reason, $restocked);
        } catch (Throwable $e) {
            error_log(sprintf('Billing: credit note for order #%d threw %s (code %s)', $orderId, get_class($e), (string) $e->getCode()));

            return CallbackResult::ignored(__('The credit note could not be made. Nothing was refunded.'));
        }
        if ($draft === null) {
            return CallbackResult::ignored(__('More units were chosen than are left to refund'));
        }

        $result = CallbackResult::refunded($orderId);
        if ($gateway !== null) {
            $n = OrderStore::partRefundKey($orderId, $amount, count(DocumentStore::forOrder($orderId)) - 1);
            if ($n === null) {
                return CallbackResult::ignored(__('The refund could not be started. Try again.'));
            }
            try {
                $result = $gateway->refundPart($order, $amount, 'order:' . $orderId . ':refund:' . $n);
            } catch (Throwable $e) {
                // The key stays open: a retry of the same amount sends the same key, so it is not paid twice.
                error_log(sprintf('Billing: part refund of order #%d through %s threw %s (code %s)', $orderId, $order->getGateway(), get_class($e), (string) $e->getCode()));
                self::log('refund failed', $order, get_class($e));

                return CallbackResult::ignored(__('The payment provider returned an error'));
            }
            if ($result->getOutcome() === CallbackResult::OUTCOME_IGNORED) {
                OrderStore::partRefundDone($orderId);
                self::log('refund refused', $order, $result->getMessage());

                return $result;
            }
            if (!in_array($result->getOutcome(), array(CallbackResult::OUTCOME_REFUNDED, CallbackResult::OUTCOME_PENDING), true) || $result->getOrderId() !== $orderId) {
                self::log('refund mismatch', $order, $result->getExternalRef());

                return CallbackResult::ignored(__('The payment provider gave an answer that does not match this order'));
            }
            $draft = Invoices::withProviderRef($draft, $result->getExternalRef());
        }

        return self::storePart($order, $draft, $lines, $restock, $result, $gateway !== null);
    }

    /**
     * Store a part refund's credit note with its stock move, and close the order when the notes
     * reach the paid amount. Composed once more when another note was stored since the draft.
     *
     * @param array<string,mixed> $draft From Invoices::partDraft()
     * @param array<int,int>|null $lines
     */
    private static function storePart(Order $order, array $draft, ?array $lines, bool $restock, CallbackResult $result, bool $sent): CallbackResult
    {
        $orderId = $order->getId();
        $closed  = false;
        for ($try = 0; $try < 2; $try++) {
            try {
                $note = DocumentStore::atomic(static function () use ($draft, $lines, $restock, $order, &$closed) {
                    // Order row first, then stock, then the note's counter, as refund() does.
                    $closed = false;
                    if (DocumentStore::lockOrder($order->getId()) === null) {
                        return null;
                    }
                    if (DocumentStore::refundedTotal($order->getId()) !== $draft['refunded']) {
                        return false;
                    }
                    if ($lines !== null && $lines !== array()) {
                        OrderStock::releaseLines($order->getId(), $lines, $restock);
                    }
                    OrderStore::partRefundDone($order->getId());
                    if ((int) $draft['refunded'] + (int) $draft['amount'] >= $order->getAmount() && OrderStore::refund($order->getId())) {
                        $closed = true;
                        OrderStock::release($order->getId());
                    }
                    $note = Invoices::storeNote($draft);
                    if (!$note instanceof Document) {
                        throw new RuntimeException('credit note not stored');
                    }

                    return $note;
                });
            } catch (Throwable $e) {
                error_log(sprintf('Billing: part refund of order #%d could not be recorded: %s (code %s)', $orderId, get_class($e), (string) $e->getCode()));
                self::log('refund unrecorded', $order, $result->getExternalRef());

                return CallbackResult::ignored($sent
                    ? __('Refunded at the provider but not recorded here. Record it with Refund part.')
                    : __('The refund could not be recorded. Try again.'));
            }
            if ($note instanceof Document) {
                break;
            }
            $draft = $note === false ? self::redraft($order, $draft, $lines, $restock) : null;
            if ($draft === null) {
                self::log('refund already recorded', $order, $result->getExternalRef());

                return CallbackResult::ignored($sent
                    ? __('Refunded at the provider, but the order had already been refunded here. Check the credit notes.')
                    : __('This order was refunded meanwhile'));
            }
        }
        if (!isset($note) || !$note instanceof Document) {
            return CallbackResult::ignored(__('The refund could not be recorded. Try again.'));
        }

        self::log('part refunded', $order, $result->getExternalRef());
        if ($closed) {
            self::log('refunded', $order, $result->getExternalRef());
            osc_run_hook('billing_order_refunded', $orderId, $order->getUserId(), $order->getCredits());
        }

        return $result;
    }

    /**
     * Compose a part refund's note again, for the same amount, lines and provider ref.
     *
     * @param array<string,mixed> $draft
     * @param array<int,int>|null $lines
     *
     * @return array<string,mixed>|null
     */
    private static function redraft(Order $order, array $draft, ?array $lines, bool $restock): ?array
    {
        $refund = (array) ($draft['data']['refund'] ?? array());
        try {
            $again = Invoices::partDraft($order, (int) $draft['amount'], $lines, (string) ($refund['reason'] ?? ''), $restock ? (array) ($refund['restocked'] ?? array()) : array());
        } catch (Throwable $e) {
            return null;
        }

        return $again === null ? null : Invoices::withProviderRef($again, $refund['provider_ref'] ?? null);
    }

    /**
     * Spend credits on a registered feature.
     *
     * The debit and the feature's own effect are one transaction: if apply() reports
     * failure, an exception is the only thing that rolls the debit back (Db::transaction
     * commits on a normal return, whatever value it carries), so failure is signalled by
     * throwing rather than returning false from inside the closure. A zero-price feature
     * skips the debit but still applies -- price and enforcement are independent.
     *
     * apply() runs while the debit above still holds the wallet row's lock. Only the
     * database writes stay inside that lock: any hook a built-in feature would fire
     * from apply() (item_premium_on, item_bumped) goes through deferHook() instead and
     * is announced below, once the transaction has actually committed -- the same
     * fire-after-commit shape markPaid() already uses for billing_order_paid.
     *
     * @param int    $userId
     * @param string $featureId Id registered with FeatureRegistry
     * @param array  $ctx       Passed to the feature's apply(); 'ref_type'/'ref_id' also
     *                          become the ledger row's reference when present, and
     *                          'days' is overwritten with the registry's own resolved
     *                          duration (see Feature::duration()) before apply() runs
     *
     * @return bool false when billing is off, on an unknown feature, insufficient credit,
     *              or a failed apply -- all normal outcomes, not exceptions
     */
    public static function spend(int $userId, string $featureId, array $ctx = array()): bool
    {
        // Nothing is purchasable while billing is switched off, and a zero-priced feature
        // would otherwise apply for free to anyone who reached this method. The public
        // route already refuses, but this is the seam every caller goes through -- a
        // plugin calling spend() directly has to hit the same wall.
        if (!osc_billing_enabled()) {
            return false;
        }

        $feature = FeatureRegistry::getInstance()->get($featureId);
        if ($feature === null) {
            return false;
        }

        $price = $feature->price($userId);
        // Resolved once here, through billing_feature_duration, so every apply() uses
        // the duration the registry actually settled on rather than re-reading its own
        // preference -- see Feature::duration(). A quantity feature's duration is 0 and
        // simply goes unused by an apply() that has no concept of days.
        $ctx['days'] = $feature->duration($userId);
        $refType     = isset($ctx['ref_type']) ? (string) $ctx['ref_type'] : null;
        $refId       = isset($ctx['ref_id']) ? (int) $ctx['ref_id'] : null;

        self::$hookBatches[] = array();

        try {
            $applied = Db::transaction(static function () use ($userId, $price, $feature, $ctx, $refType, $refId): bool {
                if ($price > 0 && !Wallet::debit($userId, $price, Wallet::REASON_SPEND, null, $refType, $refId)) {
                    return false; // insufficient credit -- nothing was written
                }

                if (!$feature->apply($userId, $ctx)) {
                    throw new DomainException('billing_feature_apply_failed');
                }

                return true;
            });
        } catch (DomainException $e) {
            array_pop(self::$hookBatches); // apply() queued these for writes that just rolled back

            return false;
        } catch (Throwable $e) {
            // A database failure rolls the transaction back the same way, so the queue goes
            // with it. Left behind, it would swallow the next hook deferred in this request.
            array_pop(self::$hookBatches);

            throw $e;
        }

        $deferred = array_pop(self::$hookBatches);

        if ($applied) {
            foreach ($deferred as $queued) {
                osc_run_hook($queued[0], ...$queued[1]);
            }
            osc_run_hook('billing_feature_applied', $featureId, $userId, $price);
        }

        return $applied;
    }

    /**
     * Register the held-stock release, card hold and invoice jobs; called from `register_jobs`.
     */
    public static function registerJobs(): void
    {
        osc_job_register_handler(self::JOB_RELEASE, static function (\mindstellar\job\Job $job): void {
            if (self::releaseHeld() === self::RELEASE_BATCH) {
                $job->repeat(array());
            }
        });
        osc_job_describe(self::JOB_RELEASE, __('Cancel unpaid shop orders'));
        osc_job_register_handler(self::JOB_CARD_HOLD, static function (\mindstellar\job\Job $job): void {
            self::expireCardHold((int) $job->get('order', 0));
        });
        osc_job_describe(self::JOB_CARD_HOLD, __('Cancel unpaid card orders'));
        Invoices::registerJobs();
    }

    /**
     * Start or extend a card order's stock hold: the end moves to billing_card_hold_minutes from
     * now, and the waiting job is pushed back to it. Call it in the placement's own transaction.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function queueCardHold(int $orderId): void
    {
        $delay = osc_billing_card_hold_minutes() * 60;
        $until = time() + $delay;
        OrderStore::withMeta($orderId, static fn (array $meta): array => array(self::CARD_HOLD_UNTIL => $until) + $meta);
        if (osc_job_enqueue(self::JOB_CARD_HOLD, array('order' => $orderId), array('delay' => $delay, 'unique_key' => (string) $orderId)) === 0) {
            // The daily release job still cancels it after billing_hold_days.
            error_log('Billing: the card hold of order #' . $orderId . ' was not queued.');
        }
    }

    /**
     * Cancel a card goods order still unpaid when its hold ends, and give its stock back. A hold
     * that Pay again moved later is left for the later run. The buyer left the payment page, so
     * no cancel mail goes. A payment that comes later finds the order closed: core takes no
     * stock and logs it as "paid after close", and the gateway plugin refunds it.
     *
     * @return bool whether the order was cancelled
     * @throws \mindstellar\database\DbException
     */
    public static function expireCardHold(int $orderId): bool
    {
        $order = OrderStore::find($orderId);
        if ($order === null || !$order->isGoods() || !$order->isPending() || in_array($order->getGateway(), self::HOLDS_STOCK, true)) {
            return false;
        }
        if ((int) $order->meta(self::CARD_HOLD_UNTIL) > time()) {
            return false;
        }
        OrderStore::markMailSent($orderId, Receipts::MAIL_CANCELLED);
        if (!OrderStore::cancel($orderId)) {
            return false;
        }
        self::log('hold expired', $order, $order->getExternalRef());

        return true;
    }

    /**
     * Queue the release job once a day while billing is on; called from `cron_daily`.
     */
    public static function queueRelease(): void
    {
        if (osc_billing_enabled()) {
            osc_job_ensure(self::JOB_RELEASE);
        }
    }

    /**
     * Cancel one batch of pending goods orders placed more than billing_hold_days ago: those
     * of the HOLDS_STOCK gateways give their stock back, and card orders the buyer left are
     * closed too. Each fires `billing_order_cancelled`.
     *
     * @return int how many were cancelled; a full batch means there may be more
     * @throws \mindstellar\database\DbException
     */
    public static function releaseHeld(): int
    {
        $before = date('Y-m-d H:i:s', time() - osc_billing_hold_days() * 86400);
        $ids    = OrderStore::heldBefore(self::HOLDS_STOCK, $before, self::RELEASE_BATCH);
        if (count($ids) < self::RELEASE_BATCH) {
            $ids = array_merge($ids, OrderStore::heldBefore(self::HOLDS_STOCK, $before, self::RELEASE_BATCH - count($ids), true));
        }
        $cancelled = 0;
        foreach ($ids as $id) {
            $cancelled += OrderStore::cancel($id) ? 1 : 0;
        }

        return $cancelled;
    }

    /**
     * Verify a paid callback against the stored order, then fulfil it.
     *
     * @param string         $gatewayId
     * @param CallbackResult $result
     *
     * @return CallbackResult the original result, or an ignored() one on mismatch
     */
    private static function settlePaid(string $gatewayId, CallbackResult $result): CallbackResult
    {
        $order = self::resolve($gatewayId, $result);
        if ($order === null) {
            return CallbackResult::ignored('no matching order');
        }

        // A callback is attacker-reachable. Where the provider echoes its own figures,
        // they have to match what we asked for -- otherwise a request claiming a paid
        // order for one cent settles an order for a hundred.
        if ($result->getAmount() !== null && $result->getAmount() !== $order->getAmount()) {
            self::log('amount mismatch', $order, $result->getExternalRef());

            return CallbackResult::ignored('amount mismatch');
        }

        if (
            $result->getCurrency() !== null
            && strtoupper($result->getCurrency()) !== strtoupper($order->getCurrency())
        ) {
            self::log('currency mismatch', $order, $result->getExternalRef());

            return CallbackResult::ignored('currency mismatch');
        }

        if (!self::markPaid($order, $result->getExternalRef())) {
            // The provider took the money for an order already closed as failed or cancelled, and a
            // callback may not reopen it. The entry shows in Tools → Activity log, one per provider
            // retry; the admin refunds it, or marks a failed one paid (CAdminBilling order_paid).
            $now = OrderStore::find($order->getId());
            if ($now !== null && in_array($now->getStatus(), array(Order::STATUS_FAILED, Order::STATUS_CANCELLED), true)) {
                self::log('paid after close', $now, $result->getExternalRef());
            } elseif ($now !== null && $now->isPaid() && $result->getExternalRef() !== null && $result->getExternalRef() !== $now->getExternalRef()) {
                // A second payment page of the same order (Pay again) was paid too.
                self::log('paid twice', $now, $result->getExternalRef());
            }
        }

        return $result;
    }

    /**
     * Find the order a callback refers to, by our id or by the provider's reference,
     * and confirm it belongs to the gateway the callback arrived on.
     *
     * The gateway check is what stops one installed gateway from settling another's
     * orders, whether by a bug or by a forged payload aimed at the weaker of the two.
     *
     * @param string         $gatewayId
     * @param CallbackResult $result
     *
     * @return Order|null null when nothing matches, or the match is another gateway's
     */
    private static function resolve(string $gatewayId, CallbackResult $result): ?Order
    {
        $order = null;

        if ($result->getOrderId() !== null) {
            $order = OrderStore::find($result->getOrderId());
        } elseif ($result->getExternalRef() !== null) {
            $order = OrderStore::findByGatewayRef($gatewayId, $result->getExternalRef());
        }

        if ($order === null || $order->getGateway() !== $gatewayId) {
            return null;
        }

        return $order;
    }

    /**
     * Record a billing action in the admin log.
     *
     * @param string      $action
     * @param Order       $order
     * @param string|null $externalRef
     *
     * @return void
     */
    private static function log(string $action, Order $order, ?string $externalRef): void
    {
        Log::getInstance()->insertLog(
            'billing',
            $action,
            $order->getId(),
            $order->getGateway() . ' ' . (string) $externalRef,
            'user',
            $order->getUserId()
        );
    }
}

/* file end: ./oc-includes/classes/billing/Billing.php */
