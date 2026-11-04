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

use InvalidArgumentException;
use mindstellar\base\Model;
use mindstellar\database\Db;
use mindstellar\database\DbException;
use mindstellar\database\QueryBuilder;
use mindstellar\model\KeyValue;

/**
 * Persistence and numbering for t_billing_document. Rows are only inserted, never changed.
 * Each type has its own series per year (INV-2026-00042), counted in t_key_value.
 *
 * @package mindstellar\billing
 */
final class DocumentStore extends Model
{
    /** Unprefixed table name. */
    protected const TABLE = 't_billing_document';

    /** t_key_value group of the counters; the key is "<type>:<year>". */
    public const COUNTER_GROUP = 'billing_doc_seq';

    /** Preference holding each type's number prefix, and the prefix used when it is empty. */
    public const PREFIX_PREF = array(
        Document::INVOICE     => array('billing_invoice_prefix', 'INV-'),
        Document::CREDIT_NOTE => array('billing_credit_prefix', 'CN-'),
    );

    /** Errors after which the whole insert is tried once more: deadlock and lock wait timeout. */
    private const RETRY_ERRORS = array(1213, 1205);

    /**
     * Number and store a document for an order. The order row is locked first, then the counter,
     * and both writes share one transaction, so a failure leaves no gap in the series. A caller that
     * also moves stock or credits does that before this call: order row, then stock and wallet,
     * then the counter last.
     *
     * @param string              $type  Document::INVOICE or Document::CREDIT_NOTE
     * @param Order               $order
     * @param int                 $total Micros, positive
     * @param array<string,mixed> $data  The s_data body; v, type, number and date are filled here
     * @param string|null         $date  Issue datetime in site time; null is now
     *
     * @return Document
     * @throws InvalidArgumentException on an unknown type, a total below 1, or an unknown order
     * @throws DbException
     */
    public static function insert(string $type, Order $order, int $total, array $data, ?string $date = null): Document
    {
        if (!in_array($type, Document::TYPES, true)) {
            throw new InvalidArgumentException('Documents: unknown type "' . $type . '"');
        }
        if ($total < 1) {
            throw new InvalidArgumentException('Documents: total must be positive');
        }
        $date ??= date('Y-m-d H:i:s');
        if (!preg_match('/^(\d{4})-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date, $m)) {
            throw new InvalidArgumentException('Documents: date must be Y-m-d H:i:s');
        }
        $year = (int) $m[1];

        return self::atomic(static function () use ($type, $order, $total, $data, $date, $year): Document {
            if (self::lockOrder($order->getId()) === null) {
                throw new InvalidArgumentException('Documents: no order ' . $order->getId());
            }
            $number = self::prefix($type) . $year . '-' . str_pad((string) self::nextSeq($type, $year), 5, '0', STR_PAD_LEFT);
            $data   = array('v' => 1, 'type' => $type, 'number' => $number, 'date' => $date) + $data;
            $json   = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $id     = self::table()->insert(array(
                's_type'        => $type,
                's_number'      => $number,
                'dt_date'       => $date,
                'fk_i_order_id' => $order->getId(),
                'i_total'       => $total,
                's_currency'    => $order->getCurrency(),
                's_data'        => $json,
            ));

            return new Document($id, $type, $number, $date, $order->getId(), $total, $order->getCurrency(), $data);
        });
    }

    /**
     * Read the order row FOR UPDATE, inside the caller's transaction: always the first lock a
     * document write takes. Lock order is the order row, then stock and wallet, then the counter.
     */
    public static function lockOrder(int $orderId): ?Order
    {
        $row = Db::selectOne('SELECT * FROM ' . DB_TABLE_PREFIX . 't_billing_order WHERE pk_i_id = ? FOR UPDATE', array($orderId));

        return $row === null ? null : Order::fromRow($row);
    }

    /**
     * Run $fn in a transaction, and once more when it failed on a deadlock or lock wait timeout.
     * Inside a caller's transaction it runs once and the error goes up to that caller.
     *
     * @template T
     * @param callable():T $fn
     *
     * @return T
     * @throws DbException
     */
    public static function atomic(callable $fn): mixed
    {
        $nested = Db::inTransaction();
        try {
            return Db::transaction($fn);
        } catch (DbException $e) {
            if ($nested || !in_array((int) $e->getCode(), self::RETRY_ERRORS, true)) {
                throw $e;
            }
            usleep(50000);

            return Db::transaction($fn);
        }
    }

    /**
     * A type's number prefix: the preference, or the default when it is empty.
     *
     * @param string $type
     *
     * @return string
     */
    public static function prefix(string $type): string
    {
        [$pref, $default] = self::PREFIX_PREF[$type];
        $value = substr((string) preg_replace('#[^A-Z0-9/-]#', '', strtoupper((string) osc_get_preference($pref, 'osclass'))), 0, 12);

        return $value === '' ? $default : $value;
    }

    /**
     * One document, or null.
     */
    public static function find(int $id): ?Document
    {
        $row = self::table()->where('pk_i_id', $id)->first();

        return $row === null ? null : Document::fromRow($row);
    }

    /**
     * An order's documents, oldest first.
     *
     * @return list<Document>
     */
    public static function forOrder(int $orderId): array
    {
        $rows = self::table()->where('fk_i_order_id', $orderId)->orderBy('pk_i_id')->get();

        return array_map(static fn (array $row): Document => Document::fromRow($row), $rows);
    }

    /**
     * The documents of several orders in one query, by order id, oldest first. An order with none has no entry.
     *
     * @param int[] $orderIds
     *
     * @return array<int,list<Document>>
     */
    public static function forOrders(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if ($orderIds === array()) {
            return array();
        }
        $rows = self::table()->whereIn('fk_i_order_id', $orderIds)->orderBy('pk_i_id')->get();
        $out  = array();
        foreach ($rows as $row) {
            $out[(int) $row['fk_i_order_id']][] = Document::fromRow($row);
        }

        return $out;
    }

    /**
     * The order's invoice, or null when it has none.
     */
    public static function invoiceOf(int $orderId): ?Document
    {
        $row = self::table()->where('fk_i_order_id', $orderId)->where('s_type', Document::INVOICE)->orderBy('pk_i_id')->first();

        return $row === null ? null : Document::fromRow($row);
    }

    /**
     * Micros refunded so far: the sum of the order's credit notes.
     */
    public static function refundedTotal(int $orderId): int
    {
        return (int) Db::scalar(
            'SELECT COALESCE(SUM(i_total), 0) FROM ' . self::tableName() . ' WHERE fk_i_order_id = ? AND s_type = ?',
            array($orderId, Document::CREDIT_NOTE)
        );
    }

    /**
     * What credit notes have refunded, for several orders in one query. An order with none has no entry.
     *
     * @param int[] $orderIds
     *
     * @return array<int,int> order id => micros
     */
    public static function refundedTotals(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if ($orderIds === array()) {
            return array();
        }
        $rows = self::table()->select('fk_i_order_id')->selectRaw('SUM(i_total) AS refunded')
            ->where('s_type', Document::CREDIT_NOTE)->whereIn('fk_i_order_id', $orderIds)->groupBy('fk_i_order_id')->get();
        $out  = array();
        foreach ($rows as $row) {
            $out[(int) $row['fk_i_order_id']] = (int) $row['refunded'];
        }

        return $out;
    }

    /**
     * Quantity refunded so far per order line, from the lines of the order's credit notes.
     *
     * @return array<int,int> line id => quantity
     */
    public static function refundedQty(int $orderId): array
    {
        $qty = array();
        foreach (self::forOrder($orderId) as $doc) {
            if (!$doc->isCreditNote()) {
                continue;
            }
            foreach ((array) ($doc->data['lines'] ?? array()) as $line) {
                $lineId = is_array($line) ? (int) ($line['line_id'] ?? 0) : 0;
                if ($lineId > 0) {
                    $qty[$lineId] = ($qty[$lineId] ?? 0) + (int) ($line['qty'] ?? 0);
                }
            }
        }

        return $qty;
    }

    /**
     * The admin list, newest first.
     *
     * @param array{type?:string,from?:string,to?:string,order_id?:int} $filters from/to are Y-m-d days, both included
     * @param int                                                       $limit
     * @param int                                                       $offset
     *
     * @return list<Document>
     */
    public static function search(array $filters, int $limit = 25, int $offset = 0): array
    {
        $rows = self::filtered($filters)->orderBy('dt_date', 'DESC')->orderBy('pk_i_id', 'DESC')->limit(max(1, $limit))->offset(max(0, $offset))->get();

        return array_map(static fn (array $row): Document => Document::fromRow($row), $rows);
    }

    /**
     * How many documents search() matches, for the pager.
     *
     * @param array{type?:string,from?:string,to?:string,order_id?:int} $filters
     */
    public static function count(array $filters): int
    {
        return self::filtered($filters)->count();
    }

    /**
     * The search() query, for the admin list and its EXPLAIN check.
     *
     * @param array{type?:string,from?:string,to?:string,order_id?:int} $filters
     */
    public static function filtered(array $filters): QueryBuilder
    {
        $q = self::table();
        if (!empty($filters['type'])) {
            $q = $q->where('s_type', (string) $filters['type']);
        }
        if (!empty($filters['order_id'])) {
            $q = $q->where('fk_i_order_id', (int) $filters['order_id']);
        }
        if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['from'])) {
            $q = $q->where('dt_date', '>=', $filters['from'] . ' 00:00:00');
        }
        if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['to'])) {
            $q = $q->where('dt_date', '<=', $filters['to'] . ' 23:59:59');
        }

        return $q;
    }

    /**
     * The next number of a series, inside the caller's transaction. The counter row stays locked
     * until commit; a missing one is seeded from the highest number already stored.
     */
    private static function nextSeq(string $type, int $year): int
    {
        $kv  = new KeyValue();
        $key = $type . ':' . $year;
        $seq = $kv->increment(self::COUNTER_GROUP, $key);
        if ($seq === 1) {
            // A new row, or a lost one: continue after the series' highest stored number.
            $top = (int) Db::scalar(
                "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(s_number, '-', -1) AS UNSIGNED)), 0) FROM " . self::tableName()
                . ' WHERE s_type = ? AND dt_date >= ? AND dt_date < ?',
                array($type, $year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00')
            );
            if ($top > 0) {
                $seq = $kv->increment(self::COUNTER_GROUP, $key, $top);
            }
        }

        return $seq;
    }
}
