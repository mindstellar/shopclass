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
     * Issue the invoice of a paid order, once. A second call gives the stored invoice, even with
     * invoices switched off since.
     *
     * @return Document|null null when the order takes none: unpaid, free, or invoices are off
     * @throws Throwable when composing or storing fails, or the amount changed on both tries;
     *                   issueOrQueue() catches it and the job tries again
     */
    public static function issue(Order $order): ?Document
    {
        $existing = DocumentStore::invoiceOf($order->getId());
        if ($existing !== null) {
            return $existing;
        }
        $order = OrderStore::find($order->getId());
        if ($order === null || !self::invoiceable($order)) {
            return null;
        }

        for ($try = 0; $try < 2; $try++) {
            $data   = self::composeInvoice($order);
            $amount = $order->getAmount();
            $result = DocumentStore::atomic(static function () use ($order, $data, $amount) {
                $locked = DocumentStore::lockOrder($order->getId());
                if ($locked === null) {
                    return null;
                }
                // Checked again under the lock: a webhook and the retry job may issue at once.
                $existing = DocumentStore::invoiceOf($locked->getId());
                if ($existing !== null) {
                    return $existing;
                }
                if (!self::invoiceable($locked)) {
                    return null;
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

        throw new RuntimeException('order amount changed while the invoice was issued');
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
     * What returning these units refunds. With line tax, a taxed line's share of its base (after any
     * discount) plus its own tax; else the price plus tax in the invoice's proportion. Null when there is no invoice.
     *
     * @param array<int,int> $lines Item line id => units
     */
    public static function linesAmount(int $orderId, array $lines): ?int
    {
        $invoice = DocumentStore::invoiceOf($orderId);
        if ($invoice === null) {
            return null;
        }
        $invLines = array_values(array_filter((array) ($invoice->data['lines'] ?? array()), 'is_array'));
        if (self::hasLineTax($invLines)) {
            $value  = 0;
            $shares = self::lineShares($orderId, $invoice, $invLines, $lines);
            foreach ($invLines as $line) {
                $id = (int) ($line['line_id'] ?? 0);
                if (($line['kind'] ?? '') === OrderLineStore::KIND_ITEM && isset($lines[$id])) {
                    $value += $shares['value'][$id] ?? (int) ($line['unit'] ?? 0) * max(0, (int) $lines[$id]);
                }
            }

            return $value;
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
                'fields'  => Receipts::buyerFields($order),
            ),
            'currency'    => array('code' => $currency, 'decimals' => $money->decimals($currency)),
            'lines'       => self::orderLines($order, (string) ($order->isGoods() || $order->isListing() ? $order->getLabel() : $vars['credits'])),
            'totals'      => array('total' => $order->getAmount(), 'total_text' => $money->money($order->getAmount(), $currency, false, true)),
            'wording'     => array(
                'title'      => $title !== '' && $title !== __('Receipt') ? $title : __('Invoice'),
                'extra'      => (array) ($details['extra'] ?? array()),
                'summary'    => (array) ($details['summary'] ?? array()),
                'footer'     => (string) ($details['footer'] ?? ''),
                'code_label' => __('Tax code'),
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
        $rows     = OrderLineStore::ofOrder($order->getId());
        $taxes    = self::checkedLineTax($rows, $currency);
        foreach ($rows as $line) {
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
                'unit_text'   => $money->money((int) $line['unit'], $currency, $ship, true),
                'amount_text' => $money->money((int) $line['amount'], $currency, $ship, true),
                'included'    => $line['kind'] === OrderLineStore::KIND_TAX && ($line['meta']['included'] ?? false) === true,
            ) + (isset($taxes[(int) $line['id']]) ? array('tax' => $taxes[(int) $line['id']]) : array());
        }
        if ($out === array()) {
            $out[] = self::line(null, OrderLineStore::KIND_ITEM, $title, 1, $order->getAmount(), $currency);
        }

        return $out;
    }

    /**
     * Draft a credit note: the checks, the lines with their own tax (or tax in proportion), and DocumentComposing.
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
            $lineMode = self::hasLineTax($invLines);
            $returned = array();
            foreach ($noteLines as $line) {
                $returned[(int) $line['line_id']] = (int) $line['qty'];
            }
            $byLine  = $lineMode && $returned !== array();
            $taxDone = self::refundedTax($orderId)['tax'];
            if ($byLine) {
                $shares = self::lineShares($orderId, $invoice, $invLines, $returned);
                $worth  = (int) self::linesAmount($orderId, $returned);
                if ($amount < $worth) {
                    // A typed amount below what the units are worth scales their tax down with it.
                    $shares = self::scaledShares($shares, $amount, $worth, $decimals, $currency);
                }
                foreach ($noteLines as $i => $line) {
                    if (isset($shares['lines'][(int) $line['line_id']])) {
                        $noteLines[$i]['tax'] = $shares['lines'][(int) $line['line_id']];
                    }
                }
            }
            foreach ($invLines as $line) {
                if (($line['kind'] ?? '') !== OrderLineStore::KIND_TAX) {
                    continue;
                }
                $id   = isset($line['line_id']) ? (int) $line['line_id'] : 0;
                $left = self::taxValue($line, $lineMode) - ($taxDone[$id] ?? 0);
                if ($closing) {
                    $share = $left;
                } elseif ($byLine) {
                    $share = $shares['tax'][$id] ?? 0;
                } else {
                    $share = max(0, min($left, self::roundTo((int) $line['amount'] * $amount / $invoice->total, $decimals)));
                }
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
            'totals'      => array('total' => $amount, 'total_text' => CurrencyService::make()->money($amount, $currency, false, true)),
            'wording'     => array(
                'title'   => __('Credit note'),
                'extra'   => (array) ($wording['extra'] ?? array()),
                'summary' => array(),
                'footer'  => (string) ($wording['footer'] ?? ''),
            ) + (isset($wording['code_label']) ? array('code_label' => (string) $wording['code_label']) : array()),
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
     * What the order's credit notes already returned: tax by the invoice's tax line id, units by
     * item line id, and each item line's own tax by item line id and tax line id.
     *
     * @return array{tax:array<int,int>,qty:array<int,int>,parts:array<int,array<int,int>>}
     */
    private static function refundedTax(int $orderId): array
    {
        $done = array('tax' => array(), 'qty' => array(), 'parts' => array());
        foreach (DocumentStore::forOrder($orderId) as $doc) {
            if (!$doc->isCreditNote()) {
                continue;
            }
            foreach ((array) ($doc->data['lines'] ?? array()) as $line) {
                $id = is_array($line) ? (int) ($line['line_id'] ?? 0) : 0;
                if ($id < 1) {
                    continue;
                }
                $kind = (string) ($line['kind'] ?? '');
                if ($kind === OrderLineStore::KIND_TAX) {
                    $done['tax'][$id] = ($done['tax'][$id] ?? 0) + (int) $line['amount'];
                } elseif ($kind === OrderLineStore::KIND_ITEM) {
                    $done['qty'][$id] = ($done['qty'][$id] ?? 0) + (int) ($line['qty'] ?? 0);
                    foreach ((array) ($line['tax']['parts'] ?? array()) as $part) {
                        $taxId = is_array($part) ? (int) ($part['line_id'] ?? 0) : 0;
                        if ($taxId > 0) {
                            $done['parts'][$id][$taxId] = ($done['parts'][$id][$taxId] ?? 0) + (int) ($part['amount'] ?? 0);
                        }
                    }
                }
            }
        }

        return $done;
    }

    /**
     * Whether an invoice's item or shipping lines carry their own tax.
     *
     * @param list<array<string,mixed>> $invLines
     */
    private static function hasLineTax(array $invLines): bool
    {
        foreach ($invLines as $line) {
            if (is_array($line['tax'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An invoice tax line's tax. A tax already in the prices is its unit, but only with line tax:
     * older invoices refund included tax as 0, as before.
     *
     * @param array<string,mixed> $line
     */
    private static function taxValue(array $line, bool $lineMode): int
    {
        return $lineMode && !empty($line['included']) ? (int) ($line['unit'] ?? 0) : (int) ($line['amount'] ?? 0);
    }

    /**
     * The own tax of returned item lines: per part, the share of the units returned so far less
     * what earlier notes took, the last unit taking the exact rest. Capped at what is left of each
     * tax line, so no tax line is refunded past its amount.
     *
     * @param list<array<string,mixed>> $invLines
     * @param array<int,int>            $lines Item line id => units
     *
     * @return array{lines:array<int,array<string,mixed>>,tax:array<int,int>,included:array<int,bool>,value:array<int,int>}
     */
    private static function lineShares(int $orderId, Document $invoice, array $invLines, array $lines): array
    {
        $decimals = (int) ($invoice->data['currency']['decimals'] ?? CurrencyService::make()->decimals($invoice->currency));
        $done     = self::refundedTax($orderId);
        $left     = array();
        $included = array();
        foreach ($invLines as $line) {
            if (($line['kind'] ?? '') === OrderLineStore::KIND_TAX && !empty($line['line_id'])) {
                $id            = (int) $line['line_id'];
                $left[$id]     = self::taxValue($line, true) - ($done['tax'][$id] ?? 0);
                $included[$id] = !empty($line['included']);
            }
        }

        $out = array('lines' => array(), 'tax' => array(), 'included' => $included, 'value' => array());
        foreach ($invLines as $line) {
            $id  = (int) ($line['line_id'] ?? 0);
            $tax = $line['tax'] ?? null;
            if (($line['kind'] ?? '') !== OrderLineStore::KIND_ITEM || !isset($lines[$id]) || !is_array($tax)) {
                continue;
            }
            $qty   = max(1, (int) $line['qty']);
            $was   = min($qty, $done['qty'][$id] ?? 0);
            $now   = min($qty, $was + max(0, (int) $lines[$id]));
            $part  = static fn (int $whole, int $units): int => $units >= $qty ? $whole : self::roundTo($whole * $units / $qty, $decimals);
            $parts = array();
            foreach ((array) ($tax['parts'] ?? array()) as $p) {
                $taxId = (int) ($p['line_id'] ?? 0);
                if (!isset($left[$taxId])) {
                    continue;
                }
                $share = max(0, $part((int) $p['amount'], $now) - ($done['parts'][$id][$taxId] ?? 0));
                $share = min($share, max(0, $left[$taxId]));
                $left[$taxId] -= $share;
                $out['tax'][$taxId] = ($out['tax'][$taxId] ?? 0) + $share;
                $parts[] = array('line_id' => $taxId, 'label' => (string) $p['label'], 'bp' => (int) $p['bp'], 'amount' => $share);
            }
            $base     = (int) ($tax['base'] ?? 0);
            $baseNow  = $part($base, $now) - $part($base, $was);
            $out['value'][$id] = $baseNow + array_sum(array_column($parts, 'amount'));
            if ($parts !== array()) {
                $out['lines'][$id] = self::docLineTax(!empty($tax['included']), $baseNow, isset($tax['code']) ? (string) $tax['code'] : null, $parts, $invoice->currency);
            }
        }

        return $out;
    }

    /**
     * Line shares scaled by $amount / $worth, for a note that refunds less than the returned units are worth.
     *
     * @param array{lines:array<int,array<string,mixed>>,tax:array<int,int>,included:array<int,bool>,value:array<int,int>} $shares
     *
     * @return array{lines:array<int,array<string,mixed>>,tax:array<int,int>,included:array<int,bool>,value:array<int,int>}
     */
    private static function scaledShares(array $shares, int $amount, int $worth, int $decimals, string $currency): array
    {
        $scale = static fn (int $value): int => self::roundTo($value * $amount / $worth, $decimals);
        $shares['tax'] = array_map(static fn (int $share): int => 0, $shares['tax']);
        foreach ($shares['lines'] as $id => $tax) {
            $parts = array();
            foreach ((array) $tax['parts'] as $part) {
                $part['amount'] = $scale((int) $part['amount']);
                $shares['tax'][(int) $part['line_id']] += $part['amount'];
                $parts[] = $part;
            }
            $shares['lines'][$id] = self::docLineTax((bool) $tax['included'], $scale((int) $tax['base']), $tax['code'] ?? null, $parts, $currency);
        }

        return $shares;
    }

    /**
     * The checked own tax of each item and shipping order line, by line id, as frozen on an
     * invoice. Empty when any part names no tax line, or the parts do not add up to every tax line.
     *
     * @param array<int,array<string,mixed>> $rows The order's lines
     *
     * @return array<int,array<string,mixed>>
     */
    private static function checkedLineTax(array $rows, string $currency): array
    {
        $taxLines = array();
        $lineTax  = array();
        foreach ($rows as $row) {
            if ($row['kind'] === OrderLineStore::KIND_TAX) {
                $included = ($row['meta']['included'] ?? false) === true;
                $key      = (string) ($row['meta']['id'] ?? '');
                if ($key !== '' && isset($taxLines[$key])) {
                    return array();
                }
                $taxLines[$key !== '' ? $key : '#' . $row['id']] = array('id' => (int) $row['id'], 'value' => $included ? (int) $row['unit'] : (int) $row['amount'], 'included' => $included);
            } elseif (($tax = OrderLineStore::lineTax($row)) !== null) {
                $lineTax[(int) $row['id']] = $tax;
            }
        }
        if ($lineTax === array()) {
            return array();
        }

        if (LineTax::fault($lineTax, array_map(static fn (array $t): array => array($t['value'], $t['included']), $taxLines)) !== null) {
            return array();
        }
        $out = array();
        foreach ($lineTax as $id => $tax) {
            $parts = array();
            foreach ($tax->parts as $part) {
                $parts[] = array('line_id' => $taxLines[$part['tax']]['id'], 'label' => $part['label'], 'bp' => $part['bp'], 'amount' => $part['amount']);
            }
            $out[$id] = self::docLineTax($tax->included, $tax->base, $tax->code, $parts, $currency);
        }

        return $out;
    }

    /**
     * A line's own tax as a document stores it, with its display text.
     *
     * @param list<array{line_id:int,label:string,bp:int,amount:int}> $parts
     *
     * @return array<string,mixed>
     */
    private static function docLineTax(bool $included, int $base, ?string $code, array $parts, string $currency): array
    {
        return LineTaxView::shape($included, $base, $code, $parts, $currency);
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
            'unit_text'   => $money->money($unit, $currency, false, true),
            'amount_text' => $money->money($amount, $currency, false, true),
            'included'    => false,
        );
    }

    /**
     * What an invoice for this order calls a line's tax code, for pages that show it live.
     */
    public static function codeLabel(Order $order): string
    {
        $default = __('Tax code');
        try {
            $event = new DocumentComposing(array('wording' => array('code_label' => $default)), $order, Document::INVOICE);
            CoreServices::dispatcher()->dispatch($event);
            $label = $event->data()['wording']['code_label'] ?? '';
        } catch (Throwable) {
            return $default;
        }

        return is_string($label) && trim($label) !== '' ? trim($label) : $default;
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
            unset($lines[$i]['tax']);
            if (isset($core['tax'])) {
                $lines[$i]['tax'] = $core['tax'];
            }
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

    /**
     * Queue the job that issues the order's invoice. Called inside the paid write, so a paid order
     * always has the job even when the request dies before the invoice is issued.
     */
    public static function queueForPaid(Order $order): void
    {
        if (self::enabled() && $order->getAmount() > 0) {
            self::queue($order->getId());
        }
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
