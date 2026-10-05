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

use RuntimeException;
use Throwable;
use User;

/**
 * Payment receipts: the e-mail a buyer gets when an order is paid, and the printable page.
 *
 * A receipt is sent once per order. Billing::markPaid() calls sent() after the paid
 * transaction commits; if the mail cannot go out, a job on core's queue retries it. The
 * order's `_receipt_sent` meta key records that it went, so a replayed webhook, the queue
 * and an admin "mark paid" never send a second one.
 *
 * @package mindstellar\billing
 */
final class Receipts
{
    /** Job type that retries a receipt that could not be sent at once. */
    public const JOB = 'billing.receipt';

    /** Preference: e-mail a receipt when an order is paid. On unless switched off. */
    public const PREF_EMAIL = 'billing_receipt_email';

    /** Preference: free-text business details printed on every receipt. */
    public const PREF_BUSINESS = 'billing_receipt_business';

    /**
     * Sends the mail. Null uses osc_sendMail(); tests replace it.
     *
     * @var callable(array):bool|null
     */
    public static $mailer = null;

    /**
     * Whether receipts are e-mailed.
     *
     * @return bool
     */
    public static function emailEnabled(): bool
    {
        $value = osc_get_preference(self::PREF_EMAIL, Billing::PREF_GROUP);

        return $value === null || $value === '' ? true : (bool) $value;
    }

    /**
     * The business details an admin wrote for receipts, as plain text.
     *
     * @return string
     */
    public static function businessDetails(): string
    {
        return trim((string) osc_get_preference(self::PREF_BUSINESS, Billing::PREF_GROUP));
    }

    /**
     * The business details as HTML: escaped, line breaks kept.
     *
     * @return string
     */
    public static function businessHtml(): string
    {
        return nl2br(osc_esc_html(self::businessDetails()), false);
    }

    /**
     * Send the receipt for an order that was just paid, or queue it when that fails.
     * Never throws: a receipt must not break settlement.
     *
     * @param Order $order
     *
     * @return void
     */
    public static function afterPaid(Order $order): void
    {
        if (!self::emailEnabled()) {
            return;
        }

        try {
            if (self::send($order->getId())) {
                return;
            }
        } catch (Throwable $e) {
            self::logFailure($order->getId(), $e);
        }

        try {
            osc_job_enqueue(
                self::JOB,
                array('order_id' => $order->getId()),
                array('unique_key' => 'order:' . $order->getId(), 'delay' => 60)
            );
        } catch (Throwable $e) {
            self::logFailure($order->getId(), $e);
        }
    }

    /**
     * Send the receipt for an order, unless it went already.
     *
     * @param int $orderId
     *
     * @return bool true when the receipt is sent, or there is nothing to send (no such
     *              order, not paid, no buyer address); false when sending failed
     */
    public static function send(int $orderId): bool
    {
        $order = OrderStore::find($orderId);
        if ($order === null || $order->getStatus() !== Order::STATUS_PAID
            || $order->meta(OrderStore::RECEIPT_SENT) !== null
        ) {
            return true;
        }

        $vars = self::vars($order);
        if ($vars['email'] === '') {
            return true;
        }

        $params = array(
            'from'      => _osc_from_email_aux(),
            'from_name' => osc_page_title(),
            'to'        => $vars['email'],
            'to_name'   => $vars['name'],
            'subject'   => sprintf(__('Your receipt from %1$s, order #%2$d'), $vars['site'], $order->getId()),
            'body'      => self::emailBody($vars),
        );

        $sent = self::$mailer !== null ? (bool) (self::$mailer)($params) : (bool) osc_sendMail($params);
        if ($sent) {
            OrderStore::markReceiptSent($orderId);
        }

        return $sent;
    }

    /**
     * Register the retry job's handler. A false send throws, so the queue tries again.
     *
     * @return void
     */
    public static function registerJobs(): void
    {
        osc_job_register_handler(self::JOB, static function ($job) {
            if (!self::send((int) $job->get('order_id'))) {
                throw new RuntimeException('receipt not sent');
            }
        });
        osc_job_describe(self::JOB, __('Payment receipt e-mail'));
    }

    /**
     * Whether a receipt exists for the order: it was paid, and may since be refunded.
     *
     * @param Order $order
     *
     * @return bool
     */
    public static function available(Order $order): bool
    {
        return $order->isPaid() || $order->getStatus() === Order::STATUS_REFUNDED;
    }

