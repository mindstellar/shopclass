<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Helper Security
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

use mindstellar\Csrf;

/**
 * bcrypt work factor used by osc_hash_password().
 *
 * Each step up doubles the time to hash and to verify a password. Cost 12 is
 * roughly 175ms on typical 2026 hardware; cost 15 (the historic value) is about
 * 1.4s, which put a full second and a half of CPU into every login attempt,
 * successful or not. Raise it on hardware that can afford it, or lower it on
 * constrained shared hosting, by defining BCRYPT_COST in config.php.
 *
 * Existing hashes are unaffected: bcrypt stores its own cost, so a password is
 * always verified at the cost it was hashed with, and the login controllers
 * re-hash an account to the current value the next time its owner signs in.
 */
if (!defined('BCRYPT_COST')) {
    define('BCRYPT_COST', 12);
}

/**
 * Creates a random password.
 *
 * @param int password $length. Default to 8.
 *
 * @return string
 */
function osc_genRandomPassword($length = 8)
{
    $dict = array_merge(range('a', 'z'), range('0', '9'), range('A', 'Z'));
    $max  = count($dict) - 1;

    $pass = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $dict[random_int(0, $max)];
    }

    return $pass;
}

/**
 * Create a CSRF token to be placed in a url
 *
 * @return string
 * @since 3.1
 */
function osc_csrf_token_url()
{
    return (new Csrf())->tokenUrl();
}

/**
 * Hidden CSRF input fields to drop inside a &lt;form&gt;, for templates that want to emit the token
 * explicitly instead of relying on the shutdown auto-injector. Mark the &lt;form&gt; with
 * class="nocsrf" when using this so the injector skips it and the token is not duplicated — the
 * same convention FormBuilder uses.
 *
 * @return string
 * @since 5.3.0
 */
function osc_csrf_token_form()
{
    return (new Csrf())->tokenForm();
}

/**
 * Check if CSRF token is valid, die in other case
 *
 * @since 3.1
 */
function osc_csrf_check()
{
    (new Csrf())->check();
}

/**
 * Check if an email and/or IP are banned
 *
 * @param string $email
 * @param string $ip
 *
 * @return int 0: not banned, 1: email is banned, 2: IP is banned
 * @since 3.1
 */
function osc_is_banned($email = '', $ip = null)
{
    if ($ip === null) {
        $ip = Params::getServerParam('REMOTE_ADDR');
    }
    $rules = BanRule::newInstance()->listAll();
    if (!osc_is_ip_banned($ip, $rules)) {
        if ($email) {
            return osc_is_email_banned($email, $rules) ? 1 : 0; // 1:Email is banned, 0:not banned
        }

        return 0;
    }

    return 2; //IP is banned
}

/**
 * Check if IP is banned
 *
 * @param string $ip
 * @param string $rules (optional, to savetime and resources)
 *
 * @return boolean
 * @since 3.1
 */
function osc_is_ip_banned($ip, $rules = null)
{
    if ($rules === null) {
        $rules = BanRule::newInstance()->listAll();
    }
    $ip_blocks = explode('.', $ip);
    if (count($ip_blocks) == 4) {
        foreach ($rules as $rule) {
            if ($rule['s_ip'] != '') {
                $blocks = explode('.', $rule['s_ip']);
                if (count($blocks) == 4) {
                    $matched = true;
                    for ($k = 0; $k < 4; $k++) {
                        if (preg_match('|([0-9]+)-([0-9]+)|', $blocks[$k], $match)) {
                            if ($ip_blocks[$k] < $match[1] || $ip_blocks[$k] > $match[2]) {
                                $matched = false;
                                break;
                            }
                        } elseif ($blocks[$k] !== '*' && $blocks[$k] != $ip_blocks[$k]) {
                            $matched = false;
                            break;
                        }
                    }
                    if ($matched) {
                        return true;
                    }
                }
            }
        }
    }

    return false;
}

/**
 * Check if email is banned
 *
 * @param string $email
 * @param string $rules (optional, to savetime and resources)
 *
 * @return boolean
 * @since 3.1
 */
