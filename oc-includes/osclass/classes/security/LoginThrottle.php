<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\security;

use LoginAttempt;
use Params;

/**
 * Rate limit for the sign-in and password-reset forms.
 *
 * Nothing bounded password guessing: no counter, no delay, no lockout, so the
 * only cost of an attempt was the password hash. Lowering the bcrypt work
 * factor from 15 to 12 made a correct sign-in eight times quicker, and a guess
 * along with it, which is what makes a limiter the piece that has to bound the
 * guess rate rather than the hash cost.
 *
 * Two counters over one rolling window, both fed by {@see LoginAttempt}:
 *
 *   by address  every failure from one IP, whichever account it aimed at.
 *               Over the limit blocks that address outright.
 *   by account  every failure against one submitted name, from wherever.
 *               Over the limit blocks, unless the caller solved a captcha.
 *
 * The account counter is what catches guessing spread across many addresses,
 * which the address counter cannot see. It also means an attacker can hold a
 * named account shut for the length of the window, so the window is short and
 * lifts on its own.
 *
 * That is why a solved captcha excuses the account limit: the guess rate is
 * bounded already, and blocking as well would only hand an attacker a way to
 * shut a named account without getting past the captcha themselves. With
 * nothing bounding the rate the block stands.
 *
 * The caller states whether one was solved -- evaluate() does not infer it.
 * Inferring it from "a provider is configured" is what let the recovery form
 * lose its per-account limit for a captcha it had not rendered: a global setting
 * cannot answer a per-request question. The parameter defaults to false so a
 * call site that says nothing keeps the limit.
 *
 * Failures are recorded against the name as submitted whether or not it matches
 * anything. That keeps the limiter from becoming the account oracle that was
 * just closed: a name nobody holds accumulates and blocks exactly like a real
 * one, so the response never distinguishes them.
 *
 * A correct password does not lift a block. Checking before the password is
 * verified is the entire point -- otherwise the limiter would still spend a
 * bcrypt hash per attempt and remain a way to burn CPU.
 */
class LoginThrottle
{
    /** Attempt may proceed. */
    public const OK = 'ok';

    /** Attempt is refused for now. */
    public const BLOCKED = 'blocked';

    /**
     * What this limiter counts. The same ledger also holds contact-form and listing-post
     * events for other limits; those must neither block a sign-in nor be cleared by one.
     */
    public const CONTEXTS = array('admin', 'web', 'admin-recover', 'web-recover', 'restore_reauth');

    /**
     * Decide what to do with an attempt, before any password is checked.
     *
     * Call it after the form's own captcha check, and pass whether that check
     * actually cleared a captcha on this request.
     *
     * @param string $context       'web' or 'admin'
     * @param string $account       identifier as submitted
     * @param bool   $captchaSolved true only when this request presented a
     *                              captcha and it verified. Excuses the
     *                              per-account limit; see the class comment.
     *
     * @return array{status:string,retry_after:int} retry_after is seconds, 0 when not blocked
     */
    public static function evaluate($context, $account, $captchaSolved = false)
    {
        $pass = array('status' => self::OK, 'retry_after' => 0);

        if (!osc_login_throttle_enabled()) {
            return $pass;
        }

        $window  = self::windowSeconds();
        $since   = self::since($window);
        $ip      = self::ip();
        $account = self::normalise($account);

        try {
            $model = LoginAttempt::newInstance();

            $byIp = $ip !== '' ? self::ipWindow($ip, $since) : array('n' => 0, 'oldest' => null);
            if ($byIp['n'] >= osc_login_throttle_max_ip()) {
                return array(
                    'status'      => self::BLOCKED,
                    'retry_after' => self::retryAfter($byIp['oldest'], $window),
                );
            }

            if ($account !== ''
                && !$captchaSolved
                && $model->countByAccount($context, $account, $since) >= osc_login_throttle_max_account()
            ) {
                return array(
                    'status'      => self::BLOCKED,
                    'retry_after' => self::retryAfter($model->oldestByAccount($context, $account, $since), $window),
                );
            }
        } catch (\Throwable $e) {
            self::unavailable($e);
        }

        return $pass;
    }

    /**
     * Record one rejected attempt.
     *
     * @param string $context
     * @param string $account identifier as submitted
     *
     * @return void
     */
    public static function recordFailure($context, $account)
    {
        if (!osc_login_throttle_enabled()) {
            return;
        }

        try {
            LoginAttempt::newInstance()->record(
                $context,
                self::normalise($account),
                self::ip(),
                date('Y-m-d H:i:s')
            );
        } catch (\Throwable $e) {
            self::unavailable($e);
        }
    }

