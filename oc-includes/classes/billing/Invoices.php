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

use mindstellar\billing\event\DocumentComposing;
use mindstellar\billing\event\DocumentIssued;
use mindstellar\boot\CoreServices;
use mindstellar\currency\CurrencyService;
use mindstellar\database\Db;
use RuntimeException;
use Throwable;

/**
 * Invoices and credit notes: what goes on them, frozen once, and when they are issued.
 * Composing (and every plugin listener) runs before the write; the write locks the order, checks
 * again and numbers the document in one transaction.
 *
 * @package mindstellar\billing
 */
final class Invoices
{
    /** Preference: issue numbered invoices. On unless switched off. */
    public const PREF_ON = 'billing_invoice_on';

    /** Job that issues an invoice, or a refunded order's last credit note, that could not be issued at once. */
    public const JOB = 'billing.invoice';

    /** Order line fields a compose listener cannot change. */
    private const LINE_MONEY = array('line_id', 'kind', 'qty', 'unit', 'amount', 'included');

    /** s_data fields a compose listener cannot change. */
    private const FIXED = array('tax_point', 'order', 'ref_invoice', 'currency', 'refund');

    /**
     * Whether invoices are issued.
     */
    public static function enabled(): bool
    {
        $value = osc_get_preference(self::PREF_ON, Billing::PREF_GROUP);

        return $value === null || $value === '' ? true : (bool) $value;
    }

    /**
     * Issue the invoice of a paid order, once. A second call gives the stored invoice.
     *
     * @return Document|null null when the order takes none: unpaid, free, or invoices are off
     * @throws Throwable when composing or storing fails; issueOrQueue() catches it
     */
    public static function issue(Order $order): ?Document
    {
        $order = OrderStore::find($order->getId());
        if ($order === null || !self::invoiceable($order)) {
            return null;
        }
        $existing = DocumentStore::invoiceOf($order->getId());
        if ($existing !== null) {
            return $existing;
        }

        for ($try = 0; $try < 2; $try++) {
            $data   = self::composeInvoice($order);
            $amount = $order->getAmount();
            $result = DocumentStore::atomic(static function () use ($order, $data, $amount) {
                $locked = DocumentStore::lockOrder($order->getId());
                if ($locked === null || !self::invoiceable($locked)) {
                    return null;
                }
                $existing = DocumentStore::invoiceOf($locked->getId());
                if ($existing !== null) {
                    return $existing;
                }
                if ($locked->getAmount() !== $amount) {
                    return false;
                }

                return self::store(Document::INVOICE, $locked, $amount, $data);
            });
            if ($result !== false) {
                return $result;
            }
            $order = OrderStore::find($order->getId()) ?? $order;
        }

        return null;
    }

    /**
     * Issue what the order is missing (see catchUp()), or queue it when that fails. Never throws:
     * a payment or refund must not fail on a document.
     */
    public static function issueOrQueue(Order $order): void
    {
        try {
            self::catchUp($order->getId());
        } catch (Throwable $e) {
            self::logFailure('invoice', $order->getId(), $e);
            self::queue($order->getId());
        }
    }

