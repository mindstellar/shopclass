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

/**
 * Per-address rate limit for public forms (share a listing, contact the seller, post): at most
 * N events per rolling window per address, counted with {@see RateLimit::addRolling()}.
 *
 * It keys on REMOTE_ADDR alone, per {@see AddressBucket} so an IPv6 client is one /64; a
 * forwarded-for header is written by the client and would let it reset the count. Like
 * RateLimit it fails open, so a counter that cannot be reached never takes a form down.
 */
class ActionThrottle
{
    /** Seconds an event is kept, so the longest window counted is a day. */
    private const KEEP = 86400;

    /** Hourly limit per address for each public action; the admin can change them under Spam and bots. */
    public const DEFAULT_LIMITS = array(
        'comment_post'    => 20,
        'ajax_upload'     => 100,
        'form_submit'     => 10,
        'site_contact'    => 5,
        'item_contact'    => 15,
        'user_contact'    => 15,
        'send_friend'     => 5,
        'alert_subscribe' => 10,
    );

    /**
     * The limit for $context: the stored preference `throttle_<context>`, else $max, else
     * the built-in default. The action_throttle_limit filter may still change it, e.g.
     * array('max' => 5, 'window' => 3600). 0 means no limit.
     *
     * @param string   $context
     * @param int|null $max     fallback when no preference is stored
     * @param int      $window  seconds
     *
     * @return bool true when the action should be refused
     */
    public static function exceededFor(string $context, ?int $max = null, int $window = 3600): bool
    {
        [$max, $window] = self::limitFor($context, $max, $window);

        return self::exceeded($context, $max, $window);
    }

    /**
     * The limit for $context: the stored setting, else $max, else the default, then the
     * action_throttle_limit filter. A max of 0 means no limit.
     *
     * @return array{0:int,1:int} max and window in seconds
     */
    public static function limitFor(string $context, ?int $max = null, int $window = 3600): array
    {
        $stored = osc_get_preference('throttle_' . $context);
        if (is_numeric($stored)) {
            $max = max(0, (int) $stored);
        } elseif ($max === null) {
            $max = self::DEFAULT_LIMITS[$context] ?? 0;
        }

        $limit = (array) osc_apply_filter('action_throttle_limit', array('max' => $max, 'window' => $window), $context);

        return array((int) ($limit['max'] ?? $max), max(1, (int) ($limit['window'] ?? $window)));
    }

    /**
     * Has this source already used its allowance of $max events for $context in
     * the trailing $windowSeconds? Checked before the action runs.
     *
     * @param string $context       e.g. 'send_friend'
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
        $ip = AddressBucket::ofRequest();
        if ($ip === '') {
            return false;
        }

        return (RateLimit::countRolling((string) $context, $ip, (int) $windowSeconds) ?? 0) >= $max;
    }

    /**
     * Record one event for the current source, so it counts toward the window.
     * Call after the action has been accepted.
     *
     * @param string $context matching the one passed to exceeded()
     *
     * @return void
     */
    public static function record($context)
    {
        $ip = AddressBucket::ofRequest();
        if ($ip !== '') {
            RateLimit::addRolling((string) $context, $ip, self::KEEP);
        }
    }

}
