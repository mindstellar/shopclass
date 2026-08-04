<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\security;

use LoginAttempt;
use Params;

/**
 * Per-address rate limit for public mail-sending forms (share a listing, contact
 * the seller). Those forms hand a request to the mailer, so without a ceiling one
 * source can drive the site's own address as a spam relay; this bounds how many
 * it may send in a rolling window.
 *
 * It records events in the same {@see LoginAttempt} ledger the sign-in limiter and
 * the item-post flood wait already use, under a distinct context string, keyed on
 * the source address alone — an account counter would not help here, since the
 * abuse is one sender reaching many recipients rather than many senders reaching
 * one account.
 *
 * Two deliberate choices, both shared with {@see LoginThrottle}:
 *
 *   REMOTE_ADDR only. A forwarded-for header is written by the client, so trusting
 *   it would let an attacker reset the counter on every request by inventing a new
 *   address. An install behind a proxy must have the proxy set REMOTE_ADDR (the
 *   image's OSC_REAL_IP_HEADER does exactly this).
 *
 *   Fail open. The ledger arrives with an upgrade and the files are in place before
 *   the upgrade runs, so between the two the table may not exist; a missing or
 *   unwell ledger must not take a legitimate feature down with it. Losing the limit
 *   leaves the form as exposed as it was before — survivable — while failing closed
 *   would break it for everyone.
 */
class ActionThrottle
{
    /**
     * Has this source already used its allowance of $max events for $context in
     * the trailing $windowSeconds? Checked before the action runs.
     *
     * @param string $context       ledger context, e.g. 'send_friend'
     * @param int    $max           events permitted in the window; <= 0 disables the limit
     * @param int    $windowSeconds length of the rolling window
     *
     * @return bool true when the action should be refused
     */
    public static function exceeded($context, $max, $windowSeconds)
    {
        if ($max <= 0) {
            return false;
        }

        $ip = self::ip();
        if ($ip === '') {
            return false;
        }

        try {
            $since = date('Y-m-d H:i:s', time() - (int)$windowSeconds);

            return LoginAttempt::newInstance()->countByIpContext($context, $ip, $since) >= $max;
        } catch (\Throwable $e) {
            self::unavailable($e);

            return false;
        }
    }

    /**
     * Record one event for the current source, so it counts toward the window.
     * Call after the action has been accepted.
     *
     * @param string $context ledger context, matching the one passed to exceeded()
     *
     * @return void
     */
    public static function record($context)
    {
        $ip = self::ip();
        if ($ip === '') {
            return;
        }

        try {
            // Account is empty: these limits key on the address, not a name.
            LoginAttempt::newInstance()->record($context, '', $ip, date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            self::unavailable($e);
        }
    }

    /**
     * The address the request came from. REMOTE_ADDR only; see the class comment.
     *
     * @return string
     */
    private static function ip()
    {
        return (string)Params::getServerParam('REMOTE_ADDR');
    }

    /**
     * The ledger could not be reached, so the limiter stands aside. Logged once
     * per request so a run of attempts against a broken ledger cannot fill the log.
     *
     * @param \Throwable $e
     *
     * @return void
     */
    private static function unavailable(\Throwable $e)
    {
        static $logged = false;

        if (!$logged) {
            $logged = true;
            error_log('ActionThrottle unavailable, allowing the action: ' . $e->getMessage());
        }
    }
}
