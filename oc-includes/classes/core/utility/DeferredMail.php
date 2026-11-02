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

namespace mindstellar\utility;

use mindstellar\database\Db;

/**
 * E-mails held back while a database write is open, so a write that is rolled back sends
 * none. osc_sendMail() hands each e-mail here first.
 */
final class DeferredMail
{
    /** @var array<int,array<string,mixed>>|null the held e-mails' params; null when not holding */
    private static ?array $held = null;

    private function __construct()
    {
    }

    /**
     * Run $fn with e-mails held: they are sent once it returns and dropped if it throws.
     * A hold inside another leaves the sending to the outer one, and one inside an open
     * database transaction sends after that commits. A send that throws is logged, never
     * thrown: the write has committed, so the caller must still see success.
     *
     * @param callable(): mixed                         $fn
     * @param (callable(array<string,mixed>): mixed)|null $send sends one e-mail; osc_sendMail() by default
     *
     * @return mixed what $fn returns
     */
    public static function during(callable $fn, ?callable $send = null): mixed
    {
        if (self::$held !== null) {
            return $fn();
        }
        self::$held = [];
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            self::$held = null;

            throw $e;
        }
        $mails      = self::$held;
        self::$held = null;
        // @phpstan-ignore notIdentical.alwaysFalse (the callback queues e-mails while it runs)
        if ($mails !== []) {
            Db::afterCommit(static fn () => self::send($mails, $send ?? 'osc_sendMail'));
        }

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>>        $mails
     * @param callable(array<string,mixed>): mixed $send
     */
    private static function send(array $mails, callable $send): void
    {
        foreach ($mails as $params) {
            try {
                $send($params);
            } catch (\Throwable $e) {
                error_log('DeferredMail: a held e-mail was not sent: ' . $e->getMessage());
            }
        }
    }

    /**
     * Run $fn in one database transaction with e-mails held until it commits.
     *
     * @param callable(): mixed                         $fn
     * @param (callable(array<string,mixed>): mixed)|null $send as for during()
     *
     * @return mixed what $fn returns
     */
    public static function transaction(callable $fn, ?callable $send = null): mixed
    {
        return self::during(static fn (): mixed => Db::transaction($fn), $send);
    }

    /**
     * Keep an e-mail for later while a hold or a database transaction is open.
     *
     * @param array<string,mixed> $params osc_sendMail()'s
     *
     * @return bool true when it was held, false when it should be sent now
     */
    public static function hold(array $params): bool
    {
        if (self::$held === null) {
            if (!Db::inTransaction()) {
                return false;
            }
            // Sent when the transaction commits, dropped when it rolls back.
            Db::afterCommit(static fn () => self::send([$params], 'osc_sendMail'));

            return true;
        }
        self::$held[] = $params;

        return true;
    }
}