    /**
     * Whether a visitor may see an order's receipt: an admin, or the buyer themself.
     *
     * @param Order $order
     * @param int   $userId  The signed-in user, 0 for none
     * @param bool  $isAdmin
     *
     * @return bool
     */
    public static function canView(Order $order, int $userId, bool $isAdmin): bool
    {
        if (!self::available($order)) {
            return false;
        }

        return $isAdmin || ($userId > 0 && $userId === $order->getUserId());
    }

    /**
     * The buyer's receipt page.
     *
     * @param int $orderId
     *
     * @return string
     */
    public static function url(int $orderId): string
    {
        return osc_core_url('billing_receipt', array('id' => $orderId));
    }

    /**
     * Print the receipt page for an order. The caller has checked who may see it.
     *
     * @param Order  $order
     * @param string $backUrl   Where the page's back link goes
     * @param string $backLabel Its text
     *
     * @return void
     */
    public static function render(Order $order, string $backUrl, string $backLabel): void
    {
        $receipt = self::vars($order);
        require ABS_PATH . 'oc-includes/osclass/gui/billing/receipt.php';
    }

    /**
     * Money the way the account pages show it: two decimals in the site's locale and the
     * currency code.
     *
     * @param int    $micros
     * @param string $currency
     *
     * @return string
     */
    public static function money(int $micros, string $currency): string
    {
        return number_format($micros / 1000000, 2, osc_locale_dec_point(), osc_locale_thousands_sep())
               . ' ' . strtoupper($currency);
    }

    /**
     * Everything a receipt shows, as plain text. Escaping is the caller's.
     *
     * @param Order $order
     *
     * @return array<string,mixed>
     */
    public static function vars(Order $order): array
    {
        $user = User::newInstance()->findByPrimaryKey($order->getUserId());
        $user = is_array($user) ? $user : array();
        $paid = $order->getPaidDate() ?? $order->getDate();

        return array(
            'site'     => osc_page_title(),
            'number'   => $order->getId(),
            'date'     => osc_format_date($paid),
            'credits'  => sprintf(__('%s credits'), number_format($order->getCredits())),
            'amount'   => self::money($order->getAmount(), $order->getCurrency()),
            'method'   => osc_billing_gateway_name($order->getGateway()),
            'ref'      => (string) $order->getExternalRef(),
            'email'    => (string) ($user['s_email'] ?? ''),
            'name'     => (string) ($user['s_name'] ?? ''),
            'url'      => self::url($order->getId()),
            'business' => self::businessDetails(),
            'refunded' => $order->getStatus() === Order::STATUS_REFUNDED,
        );
    }

    /**
     * The receipt e-mail's body. Every value is escaped here.
     *
     * @param array<string,mixed> $vars From vars()
     *
     * @return string
     */
    public static function emailBody(array $vars): string
    {
        $rows = array(
            __('Receipt number')  => '#' . $vars['number'],
            __('Date paid')       => $vars['date'],
            __('What you bought') => $vars['credits'],
            __('Amount paid')     => $vars['amount'],
            __('Payment method')  => $vars['method'],
        );
        if ($vars['ref'] !== '') {
            $rows[__('Payment reference')] = $vars['ref'];
        }
        $rows[__('Paid by')] = $vars['email'];

        $html = '<p>' . osc_esc_html(sprintf(__('Thank you for your payment to %s.'), $vars['site'])) . '</p>'
                . '<table cellpadding="6" cellspacing="0" border="0">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td style="color:#666">' . osc_esc_html($label) . '</td>'
                     . '<td><strong>' . osc_esc_html((string) $value) . '</strong></td></tr>';
        }
        $html .= '</table>'
                 . '<p style="margin-top:24px"><a href="' . osc_esc_html($vars['url']) . '">'
                 . osc_esc_html(__('View or print your receipt')) . '</a></p>';
        if ($vars['business'] !== '') {
            $html .= '<p style="color:#666">' . nl2br(osc_esc_html($vars['business']), false) . '</p>';
        }

        return $html;
    }

    /**
     * Log a receipt that could not be sent: the exception's class and code, never its text.
     *
     * @param int       $orderId
     * @param Throwable $e
     *
     * @return void
     */
    private static function logFailure(int $orderId, Throwable $e): void
    {
        error_log(sprintf(
            'Billing: receipt for order #%d failed with %s (code %s)',
            $orderId,
            get_class($e),
            (string) $e->getCode()
        ));
    }
}

/* file end: ./oc-includes/osclass/classes/billing/Receipts.php */
