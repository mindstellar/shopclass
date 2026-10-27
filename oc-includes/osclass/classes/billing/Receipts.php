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

use mindstellar\billing\event\ReceiptComposing;
use mindstellar\boot\CoreServices;
use mindstellar\currency\Money;
use mindstellar\mail\CoreEmails;
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

    /** Job type that retries a placed, shipped, delivered, cancelled, refunded or admin order mail that could not be sent at once. */
    public const MAIL_JOB = 'billing.order_mail';

    public const MAIL_PLACED  = 'placed';
    public const MAIL_SHIPPED = 'shipped';
    public const MAIL_ADMIN   = 'admin';
    public const MAIL_DELIVERED = 'delivered';
    public const MAIL_CANCELLED = 'cancelled';
    public const MAIL_REFUNDED  = 'refunded';

    /** Gateways that leave an order unpaid until the shop hears the money came in. */
    private const HELD_GATEWAYS = array('offline', 'cod');

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
        if (self::emailEnabled()) {
            self::sendOrQueueReceipt($order);
        }
        if ($order->isGoods()) {
            self::notify(self::MAIL_ADMIN, $order->getId());
        }
    }

    /**
     * Send the receipt now, or queue it when that fails.
     *
     * @param Order $order
     *
     * @return void
     */
    private static function sendOrQueueReceipt(Order $order): void
    {
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
     * `billing_order_placed`: a goods order that waits for its money (bank transfer, cash on
     * delivery) tells the buyer it was received and the admin that it came in.
     *
     * @param int $orderId
     * @param int $userId
     *
     * @return void
     */
    public static function afterPlaced($orderId, $userId = 0): void
    {
        $order = OrderStore::find((int) $orderId);
        if ($order === null || !$order->isGoods() || !in_array($order->getGateway(), self::HELD_GATEWAYS, true)) {
            return;
        }
        self::notify(self::MAIL_PLACED, $order->getId());
        self::notify(self::MAIL_ADMIN, $order->getId());
    }

    /**
     * `billing_order_shipped`: tell the buyer the parcel is on its way.
     *
     * @param int $orderId
     * @param int $userId
     *
     * @return void
     */
    public static function afterShipped($orderId, $userId = 0): void
    {
        self::notify(self::MAIL_SHIPPED, (int) $orderId);
    }

    /**
     * `billing_order_delivered`: tell the buyer the order arrived.
     *
     * @param int $orderId
     * @param int $userId
     *
     * @return void
     */
    public static function afterDelivered($orderId, $userId = 0): void
    {
        self::notify(self::MAIL_DELIVERED, (int) $orderId);
    }

    /**
     * `billing_order_cancelled`: tell the buyer the order was cancelled, whatever it was for.
     *
     * @param int $orderId
     * @param int $userId
     *
     * @return void
     */
    public static function afterCancelled($orderId, $userId = 0): void
    {
        self::notify(self::MAIL_CANCELLED, (int) $orderId);
    }

    /**
     * `billing_order_refunded`: tell the buyer the money is on its way back.
     *
     * @param int $orderId
     * @param int $userId
     * @param int $credits
     *
     * @return void
     */
    public static function afterRefunded($orderId, $userId = 0, $credits = 0): void
    {
        self::notify(self::MAIL_REFUNDED, (int) $orderId);
    }

    /**
     * Send one order mail, or queue it when that fails. Never throws.
     *
     * @param string $kind One of the MAIL_* constants
     * @param int    $orderId
     *
     * @return void
     */
    public static function notify(string $kind, int $orderId): void
    {
        try {
            if (self::sendNotice($kind, $orderId)) {
                return;
            }
        } catch (Throwable $e) {
            self::logFailure($orderId, $e);
        }

        try {
            osc_job_enqueue(
                self::MAIL_JOB,
                array('order_id' => $orderId, 'kind' => $kind),
                array('unique_key' => 'order:' . $orderId . ':' . $kind, 'delay' => 60)
            );
        } catch (Throwable $e) {
            self::logFailure($orderId, $e);
        }
    }

    /**
     * Send one kind of order mail, once per order and kind. A failed send is flagged on CoreEmails::orderMailFailed().
     *
     * @param string $kind
     * @param int    $orderId
     *
     * @return bool true when the mail went, or there is nothing to send; false to try again
     */
    public static function sendNotice(string $kind, int $orderId): bool
    {
        $order = OrderStore::find($orderId);
        $mark  = '_mail_' . $kind;
        if (!in_array($kind, array(self::MAIL_PLACED, self::MAIL_SHIPPED, self::MAIL_ADMIN, self::MAIL_DELIVERED, self::MAIL_CANCELLED, self::MAIL_REFUNDED), true) || $order === null || (!$order->isGoods() && $kind !== self::MAIL_CANCELLED) || $order->meta($mark) !== null) {
            return true;
        }
        if (($kind === self::MAIL_SHIPPED && $order->getStatus() !== Order::STATUS_SHIPPED && $order->getStatus() !== Order::STATUS_DELIVERED)
            || ($kind === self::MAIL_PLACED && !$order->isPending() && !$order->isPaid())
            || ($kind === self::MAIL_DELIVERED && $order->getStatus() !== Order::STATUS_DELIVERED)
            || ($kind === self::MAIL_CANCELLED && $order->getStatus() !== Order::STATUS_CANCELLED)
            || ($kind === self::MAIL_REFUNDED && $order->getStatus() !== Order::STATUS_REFUNDED)
        ) {
            return true;
        }

        CoreEmails::resetOrderMailFailure();
        $send = array(
            self::MAIL_PLACED    => 'orderPlaced',
            self::MAIL_SHIPPED   => 'orderShipped',
            self::MAIL_DELIVERED => 'orderDelivered',
            self::MAIL_CANCELLED => 'orderCancelled',
            self::MAIL_REFUNDED  => 'orderRefunded',
            self::MAIL_ADMIN     => 'adminNewOrder',
        );
        CoreEmails::{$send[$kind]}($order);
        if (CoreEmails::orderMailFailed()) {
            return false;
        }
        OrderStore::markMailSent($orderId, $kind);

        return true;
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
        if ($order === null || !$order->isPaid() || $order->meta(OrderStore::RECEIPT_SENT) !== null) {
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
            'subject'   => $order->isGoods()
                ? sprintf(__('Your order %1$s from %2$s'), self::number($order), $vars['site'])
                : sprintf(__('Your receipt from %1$s, order #%2$d'), $vars['site'], $order->getId()),
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
        osc_job_register_handler(self::MAIL_JOB, static function ($job) {
            if (!self::sendNotice((string) $job->get('kind'), (int) $job->get('order_id'))) {
                throw new RuntimeException('order e-mail not sent');
            }
        });
        osc_job_describe(self::MAIL_JOB, __('Order e-mail'));
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
     * Everything a receipt shows, as plain text. Escaping is the caller's.
     *
     * @param Order $order
     *
     * @return array<string,mixed>
     */
    public static function vars(Order $order): array
    {
        $user = User::getInstance()->findByPrimaryKey($order->getUserId());
        $user = is_array($user) ? $user : array();
        $paid = $order->getPaidDate() ?? $order->getDate();

        return array(
            'site'     => osc_page_title(),
            'number'   => $order->getId(),
            'label'    => self::number($order),
            'date'     => osc_format_date($paid),
            'credits'  => $order->isGoods() ? $order->getLabel() : sprintf(__('%s credits'), number_format($order->getCredits())),
            'for'      => $order->isListing() ? $order->getLabel() : '',
            'amount'   => Money::format($order->getAmount(), $order->getCurrency()),
            'method'   => osc_billing_gateway_name($order->getGateway()),
            'ref'      => (string) $order->getExternalRef(),
            'email'    => (string) ($user['s_email'] ?? ''),
            'name'     => (string) ($user['s_name'] ?? ''),
            'url'      => self::url($order->getId()),
            'wallet'   => osc_billing_credits_enabled() ? osc_billing_wallet_url() : '',
            'business' => self::businessDetails(),
            'refunded' => $order->getStatus() === Order::STATUS_REFUNDED,
            'details'  => $order->isGoods() ? self::details($order) : null,
        );
    }

    /**
     * The order's number as the shop shows it.
     *
     * @param Order $order
     *
     * @return string
     */
    public static function number(Order $order): string
    {
        return osc_billing_order_number($order->getId());
    }

    /**
     * What a goods order holds, as plain text: every line, the address, how it goes and the
     * tracking once shipped, then the title, extra rows under the business details, summary rows
     * under the lines and footer that `ReceiptComposing` listeners (or old `billing_receipt_details`
     * callbacks) set. They change those four only. Escaping is the caller's.
     *
     * @param Order $order
     *
     * @return array{lines:array<int,array<string,mixed>>,address:string[],shipping:string,carrier:string,tracking:string,tracking_url:string,total:string,note:string,title:string,extra:array<int,array{label:string,value:string}>,summary:array<int,array{label:string,value:string}>,footer:string}
     */
    public static function details(Order $order): array
    {
        $currency = $order->getCurrency();
        $lines    = array();
        foreach (OrderLineStore::ofOrder($order->getId()) as $line) {
            $options = array();
            foreach ((array) ($line['options'] ?? array()) as $name => $value) {
                $options[] = $name . ': ' . $value;
            }
            $lines[] = array(
                'kind'    => (string) $line['kind'],
                'title'   => (string) $line['title'],
                'options' => implode(', ', $options),
                'sku'     => (string) ($line['sku'] ?? ''),
                'qty'     => (string) $line['qty'],
                'unit'     => Money::format($line['unit'], $currency),
                'amount'   => Money::format($line['amount'], $currency),
                'included' => $line['kind'] === OrderLineStore::KIND_TAX && ($line['meta']['included'] ?? false) === true,
            );
        }
        $fulfilment = $order->fulfilment() ?? array();
        $address    = array();
        foreach ((array) ($fulfilment['address'] ?? array()) as $part) {
            if (is_string($part) && trim($part) !== '') {
                $address[] = trim($part);
            }
        }
        $note    = $order->meta('_note');
        $details = array(
            'lines'        => $lines,
            'address'      => $address,
            'shipping'     => (string) ($fulfilment['label'] ?? ''),
            'carrier'      => (string) ($fulfilment['carrier'] ?? ''),
            'tracking'     => (string) ($fulfilment['tracking'] ?? ''),
            'tracking_url' => self::httpsUrl((string) ($fulfilment['tracking_url'] ?? '')),
            'total'        => Money::format($order->getAmount(), $currency),
            'note'         => is_string($note) ? $note : '',
            'title'        => __('Receipt'),
            'extra'        => array(),
            'summary'      => array(),
            'footer'       => '',
        );
        $event = new ReceiptComposing($order, $details);
        CoreServices::dispatcher()->dispatch($event);

        return array(
            'title'   => $event->title(),
            'extra'   => $event->extraRows(),
            'summary' => $event->summaryRows(),
            'footer'  => $event->footer(),
        ) + $details;
    }

    /**
     * A string trimmed to $max characters with control characters made spaces (line breaks
     * kept when $lines), or '' for anything that is not a string.
     *
     * @param mixed $value
     */
    public static function plain($value, int $max, bool $lines = false): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace($lines ? '/[\x00-\x09\x0B-\x1F\x7F]+/' : '/[\x00-\x1F\x7F]+/', ' ', str_replace("\r\n", "\n", $value)) ?? '';

        return mb_substr(trim($value), 0, $max, 'UTF-8');
    }

    /**
     * A web address a mail or page may link: https only, no spaces, no markup.
     *
     * @param string $url
     *
     * @return string the address, or '' when it is not one
     */
    public static function httpsUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('~^https://[^\s<>"\']+$~i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
    }

    /**
     * The lines of a goods order as an HTML table. Every value is escaped here.
     *
     * @param array<string,mixed> $details From details()
     *
     * @return string
     */
    public static function linesHtml(array $details): string
    {
        $html = '<table cellpadding="6" cellspacing="0" border="0" style="width:100%">'
                . '<tr><th align="left">' . osc_esc_html(__('Item')) . '</th>'
                . '<th align="right">' . osc_esc_html(__('Qty')) . '</th>'
                . '<th align="right">' . osc_esc_html(__('Amount')) . '</th></tr>';
        foreach ($details['lines'] as $line) {
            $amount = !empty($line['included']) ? sprintf(__('%s (included)'), $line['unit']) : $line['amount'];
            $name   = $line['kind'] === OrderLineStore::KIND_ITEM
                ? '<strong>' . osc_esc_html($line['title']) . '</strong>'
                  . ($line['options'] !== '' ? '<br><span style="color:#666">' . osc_esc_html($line['options']) . '</span>' : '')
                  . ($line['sku'] !== '' ? '<br><span style="color:#666">' . osc_esc_html(sprintf(__('SKU %s'), $line['sku'])) . '</span>' : '')
                : osc_esc_html($line['title']);
            $html .= '<tr><td>' . $name . '</td>'
                     . '<td align="right">' . ($line['kind'] === OrderLineStore::KIND_ITEM ? osc_esc_html($line['qty']) : '') . '</td>'
                     . '<td align="right">' . osc_esc_html($amount) . '</td></tr>';
        }

        return $html . '<tr><td colspan="2"><strong>' . osc_esc_html(__('Total')) . '</strong></td>'
               . '<td align="right"><strong>' . osc_esc_html($details['total']) . '</strong></td></tr></table>';
    }

    /**
     * The delivery address as HTML, one line per part. Every value is escaped here.
     *
     * @param array<string,mixed> $details From details()
     *
     * @return string
     */
    public static function addressHtml(array $details): string
    {
        if ($details['address'] === array()) {
            return '';
        }
        $html = '<p><strong>' . osc_esc_html(__('Delivery address')) . '</strong><br>'
                . implode('<br>', array_map('osc_esc_html', $details['address'])) . '</p>';
        if ($details['shipping'] !== '') {
            $html .= '<p>' . osc_esc_html(sprintf(__('Delivery: %s'), $details['shipping'])) . '</p>';
        }

        return $html;
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
        if (!empty($vars['details'])) {
            return self::goodsEmailBody($vars);
        }
        $rows = array(
            __('Receipt number')  => $vars['label'],
            __('Date paid')       => $vars['date'],
            __('What you bought') => $vars['credits'],
            __('Amount paid')     => $vars['amount'],
            __('Payment method')  => $vars['method'],
        );
        if (($vars['for'] ?? '') !== '') {
            $rows[__('For')] = $vars['for'];
        }
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
        if (($vars['wallet'] ?? '') !== '') {
            $html .= '<p><a href="' . osc_esc_html($vars['wallet']) . '">'
                     . osc_esc_html(__('Use your credits')) . '</a></p>';
        }
        if ($vars['business'] !== '') {
            $html .= '<p style="color:#666">' . nl2br(osc_esc_html($vars['business']), false) . '</p>';
        }

        return $html;
    }

    /**
     * The order confirmation a buyer gets when a goods order is paid. Every value is escaped here.
     *
     * @param array<string,mixed> $vars From vars()
     *
     * @return string
     */
    private static function goodsEmailBody(array $vars): string
    {
        $details = $vars['details'];
        $html    = '<p>' . osc_esc_html(sprintf(__('Thank you for your order from %s.'), $vars['site'])) . '</p>'
                   . '<p>' . osc_esc_html(sprintf(__('Order %1$s, paid on %2$s by %3$s.'), $vars['label'], $vars['date'], $vars['method'])) . '</p>'
                   . self::linesHtml($details)
                   . self::addressHtml($details);
        if ($details['note'] !== '') {
            $html .= '<p>' . nl2br(osc_esc_html($details['note']), false) . '</p>';
        }
        $html .= '<p>' . osc_esc_html(__('We will send you another e-mail when your order ships.')) . '</p>'
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
