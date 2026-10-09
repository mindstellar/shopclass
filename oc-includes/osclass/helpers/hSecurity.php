<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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

use mindstellar\security\Csrf;
use mindstellar\security\SecretBox;

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
 * @param int $length
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
 * @return void
 * @since 3.1
 */
function osc_csrf_check()
{
    (new Csrf())->check();
}

/**
 * Whether the current request's REMOTE_ADDR looks like a proxy's address instead of the
 * visitor's — a forwarding header disagrees with it. Detection only; core still reads the
 * visitor IP from REMOTE_ADDR alone, see osc_is_banned() and osc_validate_spam_delay().
 *
 * @return array{header: string, proxy: string}|null the triggering header and REMOTE_ADDR, or null when nothing disagrees
 */
function osc_proxy_ip_mismatch()
{
    return \mindstellar\security\ProxyIpMismatch::detect(
        Params::getServerParam('REMOTE_ADDR'),
        Params::getServerParamsAsArray()
    );
}

/**
 * The ban rules in force. Expired rules are left out, and so are rules that block
 * messages only, unless $scope is 'messages'.
 *
 * @param string $scope 'all', or 'messages' for the contact and share forms
 *
 * @return array<int,array<string,mixed>>
 */
function osc_ban_rules(string $scope = 'all'): array
{
    try {
        $rows = \mindstellar\security\BanRuleStore::cached();
    } catch (\mindstellar\database\DbException $e) {
        return array();
    }
    $now = date('Y-m-d H:i:s');

    return array_values(array_filter($rows, static function ($rule) use ($scope, $now) {
        if (!empty($rule['dt_expires']) && $rule['dt_expires'] <= $now) {
            return false;
        }

        return ($rule['s_scope'] ?? 'all') !== 'messages' || $scope === 'messages';
    }));
}

/**
 * Check if an email and/or IP are banned
 *
 * @param string      $email
 * @param string|null $ip    Defaults to the request's REMOTE_ADDR
 * @param string      $scope 'messages' also applies the rules that block messages only
 *
 * @return int 0: not banned, 1: email is banned, 2: IP is banned
 * @since 3.1
 */
function osc_is_banned($email = '', $ip = null, string $scope = 'all')
{
    if ($ip === null) {
        $ip = Params::getServerParam('REMOTE_ADDR');
    }
    $rules = osc_ban_rules($scope);
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
 * @param string                              $ip
 * @param array<int,array<string,mixed>>|null $rules Pass the rule list to save a query
 *
 * @return bool
 * @since 3.1
 */
function osc_is_ip_banned($ip, $rules = null)
{
    if ($rules === null) {
        $rules = osc_ban_rules();
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
 * @param string                              $email
 * @param array<int,array<string,mixed>>|null $rules Pass the rule list to save a query
 *
 * @return bool
 * @since 3.1
 */
function osc_is_email_banned($email, $rules = null)
{
    // A site with no rules passes an empty list, which must not be read again.
    if (!is_array($rules)) {
        $rules = osc_ban_rules();
    }
    // Match the address as it will be stored, so spaces or stray symbols cannot slip past a rule.
    $email = strtolower(trim((string) filter_var((string) $email, FILTER_SANITIZE_EMAIL)));
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
 * @return bool
 * @since 3.1
 */
function osc_is_username_blacklisted($username)
{
    // Avoid numbers only usernames, this will collide with future users leaving the username field empty
    if (preg_replace('|(\d+)|', '', $username) == '') {
        return true;
    }
    // Dots and underscores are ignored on both sides, so "ad.min" matches "admin".
    $name = str_replace(['.', '_'], '', (string) $username);
    foreach (explode(',', (string) osc_username_blacklist()) as $bl) {
        $bl = str_replace(['.', '_'], '', trim($bl));
        // An empty entry would match every name.
        if ($bl !== '' && stripos($name, $bl) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Verify an user's password
 *
 * @param string $password
 * @param string $hash
 *
 * @return bool
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
 * @param string $password
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
 * @param string $password plain-text
 *
 * @return string hashed password
 * @since 3.3
 */
function osc_hash_password($password)
{

    $options = array('cost' => BCRYPT_COST);

    return password_hash($password, PASSWORD_BCRYPT, $options);
}

/**
 * Encrypt an alert payload into a SecretBox token.
 *
 * @param string $alert
 *
 * @return string Empty string when encryption fails
 */
function osc_encrypt_alert($alert)
{
    try {
        return SecretBox::seal('alert-token', (string)$alert);
    } catch (\RuntimeException $e) {
        return '';
    }
}

/**
 * Decrypt an alert token. Tokens from before 7.0 (raw AES-GCM under the alert_private_key
 * preference) still open; the older CTR format is refused, as the alert cron runs its conditions.
 *
 * @param string $string
 *
 * @return string Empty string when the token cannot be read
 */
function osc_decrypt_alert($string)
{
    $string = (string)$string;
    if (SecretBox::isSealed($string)) {
        return SecretBox::open('alert-token', $string) ?? '';
    }
    if ($string === '' || !osc_get_preference('alert_private_key')) {
        return '';
    }

    return SecretBox::openWith(osc_alert_cipher_key(), base64_encode($string), '') ?? '';
}

/**
 * Read a token minted before alert tokens were authenticated.
 *
 * The old format was AES-256-CTR with no MAC: `IV(16) . ciphertext`, the plaintext
 * carrying 32 random characters that were stripped after decryption. CTR is
 * malleable, so tampering with one of these produces a controlled change to the
 * plaintext rather than the garbage the surrounding code assumed -- the JSON parse
 * on the result is what actually rejects a forgery here, and it is a weaker check
 * than a tag. Core no longer reads this format; the function stays for callers outside core.
 *
 * @deprecated since 6.4.0; a token of this format is not trustworthy
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
 * Key of the alert tokens minted before 7.0, derived from the alert_private_key preference.
 *
 * @deprecated since 7.0.0; new tokens are sealed with SecretBox
 *
 * @return string 32 raw bytes
 */
function osc_alert_cipher_key()
{
    return hash_hmac('sha256', 'shopclass-alert-token-v1', (string)osc_get_preference('alert_private_key'), true);
}

/**
 * Mint the install's persistent alert public key if it has none yet.
 *
 * @deprecated since 7.0.0; nothing reads this key
 *
 * @return void
 */
function osc_set_alert_public_key()
{
    if (!osc_get_preference('alert_public_key')) {
        osc_set_preference('alert_public_key', osc_random_string(32));
        osc_reset_preferences();
    }
}

/**
 * Persistent per-install public key, once meant for search-alert tokens.
 *
 * @deprecated since 7.0.0; nothing reads this key
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

/**
 * Mint the install's persistent alert private key if it has none yet.
 *
 * @return void
 */
function osc_set_alert_private_key()
{
    if (!osc_get_preference('alert_private_key')) {
        osc_set_preference('alert_private_key', osc_random_string(32));
        osc_reset_preferences();
    }
}

/**
 * Persistent per-install key of the alert tokens minted before 7.0.
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
 * A random string of $length characters from A-Z, a-z, 0-9, "." and "/".
 *
 * @param int $length
 *
 * @return string
 */
function osc_random_string($length)
{
    $length = max(0, (int)$length);

    return substr(str_replace('+', '.', base64_encode(random_bytes(max(1, $length)))), 0, $length);
}