    /**
     * Forget an account's failures, and those of the address that just proved
     * it holds the password. Called after a sign-in succeeds.
     *
     * @param string $context
     * @param string $account identifier as submitted
     *
     * @return void
     */
    public static function clear($context, $account)
    {
        if (!osc_login_throttle_enabled()) {
            return;
        }

        try {
            $model   = LoginAttempt::newInstance();
            $account = self::normalise($account);
            if ($account !== '') {
                $model->clearAccount($context, $account);
            }
            $ip = self::ip();
            if ($ip !== '') {
                self::clearSignInIp($ip);
            }
        } catch (\Throwable $e) {
            self::unavailable($e);
        }
    }

    /**
     * Who has failed to sign in within the window, for the admin: addresses and accounts
     * in one list, blocked first, then by most failures.
     *
     * @param int $limit rows read per kind
     *
     * @return array<int,array<string,mixed>> each with kind (ip|account), ip or context and
     *         account, failures, blocked and until
     */
    public static function activity($limit = 1000)
    {
        $window = self::windowSeconds();
        $since  = self::since($window);
        $table  = DB_TABLE_PREFIX . 't_login_attempt';
        $out    = array();

        try {
            $rows = osc_db_table($table)
                ->select('s_ip')
                ->selectRaw('COUNT(*) AS n, MIN(dt_date) AS oldest')
                ->where('dt_date', '>', $since)
                ->whereIn('s_context', self::CONTEXTS)
                ->where('s_ip', '!=', '')
                ->groupBy('s_ip')
                ->orderBy('n', 'DESC')
                ->limit((int)$limit)
                ->get();
            foreach ($rows as $row) {
                $out[] = self::activityRow($row, osc_login_throttle_max_ip(), $window) + array(
                    'kind' => 'ip',
                    'ip'   => (string)$row['s_ip'],
                );
            }

            $rows = osc_db_table($table)
                ->select('s_context', 's_account')
                ->selectRaw('COUNT(*) AS n, MIN(dt_date) AS oldest')
                ->where('dt_date', '>', $since)
                ->whereIn('s_context', self::CONTEXTS)
                ->where('s_account', '!=', '')
                ->groupBy('s_context', 's_account')
                ->orderBy('n', 'DESC')
                ->limit((int)$limit)
                ->get();
            foreach ($rows as $row) {
                // With a captcha, the account limit is off, so no account is blocked.
                $max   = osc_captcha_enabled() ? PHP_INT_MAX : osc_login_throttle_max_account();
                $out[] = self::activityRow($row, $max, $window) + array(
                    'kind'    => 'account',
                    'context' => (string)$row['s_context'],
                    'account' => (string)$row['s_account'],
                );
            }
        } catch (\Throwable $e) {
            self::unavailable($e);
        }

        usort($out, static fn ($a, $b) => array($b['blocked'], $b['failures']) <=> array($a['blocked'], $a['failures']));

        return $out;
    }

    /**
     * Let one address sign in again straight away.
     *
     * @param string $ip
     *
     * @return void
     */
    public static function unblockIp($ip)
    {
        try {
            self::clearSignInIp((string)$ip);
        } catch (\Throwable $e) {
            self::unavailable($e);
        }
    }

    /**
     * Sign-in failures from one address inside the window, and the oldest of them.
     *
     * @param string $ip
     * @param string $since
     *
     * @return array{n:int,oldest:?string}
     * @throws \mindstellar\database\DbException
     */
    private static function ipWindow($ip, $since)
    {
        $row = osc_db_table(DB_TABLE_PREFIX . 't_login_attempt')
            ->selectRaw('COUNT(*) AS n, MIN(dt_date) AS oldest')
            ->where('s_ip', (string)$ip)
            ->whereIn('s_context', self::CONTEXTS)
            ->where('dt_date', '>', $since)
            ->first();

        return array(
            'n'      => (int)($row['n'] ?? 0),
            'oldest' => isset($row['oldest']) ? (string)$row['oldest'] : null,
        );
    }