function osc_is_email_banned($email, $rules = null)
{
    if ($rules == null) {
        $rules = BanRule::newInstance()->listAll();
    }
    $email = strtolower($email);
    foreach ($rules as $rule) {
        $rule = str_replace(array('*', '|'), array('.*', "\\"), str_replace('.', "\.", strtolower($rule['s_email'])));
        if ($rule != '') {
            if ($rule[0] === '!') {
                $rule = '|^((?' . $rule . ').*)$|';
            } else {
                $rule = '|^' . $rule . '$|';
            }
            if (preg_match($rule, $email)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Check if username is blacklisted
 *
 * @param string $username
 *
 * @return boolean
 * @since 3.1
 */
function osc_is_username_blacklisted($username)
{
    // Avoid numbers only usernames, this will collide with future users leaving the username field empty
    if (preg_replace('|(\d+)|', '', $username) == '') {
        return true;
    }
    $blacklist = explode(',', osc_username_blacklist());
    foreach ($blacklist as $bl) {
        if (stripos($username, $bl) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Verify an user's password
 *
 * @param $password string
 * @param $hash
 *
 * @return bool
 *
 * @hash  bcrypt/sha1
 * @since 3.3
 */
function osc_verify_password($password, $hash)
{
    if (password_verify($password, $hash)) {
        return true;
    }

    // Fall back to the pre-3.3 sha1 format. That comparison costs microseconds,
    // and password_verify() above rejects a non-bcrypt hash just as cheaply, so
    // an account still on a sha1 hash would answer far quicker than one on
    // bcrypt and could be picked out by timing alone. Match the work first.
    if (strncmp((string)$hash, '$2', 2) !== 0) {
        osc_dummy_password_verify($password);
    }

    return hash_equals((string)$hash, sha1($password));
}

/**
 * Wording for a sign-in refused by {@see \mindstellar\security\LoginThrottle}.
 *
 * Deliberately says nothing about the account: the limiter counts a name that
 * exists and one that does not exactly alike, and the message has to keep that
 * true or it becomes the account oracle the login form no longer is.
 *
 * @param int $seconds how long the block has left to run
 *
 * @return string
 */
function osc_login_throttle_message($seconds)
{
    $minutes = max(1, (int)ceil($seconds / 60));

    return sprintf(
        _mn(
            'Too many failed attempts. Please try again in %d minute.',
            'Too many failed attempts. Please try again in %d minutes.',
            $minutes
        ),
        $minutes
    );
}

/**
 * Spend the work of a password check against a hash that cannot match.
 *
 * A sign-in for an account that does not exist would otherwise return without
 * hashing anything, so "no such account" and "wrong password" would be tens of
 * milliseconds apart and anyone could ask the login form which usernames and
 * e-mail addresses are registered. Call this on the no-such-account branch so
 * both answers cost roughly the same.
 *
 * The two are comparable, not identical: a real check runs at the cost recorded
 * in that account's own hash, which differs from BCRYPT_COST until the account
 * has been re-hashed. The aim is to remove the step change that gives an answer
 * in a single request, not to reach constant time.
 *
 * @param $password string
 *
 * @return bool always false, so callers can use it in place of a real check
 */
function osc_dummy_password_verify($password)
{
    static $hash = null;

    if ($hash === null) {
        // A throwaway hash, kept at the default work factor. An install that
        // overrides BCRYPT_COST needs one at that cost instead, or this branch
        // would be measurably cheaper than a real check.
        $hash = '$2y$12$z.gUvYjOgcp04F2UhY2Odue946VtgluqoVMNNqWfIPfF0s.XE0cIK';
        if (strpos($hash, sprintf('$2y$%02d$', BCRYPT_COST)) !== 0) {
            $hash = password_hash(osc_genRandomPassword(32), PASSWORD_BCRYPT, array('cost' => BCRYPT_COST));
        }
    }

    password_verify($password, $hash);

    return false;
}

/**
 * Hash a password in available method (bcrypt/sha1)
 *
 * @param $password plain-text
 *
 * @return string hashed password
 *
 * @since 3.3
 */
function osc_hash_password($password)
{

    $options = array('cost' => BCRYPT_COST);

    return password_hash($password, PASSWORD_BCRYPT, $options);
}

/**
 * @param $alert
 *
 * @return string
 */
function osc_encrypt_alert($alert)
{
    osc_set_alert_private_key(); // ensure the persistent keys exist
    osc_set_alert_public_key();

    // AES-GCM: 12-byte nonce (the size the mode is defined for) and a full 16-byte tag.
    $iv  = random_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt(
        (string)$alert,
        'aes-256-gcm',
        osc_alert_cipher_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($ciphertext === false) {
        return '';
    }

    return $iv . $tag . $ciphertext;
}

/**
 * @param $string
 *
 * @return string
 */
function osc_decrypt_alert($string)
{
    $string = (string)$string;

    $ivLen  = 12;
    $tagLen = 16;

    if (strlen($string) > $ivLen + $tagLen) {
        $plain = openssl_decrypt(
            substr($string, $ivLen + $tagLen),
            'aes-256-gcm',
            osc_alert_cipher_key(),
            OPENSSL_RAW_DATA,
            substr($string, 0, $ivLen),
            substr($string, $ivLen, $tagLen)
        );

        // A failed tag is the signal that this is not a token of this format --
        // either an older one, or a forgery. Both fall through to the legacy read,
        // which is itself checked by the caller.
        if ($plain !== false) {
            return $plain;
        }
    }

    return osc_decrypt_alert_legacy($string);
}

/**
 * Read a token minted before alert tokens were authenticated.
 *
 * The old format was AES-256-CTR with no MAC: `IV(16) . ciphertext`, the plaintext
 * carrying 32 random characters that were stripped after decryption. CTR is
 * malleable, so tampering with one of these produces a controlled change to the
 * plaintext rather than the garbage the surrounding code assumed -- the JSON parse
 * on the result is what actually rejects a forgery here, and it is a weaker check
 * than a tag. Kept only so a token already in a rendered page still resolves after
 * an upgrade; nothing mints this format any more.
 *
 * @param string $string
 *
 * @return string
 */
function osc_decrypt_alert_legacy($string)
{
    if (strlen($string) <= 16) {
        return '';
    }

    $plain = openssl_decrypt(
        substr($string, 16),
        'aes-256-ctr',
        openssl_digest(hash('sha256', osc_get_alert_private_key(), true), 'sha256', true),
        OPENSSL_RAW_DATA,
        substr($string, 0, 16)
    );

    if ($plain === false) {
        return '';
    }

    $plain = trim(substr($plain, 32));

    // CTR decryption cannot fail: fed a forgery, or anything that simply is not a
    // token of this format, it returns bytes rather than an error. A real alert
    // payload is UTF-8 JSON, so anything that is not even UTF-8 was never a token
    // and is reported as such instead of being handed back as binary noise.
    if ($plain === '' || !preg_match('//u', $plain)) {
        return '';
    }

    return $plain;
}

/**
 * Encryption key for alert tokens, derived from the install's persistent alert key.
 *
 * Derived rather than used directly so the value handed to the cipher is bound to
 * this one purpose: the same stored key backing a second use later cannot then share
 * key material with this one.
 *
 * @return string 32 raw bytes
 */
function osc_alert_cipher_key()
{
    return hash_hmac('sha256', 'shopclass-alert-token-v1', (string)osc_get_alert_private_key(), true);
}

function osc_set_alert_public_key()
{
    if (!osc_get_preference('alert_public_key')) {
        osc_set_preference('alert_public_key', osc_random_string(32));
        osc_reset_preferences();
    }
}

/**
 * Persistent per-install public key for search-alert tokens. Kept in preferences (not the
 * session) so an encoded alert issued on one request stays verifiable/decryptable on a later
 * one without a session — which is what lets anonymous search pages stay cookieless.
 *
 * @return string
 */
function osc_get_alert_public_key()
{
    if (!osc_get_preference('alert_public_key')) {
        osc_set_alert_public_key();
    }

    return osc_get_preference('alert_public_key');
}

function osc_set_alert_private_key()
{
    if (!osc_get_preference('alert_private_key')) {
        osc_set_preference('alert_private_key', osc_random_string(32));
        osc_reset_preferences();
    }
}

/**
 * Persistent per-install private key backing search-alert encryption. See
 * osc_get_alert_public_key() for why it is preference-backed rather than per-session.
 *
 * @return string
 */
function osc_get_alert_private_key()
{
    if (!osc_get_preference('alert_private_key')) {
        osc_set_alert_private_key();
    }

    return osc_get_preference('alert_private_key');
}

/**
 * @param $length
 *
 * @return bool|string
 */
function osc_random_string($length)
{
    $buffer       = '';
    $buffer_valid = false;

    if (function_exists('openssl_random_pseudo_bytes')) {
        $buffer = openssl_random_pseudo_bytes($length);
        if ($buffer) {
            $buffer_valid = true;
        }
    }

    if (!$buffer_valid && is_readable('/dev/urandom')) {
        $f    = fopen('/dev/urandom', 'rb');
        $read = strlen($buffer);
        while ($read < $length) {
            $buffer .= fread($f, $length - $read);
            $read   = strlen($buffer);
        }
        fclose($f);
        if ($read >= $length) {
            $buffer_valid = true;
        }
    }

    if (!$buffer_valid || strlen($buffer) < $length) {
        $bl = strlen($buffer);
        for ($i = 0; $i < $length; $i++) {
            if ($i < $bl) {
                $buffer[$i] ^= chr(mt_rand(0, 255));
            } else {
                $buffer .= chr(mt_rand(0, 255));
            }
        }
    }

    if (!$buffer_valid) {
        $buffer = osc_genRandomPassword(2 * $length);
    }

    return substr(str_replace('+', '.', base64_encode($buffer)), 0, $length);
}
