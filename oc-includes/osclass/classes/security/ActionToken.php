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

/**
 * One-way fingerprints for single-use, email-delivered action codes (admin password-reset,
 * user account-activation).
 *
 * The plaintext code travels only inside the emailed link; the database stores just this
 * fingerprint, so a leaked row cannot be replayed as a live code. Callers verify by
 * re-fingerprinting the submitted code and comparing (an equality lookup on the stored value).
 *
 * HMAC-SHA256 under the install signing key ({@see SigningKey}), hex, truncated to 40 chars so
 * it fits the legacy `s_secret VARCHAR(40)` column with no schema change. 40 hex chars = 160
 * bits, far beyond collision/preimage concern for a lookup token, and keying it means a stolen
 * database alone (without the config-level signing key) cannot even verify guesses offline.
 */
class ActionToken
{
    /**
     * Fingerprint length, capped to the legacy s_secret column width.
     */
    private const LENGTH = 40;

    /**
     * @param string $code plaintext code from the emailed link
     *
     * @return string storable fingerprint (40 hex chars)
     */
    public static function hash($code)
    {
        return substr(hash_hmac('sha256', (string)$code, SigningKey::get()), 0, self::LENGTH);
    }
}