    /**
     * Forget one address's sign-in failures, and nothing else it did.
     *
     * @param string $ip
     *
     * @return void
     * @throws \mindstellar\database\DbException
     */
    private static function clearSignInIp($ip)
    {
        osc_db_table(DB_TABLE_PREFIX . 't_login_attempt')
            ->where('s_ip', (string)$ip)
            ->whereIn('s_context', self::CONTEXTS)
            ->delete();
    }

    /**
     * Let one account be tried again straight away.
     *
     * @param string $context
     * @param string $account the name exactly as activity() listed it
     *
     * @return void
     */
    public static function unblockAccount($context, $account)
    {
        try {
            LoginAttempt::newInstance()->clearAccount((string)$context, (string)$account);
        } catch (\Throwable $e) {
            self::unavailable($e);
        }
    }

    /**
     * @param array<string,mixed> $row    with n and oldest
     * @param int                 $max    the limit that applies
     * @param int                 $window seconds
     *
     * @return array{failures:int,blocked:bool,until:string}
     */
    private static function activityRow(array $row, $max, $window)
    {
        $failures = (int)$row['n'];

        return array(
            'failures' => $failures,
            'blocked'  => $failures >= $max,
            'until'    => date('Y-m-d H:i:s', time() + self::retryAfter((string)$row['oldest'], $window)),
        );
    }

    /**
     * Drop attempts past the retention window. Called from the daily cron.
     *
     * @return int rows removed
     */
    public static function prune()
    {
        $days = osc_login_attempt_retention_days();
        if ($days <= 0) {
            return 0;
        }

        try {
            return LoginAttempt::newInstance()->pruneBefore(
                date('Y-m-d H:i:s', time() - ($days * 86400))
            );
        } catch (\Throwable $e) {
            self::unavailable($e);

            return 0;
        }
    }

    /**
     * The ledger could not be reached, so the limiter stands aside.
     *
     * Failing open is the deliberate choice. t_login_attempt arrives with an
     * upgrade, and the files are in place before the upgrade is run -- so
     * between the two the table does not exist yet. Failing closed there would
     * refuse every sign-in including the administrator's, and the admin sign-in
     * is where the upgrade is started from: the site would have locked out the
     * one person able to fix it.
     *
     * The same reasoning covers a dropped table or a database that is briefly
     * unwell. Losing the limiter leaves the site as exposed as it was before it
     * existed, which is survivable; refusing every sign-in is not.
     *
     * Logged once per request, so a sustained run of attempts against a broken
     * ledger cannot fill the error log.
     *
     * @param \Throwable $e
     *
     * @return void
     */
    private static function unavailable(\Throwable $e)
    {
        FailOpen::log('LoginThrottle', 'the attempt', $e);
    }

    /**
     * One spelling of a submitted identifier, so that "Alice" and "alice" share
     * a counter and an attacker cannot multiply their allowance by changing
     * case or padding.
     *
     * @param string $account
     *
     * @return string
     */
    public static function normalise($account)
    {
        $account = trim((string)$account);

        return function_exists('mb_strtolower') ? mb_strtolower($account, 'UTF-8') : strtolower($account);
    }

    /**
     * How long a block has left, in seconds: a counted attempt has to age past
     * the window before the count can fall under the limit again.
     *
     * @param string|null $oldest 'Y-m-d H:i:s' of the earliest counted attempt
     * @param int         $window seconds
     *
     * @return int
     */
    private static function retryAfter($oldest, $window)
    {
        if ($oldest === null) {
            return $window;
        }

        $remaining = (strtotime($oldest) + $window) - time();

        return $remaining > 0 ? (int)$remaining : 0;
    }

    /**
     * Length of the rolling window, from the configured minutes.
     *
     * @return int seconds
     */
    private static function windowSeconds()
    {
        return osc_login_throttle_window() * 60;
    }

    /**
     * The timestamp the window opens at, for the counting queries.
     *
     * @param int $window seconds
     *
     * @return string 'Y-m-d H:i:s'
     */
    private static function since($window)
    {
        return date('Y-m-d H:i:s', time() - $window);
    }

    /**
     * The address the request came from.
     *
     * REMOTE_ADDR only. A forwarded-for header is written by the client, so
     * trusting it here would let an attacker reset their own counter on every
     * request by inventing a new one. An install behind a proxy needs the proxy
     * to set REMOTE_ADDR, which is what the ban rules already assume.
     *
     * @return string
     */
    private static function ip()
    {
        return (string)Params::getServerParam('REMOTE_ADDR');
    }
}
