<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the search-alert token format.
 *
 * Two things have to hold at once: a token minted now round-trips and refuses to be
 * tampered with, and a token minted by the previous release -- unauthenticated
 * AES-256-CTR -- still reads, so an alert link already sitting in a rendered page
 * survives the upgrade.  Usage: php tests/alert-token-crypto.php
 */

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

// The helpers reach for the install's persistent alert key and the preference
// store behind it. Stub that surface so the crypto can be exercised standalone.
$GLOBALS['__alert_key'] = str_repeat('k', 40);
function osc_get_alert_private_key()
{
    return $GLOBALS['__alert_key'];
}
function osc_set_alert_private_key()
{
}
function osc_set_alert_public_key()
{
}

// Pull in just the token functions, not the whole helper file (which needs a booted
// application). Extracting them by name keeps the test to the unit under test.
$src = file_get_contents(__DIR__ . '/../oc-includes/osclass/helpers/hSecurity.php');
foreach (array('osc_encrypt_alert', 'osc_decrypt_alert', 'osc_decrypt_alert_legacy', 'osc_alert_cipher_key') as $fn) {
    if (preg_match('/\nfunction ' . $fn . '\(.*?\n\}\n/s', $src, $m)) {
        eval($m[0]);
    }
}

$payload = '{"sCategory":["12","13"],"sPattern":"road bike","sRegion":"Kent"}';

harness_section('round trip');
$token = osc_encrypt_alert($payload);
pin('decrypts to the original payload', $payload, osc_decrypt_alert($token));
pin('ciphertext is not the plaintext', false, strpos($token, 'road bike') !== false);

// GCM is randomised per call, so the same payload must not produce the same token --
// otherwise identical searches are linkable across users.
pin('two encryptions differ', false, osc_encrypt_alert($payload) === osc_encrypt_alert($payload));

harness_section('rejects tampering');
// The whole point of the change: flipping a ciphertext bit under CTR produced a
// controlled edit to the plaintext. Under GCM the tag fails and nothing is returned.
$flipped    = $token;
$flipped[28] = ($flipped[28] === "\x00") ? "\x01" : "\x00";
pin('bit-flipped body rejected', '', osc_decrypt_alert($flipped));

$badTag     = $token;
$badTag[13] = ($badTag[13] === "\x00") ? "\x01" : "\x00";
pin('tampered tag rejected', '', osc_decrypt_alert($badTag));

pin('truncated token rejected', '', osc_decrypt_alert(substr($token, 0, 20)));
pin('empty token rejected', '', osc_decrypt_alert(''));
pin('random bytes rejected', '', osc_decrypt_alert(random_bytes(80)));

harness_section('a token from the previous release still reads');
// Mint one exactly the way the old code did: 32 random chars prepended to the
// payload, AES-256-CTR, IV in front, key hashed the way Cryptor hashed it.
$legacyPlain = str_repeat('a', 32) . $payload;
$legacyIv    = random_bytes(16);
$legacyKey   = openssl_digest(hash('sha256', osc_get_alert_private_key(), true), 'sha256', true);
$legacyToken = $legacyIv . openssl_encrypt($legacyPlain, 'aes-256-ctr', $legacyKey, OPENSSL_RAW_DATA, $legacyIv);

pin('legacy token decrypts', $payload, osc_decrypt_alert($legacyToken));

harness_section('a wrong key never yields the payload');
$GLOBALS['__alert_key'] = str_repeat('z', 40);
pin('new token under wrong key', '', osc_decrypt_alert($token));
pin('legacy token under wrong key', false, osc_decrypt_alert($legacyToken) === $payload);

exit(harness_result());