    /**
     * Issue a credit note against the order's invoice for an amount and, optionally, item lines.
     *
     * @param Order          $order
     * @param int            $amount      Micros, at most what is left to refund
     * @param array<int,int> $lines       Item line id => units returned with this note
     * @param string         $reason      Plain text
     * @param string|null    $providerRef The provider's refund id
     * @param array<int,int> $restock     Item line id => units put back in stock
     *
     * @return Document|null null when the order has no invoice, or the amount or a line is more
     *                       than is left to refund
     * @throws Throwable when composing or storing fails
     */
    public static function creditNote(Order $order, int $amount, array $lines, string $reason, ?string $providerRef, array $restock): ?Document
    {
        for ($try = 0; $try < 2; $try++) {
            $draft = self::draftNote($order->getId(), $amount, $lines, $reason, $providerRef, $restock);
            if ($draft === null) {
                return null;
            }
            $result = DocumentStore::atomic(static fn () => self::storeNote($draft));
            if ($result !== false) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Compose the note for everything not yet refunded, before a full refund's transaction.
     * Composing failures are logged and queued for the job, never thrown.
     *
     * @return array<string,mixed>|null null when there is nothing to note
     */
    public static function remainderDraft(Order $order): ?array
    {
        try {
            return self::draftNote($order->getId(), null, null, '', null, array());
        } catch (Throwable $e) {
            self::logFailure('credit note', $order->getId(), $e);
            self::queue($order->getId());

            return null;
        }
    }

    /**
     * Compose a credit note for part of an order, before the refund's transaction. Unlike
     * remainderDraft() a composing failure is thrown, so the refund stops before any money moves.
     *
     * @param array<int,int>|null $lines   Item line id => units; null is every unit not refunded yet
     * @param array<int,int>      $restock Item line id => units put back in stock
     *
     * @return array<string,mixed>|null null when there is no invoice, or the amount or a line is
     *                                  more than is left to refund
     * @throws Throwable
     */
    public static function partDraft(Order $order, int $amount, ?array $lines, string $reason, array $restock): ?array
    {
        return self::draftNote($order->getId(), $amount, $lines, $reason, null, $restock);
    }

    /**
     * A draft with the provider's refund id set, once the provider has answered.
     *
     * @param array<string,mixed> $draft
     *
     * @return array<string,mixed>
     */
    public static function withProviderRef(array $draft, ?string $providerRef): array
    {
        $draft['data']['refund']['provider_ref'] = $providerRef !== null && $providerRef !== '' ? Receipts::plain($providerRef, 200) : null;

        return $draft;
    }

    /**
     * What returning these units refunds: their price plus the tax added on top in the
     * invoice's proportion, rounded to the currency. Null when the order has no invoice.
     *
     * @param array<int,int> $lines Item line id => units
     */
    public static function linesAmount(int $orderId, array $lines): ?int
    {
        $invoice = DocumentStore::invoiceOf($orderId);
        if ($invoice === null) {
            return null;
        }
        $base  = 0;
        $value = 0;
        foreach ((array) ($invoice->data['lines'] ?? array()) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $kind = (string) ($line['kind'] ?? '');
            if ($kind !== OrderLineStore::KIND_TAX) {
                $base += (int) ($line['amount'] ?? 0);
            }
            $id = (int) ($line['line_id'] ?? 0);
            if ($kind === OrderLineStore::KIND_ITEM && isset($lines[$id])) {
                $value += (int) ($line['unit'] ?? 0) * max(0, (int) $lines[$id]);
            }
        }
        if ($base < 1) {
            return $value;
        }
        $decimals = (int) ($invoice->data['currency']['decimals'] ?? CurrencyService::make()->decimals($invoice->currency));

        return self::roundTo($value * $invoice->total / $base, $decimals);
    }

    /**
     * Store a drafted credit note inside the caller's transaction, after the order row is locked.
     *
     * @param array<string,mixed> $draft From remainderDraft()
     *
     * @return Document|false|null false when other notes were stored since it was drafted
     */
    public static function storeNote(array $draft): Document|false|null
    {
        $locked = DocumentStore::lockOrder((int) $draft['order_id']);
        if ($locked === null) {
            return null;
        }
        if (DocumentStore::refundedTotal($locked->getId()) !== $draft['refunded']) {
            return false;
        }

        return self::store(Document::CREDIT_NOTE, $locked, (int) $draft['amount'], $draft['data']);
    }

    /**
     * Register the job that issues a late invoice or credit note; called from Billing::registerJobs().
     */
    public static function registerJobs(): void
    {
        osc_job_register_handler(self::JOB, static function (\mindstellar\job\Job $job): void {
            self::catchUp((int) $job->get('order_id', 0));
        });
        osc_job_describe(self::JOB, __('Order invoice'), static fn (array $payload): string => sprintf(__('Order #%d'), (int) ($payload['order_id'] ?? 0)));
    }

    /**
     * Issue what an order is missing: its invoice, and the last credit note of a refunded order.
     *
     * @throws Throwable so the queue tries again
     */
    public static function catchUp(int $orderId): void
    {
        $order = OrderStore::find($orderId);
        if ($order === null) {
            return;
        }
        $invoice = self::issue($order);
        if ($invoice === null || $order->getStatus() !== Order::STATUS_REFUNDED) {
            return;
        }
        $left = $invoice->total - DocumentStore::refundedTotal($orderId);
        if ($left < 1) {
            return;
        }
        $draft = self::draftNote($orderId, null, null, '', null, array());
        if ($draft !== null && DocumentStore::atomic(static fn () => self::storeNote($draft)) === false) {
            throw new RuntimeException('credit note changed while it was issued');
        }
    }

    /**
     * The document's printable page.
     */
    public static function url(Document $document): string
    {
        return osc_core_url('billing_document', array('id' => $document->id));
    }

    /**
     * Whether a visitor may see a document: an admin, or the order's buyer.
     */
    public static function canView(Document $document, int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        if ($userId < 1) {
            return false;
        }
        $order = OrderStore::find($document->orderId);

        return $order !== null && $order->getUserId() === $userId;
    }

    /**
     * Whether an order takes an invoice: invoices are on, it cost something and it was paid.
     */
    private static function invoiceable(Order $order): bool
    {
        return self::enabled() && $order->getAmount() > 0 && Receipts::available($order);
    }

    /**
     * Number and insert, and announce it once the transaction commits.
     *
     * @param array<string,mixed> $data
     */
    private static function store(string $type, Order $order, int $total, array $data): Document
    {
        $document = DocumentStore::insert($type, $order, $total, $data);
        Db::afterCommit(static fn () => CoreServices::dispatcher()->dispatch(new DocumentIssued($document->id, $document->orderId, $document->type)));

        return $document;
    }

    /**
     * The invoice body: the receipt's data, with ReceiptComposing extras, then DocumentComposing.
     *
     * @return array<string,mixed>
     */
    private static function composeInvoice(Order $order): array
    {
        $vars     = Receipts::vars($order);
        $details  = is_array($vars['details'] ?? null) ? $vars['details'] : array();
        $currency = $order->getCurrency();
        $money    = CurrencyService::make();
        $title    = (string) ($details['title'] ?? '');

        $data = array(
            'tax_point'   => $order->getPaidDate() ?? $order->getDate(),
            'order'       => array(
                'id'     => $order->getId(),
                'label'  => (string) $vars['label'],
                'method' => (string) $vars['method'],
                'ref'    => (string) $vars['ref'],
            ),
            'ref_invoice' => null,
            'seller'      => array(
                'name'    => (string) $vars['site'],
                'details' => self::textLines((string) $vars['business']),
            ),
            'buyer'       => array(
                'name'    => (string) $vars['name'],
                'email'   => (string) $vars['email'],
                'address' => array_values((array) ($details['address'] ?? array())),
            ),
            'currency'    => array('code' => $currency, 'decimals' => $money->decimals($currency)),
            'lines'       => self::orderLines($order, (string) ($order->isGoods() || $order->isListing() ? $order->getLabel() : $vars['credits'])),
            'totals'      => array('total' => $order->getAmount(), 'total_text' => $money->money($order->getAmount(), $currency)),
            'wording'     => array(
                'title'   => $title !== '' && $title !== __('Receipt') ? $title : __('Invoice'),
                'extra'   => (array) ($details['extra'] ?? array()),
                'summary' => (array) ($details['summary'] ?? array()),
                'footer'  => (string) ($details['footer'] ?? ''),
            ),
            'refund'      => null,
        );

        return self::compose($data, $order, Document::INVOICE);
    }

    /**
     * The order's lines as frozen document lines, or one line for an order without any.
     *
     * @return list<array<string,mixed>>
     */
    private static function orderLines(Order $order, string $title): array
    {
        $currency = $order->getCurrency();
        $money    = CurrencyService::make();
        $out      = array();
        foreach (OrderLineStore::ofOrder($order->getId()) as $line) {
            $ship    = $line['kind'] === OrderLineStore::KIND_SHIPPING;
            $options = array();
            foreach ((array) ($line['options'] ?? array()) as $name => $value) {
                $options[] = $name . ': ' . $value;
            }
            $out[] = array(
                'line_id'     => (int) $line['id'],
                'kind'        => (string) $line['kind'],
                'title'       => (string) $line['title'],
                'options'     => implode(', ', $options),
                'sku'         => (string) ($line['sku'] ?? ''),
                'qty'         => (int) $line['qty'],
                'unit'        => (int) $line['unit'],
                'amount'      => (int) $line['amount'],
                'unit_text'   => $money->money((int) $line['unit'], $currency, $ship),
                'amount_text' => $money->money((int) $line['amount'], $currency, $ship),
                'included'    => $line['kind'] === OrderLineStore::KIND_TAX && ($line['meta']['included'] ?? false) === true,
            );
        }
        if ($out === array()) {
            $out[] = self::line(null, OrderLineStore::KIND_ITEM, $title, 1, $order->getAmount(), $currency);
        }

        return $out;
    }

    /**
     * Draft a credit note: the checks, the lines with tax in proportion, and DocumentComposing.
     *
     * @param array<int,int>|null $lines Null is every unit not yet refunded, for the note that closes the order
     * @param array<int,int>      $restock
     *
     * @return array{order_id:int,amount:int,refunded:int,data:array<string,mixed>}|null
     */
    private static function draftNote(int $orderId, ?int $amount, ?array $lines, string $reason, ?string $providerRef, array $restock): ?array
    {
        $order   = OrderStore::find($orderId);
        $invoice = DocumentStore::invoiceOf($orderId);
        if ($order === null || $invoice === null) {
            return null;
        }
        $refunded = DocumentStore::refundedTotal($orderId);
        $left     = $invoice->total - $refunded;
        $amount ??= $left;
        if ($amount < 1 || $amount > $left) {
            return null;
        }

        $inv      = $invoice->data;
        $currency = $invoice->currency;
        $decimals = (int) ($inv['currency']['decimals'] ?? CurrencyService::make()->decimals($currency));
        $invLines = array_values(array_filter((array) ($inv['lines'] ?? array()), 'is_array'));
        $closing  = $amount === $left;

        if ($lines === null && $refunded === 0) {
            // The whole order in one note: the same lines as the invoice.
            $noteLines = $invLines;
        } else {
            $noteLines = self::returnedLines($orderId, $invLines, $lines, $currency);
            if ($noteLines === null) {
                return null;
            }
            $taxDone = self::refundedTax($orderId);
            foreach ($invLines as $line) {
                if (($line['kind'] ?? '') !== OrderLineStore::KIND_TAX) {
                    continue;
                }
                $id    = isset($line['line_id']) ? (int) $line['line_id'] : 0;
                $share = $closing
                    ? (int) $line['amount'] - ($taxDone[$id] ?? 0)
                    : self::roundTo((int) $line['amount'] * $amount / $invoice->total, $decimals);
                if ($share !== 0) {
                    $noteLines[] = array('included' => !empty($line['included'])) + self::line($id ?: null, OrderLineStore::KIND_TAX, (string) $line['title'], 1, $share, $currency);
                }
            }
            $sum = 0;
            foreach ($noteLines as $line) {
                $sum += empty($line['included']) ? (int) $line['amount'] : 0;
            }
            if ($sum !== $amount) {
                $noteLines[] = self::line(null, 'adjustment', $lines === array() ? __('Refund') : __('Adjustment'), 1, $amount - $sum, $currency);
            }
        }

        $restocked = array();
        foreach ($restock as $lineId => $qty) {
            if ((int) $qty > 0) {
                $restocked[(int) $lineId] = (int) $qty;
            }
        }
        $wording = (array) ($inv['wording'] ?? array());
        $data    = array(
            'tax_point'   => $inv['tax_point'] ?? null,
            'order'       => $inv['order'] ?? array('id' => $orderId),
            'ref_invoice' => array('number' => $invoice->number, 'date' => $invoice->date),
            'seller'      => $inv['seller'] ?? array(),
            'buyer'       => $inv['buyer'] ?? array(),
            'currency'    => array('code' => $currency, 'decimals' => $decimals),
            'lines'       => $noteLines,
            'totals'      => array('total' => $amount, 'total_text' => CurrencyService::make()->money($amount, $currency)),
            'wording'     => array(
                'title'   => __('Credit note'),
                'extra'   => (array) ($wording['extra'] ?? array()),
                'summary' => array(),
                'footer'  => (string) ($wording['footer'] ?? ''),
            ),
            'refund'      => array(
                'reason'       => Receipts::plain($reason, 500, true),
                'provider_ref' => $providerRef !== null && $providerRef !== '' ? Receipts::plain($providerRef, 200) : null,
                'restocked'    => $restocked,
            ),
        );

        return array(
            'order_id' => $orderId,
            'amount'   => $amount,
            'refunded' => $refunded,
            'data'     => self::compose($data, $order, Document::CREDIT_NOTE),
        );
    }

    /**
     * The item lines a note returns. Null asks for every unit not refunded yet.
     *
     * @param list<array<string,mixed>> $invLines
     * @param array<int,int>|null       $lines
     *
     * @return list<array<string,mixed>>|null null when a line is unknown or more units are asked than are left
     */
    private static function returnedLines(int $orderId, array $invLines, ?array $lines, string $currency): ?array
    {
        $items = array();
        foreach ($invLines as $line) {
            if (($line['kind'] ?? '') === OrderLineStore::KIND_ITEM && !empty($line['line_id'])) {
                $items[(int) $line['line_id']] = $line;
            }
        }
        $done = DocumentStore::refundedQty($orderId);
        if ($lines === null) {
            $lines = array();
            foreach ($items as $id => $line) {
                $lines[$id] = (int) $line['qty'] - ($done[$id] ?? 0);
            }
            $lines = array_filter($lines, static fn (int $qty): bool => $qty > 0);
        }

        $out = array();
        foreach ($lines as $id => $qty) {
            $id  = (int) $id;
            $qty = (int) $qty;
            if (!isset($items[$id]) || $qty < 1 || ($done[$id] ?? 0) + $qty > (int) $items[$id]['qty']) {
                return null;
            }
            $line = self::line($id, OrderLineStore::KIND_ITEM, (string) $items[$id]['title'], $qty, (int) $items[$id]['unit'] * $qty, $currency, (int) $items[$id]['unit']);
            $out[] = array('options' => (string) ($items[$id]['options'] ?? ''), 'sku' => (string) ($items[$id]['sku'] ?? '')) + $line;
        }

        return $out;
    }

    /**
     * Tax already on the order's credit notes, by the invoice's tax line id.
     *
     * @return array<int,int>
     */
    private static function refundedTax(int $orderId): array
    {
        $done = array();
        foreach (DocumentStore::forOrder($orderId) as $doc) {
            if (!$doc->isCreditNote()) {
                continue;
            }
            foreach ((array) ($doc->data['lines'] ?? array()) as $line) {
                if (is_array($line) && ($line['kind'] ?? '') === OrderLineStore::KIND_TAX && !empty($line['line_id'])) {
                    $done[(int) $line['line_id']] = ($done[(int) $line['line_id']] ?? 0) + (int) $line['amount'];
                }
            }
        }

        return $done;
    }

    /**
     * One document line.
     *
     * @return array<string,mixed>
     */
    private static function line(?int $lineId, string $kind, string $title, int $qty, int $amount, string $currency, ?int $unit = null): array
    {
        $money = CurrencyService::make();
        $unit ??= $amount;

        return array(
            'line_id'     => $lineId,
            'kind'        => $kind,
            'title'       => $title,
            'options'     => '',
            'sku'         => '',
            'qty'         => $qty,
            'unit'        => $unit,
            'amount'      => $amount,
            'unit_text'   => $money->money($unit, $currency),
            'amount_text' => $money->money($amount, $currency),
            'included'    => false,
        );
    }

    /**
     * Let listeners shape the body, then put back the fields they may not change.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private static function compose(array $data, Order $order, string $type): array
    {
        $event = new DocumentComposing($data, $order, $type);
        CoreServices::dispatcher()->dispatch($event);
        $out = $event->data();

        foreach (self::FIXED as $key) {
            $out[$key] = $data[$key];
        }
        foreach (array('seller', 'buyer', 'wording') as $key) {
            if (!is_array($out[$key] ?? null)) {
                $out[$key] = $data[$key];
            }
        }
        $out['totals'] = $data['totals'];
        $lines         = $out['lines'] ?? null;
        if (!is_array($lines) || count($lines) !== count($data['lines'])) {
            $lines = $data['lines'];
        }
        $lines = array_values($lines);
        foreach ($lines as $i => $line) {
            $core      = $data['lines'][$i];
            $lines[$i] = is_array($line) ? array_intersect_key($core, array_flip(self::LINE_MONEY)) + $line + $core : $core;
        }
        $out['lines'] = $lines;
        unset($out['v'], $out['type'], $out['number'], $out['date']);

        return $out;
    }

    /**
     * Micros rounded to the currency's smallest unit.
     */
    private static function roundTo(float $micros, int $decimals): int
    {
        $step = 10 ** (6 - max(0, min(6, $decimals)));

        return (int) (round($micros / $step) * $step);
    }

    /**
     * Free text as trimmed, non-empty lines.
     *
     * @return list<string>
     */
    private static function textLines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $text))), static fn (string $l): bool => $l !== ''));
    }

    private static function queue(int $orderId): void
    {
        try {
            osc_job_enqueue(self::JOB, array('order_id' => $orderId), array('unique_key' => 'order:' . $orderId, 'delay' => 60));
        } catch (Throwable $e) {
            self::logFailure('job', $orderId, $e);
        }
    }

    /**
     * Log a failure by the exception's class and code, never its text.
     */
    private static function logFailure(string $what, int $orderId, Throwable $e): void
    {
        error_log(sprintf('Billing: %s for order #%d failed with %s (code %s)', $what, $orderId, get_class($e), (string) $e->getCode()));
    }
}
