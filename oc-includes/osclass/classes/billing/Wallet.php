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

use InvalidArgumentException;
use mindstellar\database\DbException;

/**
 * The credit wallet: a balance per user, backed by an append-only ledger.
 *
 * Credits are the only unit core knows how to spend. A gateway plugin mints them by
 * settling an order and never learns what they buy; core spends them on entitlements
 * and never learns where they came from. That split is what keeps payment code out of
 * core and keeps core's features testable with no gateway installed -- an admin
 * granting credits by hand exercises exactly the same path a real purchase does.
 *
 * Two invariants hold everything together:
 *
 * 1. The ledger is append-only. Nothing here updates or deletes a ledger row; a refund
 *    is a new negative row. t_billing_wallet.i_balance is a cache of the running sum,
 *    and the row a write locks.
 *
 * 2. Every mutation is idempotent when given a key. Gateways retry webhooks, users
 *    double-click, and networks replay. A repeated call with the same idempotency key
 *    is a no-op that reports success, enforced by a unique index rather than by a
 *    check the caller might skip.
 *
 * @package mindstellar\billing
 */
final class Wallet
{
    /** MySQL's duplicate-entry error number. */
    private const ERR_DUPLICATE = 1062;

    public const REASON_PURCHASE = 'purchase';
    public const REASON_SPEND    = 'spend';
    public const REASON_REFUND   = 'refund';
    public const REASON_GRANT    = 'grant';
    public const REASON_REVOKE   = 'revoke';

    /**
     * Current balance in credits. A user who has never transacted has no wallet row
     * and a balance of zero; that is not an error and does not create a row.
     */
    public static function balance(int $userId): int
    {
        $value = osc_db_scalar(
            'SELECT i_balance FROM ' . self::wallet() . ' WHERE fk_i_user_id = ?',
            array($userId)
        );

        return $value === null ? 0 : (int) $value;
    }

    /**
     * Add credits.
     *
     * @param int         $userId
     * @param int         $amount         Credits to add; must be positive
     * @param string      $reason         One of the REASON_* constants
     * @param string|null $idempotencyKey Stable key for this logical event, e.g.
     *                                    'order:42'. Repeat calls with the same key
     *                                    apply once.
     * @param string|null $refType        'order' | 'item' | 'user'
     * @param int|null    $refId
     *
     * @return bool true once the credit is in effect, whether this call applied it or
     *              an earlier one with the same key already did
     * @throws InvalidArgumentException on a non-positive amount
     * @throws DbException on any failure other than a duplicate key
     */
    public static function credit(
        int $userId,
        int $amount,
        string $reason = self::REASON_PURCHASE,
        ?string $idempotencyKey = null,
        ?string $refType = null,
        ?int $refId = null
    ): bool {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Wallet::credit expects a positive amount');
        }

