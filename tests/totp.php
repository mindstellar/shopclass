<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the admin two-step codes: RFC 6238 test vectors, the one-step window either side,
 * a code refused the second time, and backup code hashing.
 *
 * Usage: php tests/totp.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\security\Totp;

// RFC 6238 appendix B, SHA-1: the 20-byte secret "12345678901234567890", last 6 digits.
$rfc = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
foreach (array(59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037') as $time => $code) {
    pin("RFC 6238 at $time", $code, Totp::code($rfc, Totp::step($time)));
}

$secret = Totp::newSecret();
pin('a new secret is 32 base32 characters', 1, preg_match('/^[A-Z2-7]{32}$/', $secret));
check('two new secrets differ', $secret !== Totp::newSecret());

$now = Totp::step(1700000000);
pin('the current code passes', $now, Totp::verify($secret, Totp::code($secret, $now), 0, $now));
pin('the one before passes', $now - 1, Totp::verify($secret, Totp::code($secret, $now - 1), 0, $now));
pin('the one after passes', $now + 1, Totp::verify($secret, Totp::code($secret, $now + 1), 0, $now));
pin('two steps old fails', null, Totp::verify($secret, Totp::code($secret, $now - 2), 0, $now));
pin('a used code fails the second time', null, Totp::verify($secret, Totp::code($secret, $now), $now, $now));
pin('spaces in a typed code are ignored', $now, Totp::verify($secret, implode(' ', str_split(Totp::code($secret, $now), 3)), 0, $now));
pin('letters are refused', null, Totp::verify($secret, 'abcdef', 0, $now));
pin('a short code is refused', null, Totp::verify($secret, '12345', 0, $now));

$codes = Totp::newBackupCodes();
pin('8 backup codes', 8, count(array_unique($codes)));
pin('each is 10 base32 characters', 8, count(preg_grep('/^[A-Z2-7]{10}$/', $codes)));
pin('a typed backup code reads as issued', $codes[0], Totp::normaliseBackupCode(' ' . strtolower(substr($codes[0], 0, 5) . '-' . substr($codes[0], 5)) . ' '));

pin(
    'the setup URI',
    'otpauth://totp/My%20Site:admin?secret=' . $rfc . '&issuer=My%20Site&algorithm=SHA1&digits=6&period=30',
    Totp::uri($rfc, 'admin', 'My Site')
);

exit(harness_result());
