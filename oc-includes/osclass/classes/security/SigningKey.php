<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\security;

use Preference;

/**
 * Install-wide HMAC signing key, resolved once per request and shared by every stateless
 * signed token (CSRF tokens, remember-me cookies). Prefers an OSC_CSRF_SECRET config constant
 * so the key can live outside the database; otherwise a persisted csrf_secret preference,
 * generated once on first use so existing installs need no migration. Rotating the key
 * invalidates every outstanding token at once — a one-time re-issue, no data loss.
 */
class SigningKey
{
    /**
     * Resolved key, cached for the lifetime of the request.
     * @var string|null
     */
    private static $key;

    /**
     * @return string
     */
    public static function get()
    {
        if (self::$key !== null) {
            return self::$key;
        }
        if (defined('OSC_CSRF_SECRET') && OSC_CSRF_SECRET !== '') {
            return self::$key = OSC_CSRF_SECRET;
        }
        $secret = Preference::newInstance()->get('csrf_secret');
        if ($secret === '' || $secret === null) {
            $secret = bin2hex(random_bytes(32));
            // Prime the in-memory cache so this same request signs and verifies consistently;
            // replace() only writes the row, it does not refresh the loaded preferences.
            Preference::newInstance()->set('csrf_secret', $secret);
            osc_set_preference('csrf_secret', $secret, 'osclass', 'STRING');
        }

        return self::$key = $secret;
    }
}