        return self::apply($userId, $amount, $reason, $idempotencyKey, $refType, $refId);
    }

    /**
     * Spend credits, refusing to go below zero.
     *
     * The balance check and the deduction are one conditional UPDATE, not a read
     * followed by a write. Two concurrent requests spending the last credit therefore
     * cannot both succeed: the second matches no row and is told it has insufficient
     * funds. Doing this as SELECT-then-UPDATE would let both through under any
     * isolation level short of serialisable.
     *
     * @return bool false when the balance is insufficient -- a normal outcome the
     *              caller must handle, not an exception
     * @throws InvalidArgumentException on a non-positive amount
     */
    public static function debit(
        int $userId,
        int $amount,
        string $reason = self::REASON_SPEND,
        ?string $idempotencyKey = null,
        ?string $refType = null,
        ?int $refId = null
    ): bool {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Wallet::debit expects a positive amount');
        }

        return self::apply($userId, -$amount, $reason, $idempotencyKey, $refType, $refId);
    }

    /**
     * Take credits back, even when that drives the balance negative.
     *
     * This is the chargeback path, and it is deliberately not debit(). By the time a
     * provider reverses a payment the user has usually already spent what it bought, so
     * a reversal that refused to overdraw would simply not happen and the site would
     * have given away the goods. A negative balance is the honest record of that, and
     * it blocks further spending until it is settled.
     *
     * @throws InvalidArgumentException on a non-positive amount
     */
    public static function reverse(
        int $userId,
        int $amount,
        string $reason = self::REASON_REFUND,
        ?string $idempotencyKey = null,
        ?string $refType = null,
        ?int $refId = null
    ): bool {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Wallet::reverse expects a positive amount');
        }

        return self::apply($userId, -$amount, $reason, $idempotencyKey, $refType, $refId, true);
    }

    /**
     * Ledger rows for a user, newest first. This is what an admin reads when a user
     * disputes a balance, so it returns the raw rows rather than a summary.
     *
     * @return array<int,array>
     */
    public static function history(int $userId, int $limit = 50, int $offset = 0): array
    {
        return osc_db_table(self::ledger())
            ->where('fk_i_user_id', $userId)
            ->orderBy('pk_i_id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    public static function historyCount(int $userId): int
    {
        return osc_db_table(self::ledger())->where('fk_i_user_id', $userId)->count();
    }

    /**
     * Every wallet that has ever transacted, with the account it belongs to.
     *
     * Inner join, so a wallet whose user has been deleted does not appear as a nameless
     * row -- the cascade removes it anyway. Ordered by balance so the accounts holding
     * the most unspent credit, which is the site's outstanding liability, are first.
     *
     * @return array<int,array>
     */
    public static function balances(int $limit = 25, int $offset = 0): array
    {
        return osc_db_select(
            'SELECT w.fk_i_user_id, w.i_balance, w.dt_mod_date, u.s_username, u.s_name, u.s_email'
            . ' FROM ' . self::wallet() . ' w'
            . ' INNER JOIN ' . DB_TABLE_PREFIX . 't_user u ON u.pk_i_id = w.fk_i_user_id'
            . ' ORDER BY w.i_balance DESC, w.fk_i_user_id ASC'
            . ' LIMIT ? OFFSET ?',
            array($limit, $offset)
        );
    }

    public static function balanceCount(): int
    {
        return (int) osc_db_scalar(
            'SELECT COUNT(*) FROM ' . self::wallet() . ' w'
            . ' INNER JOIN ' . DB_TABLE_PREFIX . 't_user u ON u.pk_i_id = w.fk_i_user_id'
        );
    }

    /**
     * Total credit outstanding across every wallet -- what the site owes its users in
     * things they have paid for and not yet spent.
     */
    public static function totalOutstanding(): int
    {
        return (int) osc_db_scalar('SELECT COALESCE(SUM(i_balance), 0) FROM ' . self::wallet());
    }

    /**
     * Move the balance by $delta (signed) and append the matching ledger row, atomically.
     *
     * @param int  $delta         positive to credit, negative to debit
     * @param bool $allowNegative skip the overdraw guard (reversals only)
     *
     * @return bool whether the balance now reflects this event
     */
    private static function apply(
        int $userId,
        int $delta,
        string $reason,
        ?string $idempotencyKey,
        ?string $refType,
        ?int $refId,
        bool $allowNegative = false
    ): bool {
        $applied = false;
        $settled = false;

        try {
            osc_db_transaction(static function () use (
                $userId,
                $delta,
                $reason,
                $idempotencyKey,
                $refType,
                $refId,
                $allowNegative,
                &$applied,
                &$settled
            ): void {
                // Fast path for the common replay. The unique index below is the real
                // guard -- this only avoids the write when the answer is already known.
                if ($idempotencyKey !== null && self::keyExists($idempotencyKey)) {
                    $settled = true;

                    return;
                }

                self::ensureWallet($userId);

                $sql    = 'UPDATE ' . self::wallet() . ' SET i_balance = i_balance + ?, dt_mod_date = ?'
                    . ' WHERE fk_i_user_id = ?';
                $params = array($delta, date('Y-m-d H:i:s'), $userId);

                if ($delta < 0 && !$allowNegative) {
                    // Refuse to overdraw, in the same statement that deducts.
                    $sql     .= ' AND i_balance >= ?';
                    $params[] = -$delta;
                }

                if (osc_db_execute($sql, $params) !== 1) {
                    return; // insufficient funds; $applied stays false
                }

                osc_db_execute(
                    'INSERT INTO ' . self::ledger()
                    . ' (fk_i_user_id, i_amount, i_balance_after, s_reason, s_ref_type, i_ref_id,'
                    . ' s_idempotency_key, dt_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    array(
                        $userId,
                        $delta,
                        self::balance($userId),
                        $reason,
                        $refType,
                        $refId,
                        $idempotencyKey,
                        date('Y-m-d H:i:s'),
                    )
                );

                $applied = true;
                $settled = true;
            });
        } catch (DbException $e) {
            if ((int) $e->getCode() !== self::ERR_DUPLICATE) {
                throw $e;
            }

            // A concurrent request with the same key committed first. The transaction
            // rolled back, so the balance moved exactly once -- by the other request.
            return true;
        }

        if ($applied) {
            osc_run_hook('billing_credits_changed', $userId, $delta, $reason);
        }

        return $settled;
    }

    /**
     * Create the wallet row if the user has never transacted. INSERT IGNORE rather
     * than a check-then-insert, so two concurrent first-time writes cannot race.
     */
    private static function ensureWallet(int $userId): void
    {
        osc_db_execute(
            'INSERT IGNORE INTO ' . self::wallet() . ' (fk_i_user_id, i_balance, dt_mod_date) VALUES (?, 0, ?)',
            array($userId, date('Y-m-d H:i:s'))
        );
    }

    private static function keyExists(string $idempotencyKey): bool
    {
        return osc_db_table(self::ledger())->where('s_idempotency_key', $idempotencyKey)->count() > 0;
    }

    private static function wallet(): string
    {
        return DB_TABLE_PREFIX . 't_billing_wallet';
    }

    private static function ledger(): string
    {
        return DB_TABLE_PREFIX . 't_billing_ledger';
    }
}

/* file end: ./oc-includes/osclass/classes/billing/Wallet.php */
