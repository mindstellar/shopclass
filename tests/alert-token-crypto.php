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

harness_section('a token from before 6.2.0 is refused');
// Mint one exactly the way the old code did: 32 random chars prepended to the
// payload, AES-256-CTR, IV in front, key hashed the way Cryptor hashed it.
$legacyPlain = str_repeat('a', 32) . $payload;
$legacyIv    = random_bytes(16);
$legacyKey   = openssl_digest(hash('sha256', osc_get_alert_private_key(), true), 'sha256', true);
$legacyToken = $legacyIv . openssl_encrypt($legacyPlain, 'aes-256-ctr', $legacyKey, OPENSSL_RAW_DATA, $legacyIv);

pin('legacy token is refused', '', osc_decrypt_alert($legacyToken));

// CTR is malleable: with the plaintext known, XOR swaps a same-length part for chosen text.
$known  = '"no_catched_conditions":["1=1"]';
$chosen = '"no_catched_conditions":["1=2"]';
$plain  = str_repeat('a', 32) . '{' . $known . '}';
$iv     = random_bytes(16);
$forged = $iv . openssl_encrypt($plain, 'aes-256-ctr', $legacyKey, OPENSSL_RAW_DATA, $iv);
$at     = 16 + 32 + 1;
$forged = substr($forged, 0, $at) . (substr($forged, $at, strlen($known)) ^ $known ^ $chosen)
    . substr($forged, $at + strlen($known));
pin('the edit really changes a legacy token', '{' . $chosen . '}', osc_decrypt_alert_legacy($forged));
pin('so an edited legacy token is refused', '', osc_decrypt_alert($forged));

harness_section('a v2 envelope survives the round trip');
// The subscribe endpoint only accepts a token whose plaintext is a valid v2 envelope.
require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
if (!function_exists('osc_apply_filter')) {
    function osc_apply_filter($hook, $content = '', ...$args)
    {
        return $content;
    }
}
$v2      = '{"v":2,"params":{"meta":{"4":"red"},"sCategory":[12,13],"sPattern":"road bike é","sRegion":["Kent"]}}';
$v2Token = osc_encrypt_alert($v2);
pin('decrypts to the envelope', $v2, osc_decrypt_alert($v2Token));
pin(
    'which validates to its params',
    array('meta' => array(4 => 'red'), 'sCategory' => array(12, 13), 'sPattern' => 'road bike é', 'sRegion' => array('Kent')),
    \mindstellar\search\AlertEnvelope::validate(osc_decrypt_alert($v2Token))
);
pin('fromToken(): a v2 token gives the envelope', $v2, \mindstellar\search\AlertEnvelope::fromToken(base64_encode($v2Token)));
pin('fromToken(): a v1 payload is refused', null, \mindstellar\search\AlertEnvelope::fromToken(base64_encode($token)));
pin('fromToken(): garbage is refused', null, \mindstellar\search\AlertEnvelope::fromToken('not a token'));
pin('fromToken(): an empty token is refused', null, \mindstellar\search\AlertEnvelope::fromToken(''));
pin('a v1 payload decrypts but does not validate', null, \mindstellar\search\AlertEnvelope::validate(osc_decrypt_alert($token)));

harness_section('a wrong key never yields the payload');
$GLOBALS['__alert_key'] = str_repeat('z', 40);
pin('new token under wrong key', '', osc_decrypt_alert($token));
pin('legacy token under wrong key', false, osc_decrypt_alert($legacyToken) === $payload);

exit(harness_result());
