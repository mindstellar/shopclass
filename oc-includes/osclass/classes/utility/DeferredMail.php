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
     * A hold inside another leaves the sending to the outer one. A send that throws is
     * logged, never thrown: the write has committed, so the caller must still see success.
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
        $send ??= 'osc_sendMail';
        foreach ($mails as $params) {
            try {
                $send($params);
            } catch (\Throwable $e) {
                error_log('DeferredMail: a held e-mail was not sent: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Run $fn in one database transaction with e-mails held until it commits.
     *
     * @param callable(): mixed $fn
     *
     * @return mixed what $fn returns
     */
    public static function transaction(callable $fn): mixed
    {
        return self::during(static fn (): mixed => osc_db_transaction($fn));
    }

    /**
     * Keep an e-mail for later while a hold is open.
     *
     * @param array<string,mixed> $params osc_sendMail()'s
     *
     * @return bool true when it was held, false when it should be sent now
     */
    public static function hold(array $params): bool
    {
        if (self::$held === null) {
            return false;
        }
        self::$held[] = $params;

        return true;
    }
}
