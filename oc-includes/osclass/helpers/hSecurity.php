<?php

/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Helper Security
 *
 * @package    Osclass
 * @subpackage Helpers
 * @author     Osclass
 */

use mindstellar\Csrf;
use OpensslCryptor\Cryptor;

if (!defined('BCRYPT_COST')) {
    define('BCRYPT_COST', 15);
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

    return password_verify($password, $hash) ? true : hash_equals((string)$hash, sha1($password));
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
    $string = osc_genRandomPassword(32) . $alert;
    osc_set_alert_private_key(); // ensure the persistent keys exist
    osc_set_alert_public_key();
    $key = hash('sha256', osc_get_alert_private_key(), true);

    if (function_exists('openssl_digest') && function_exists('openssl_encrypt') && function_exists('openssl_decrypt')
        && in_array('aes-256-ctr', openssl_get_cipher_methods(true))
        && in_array('sha256', openssl_get_md_methods(true))
    ) {
        return Cryptor::Encrypt($string, $key, 0);
    }

    // COMPATIBILITY
    while (strlen($string) % 32 != 0) {
        $string .= "\0";
    }

    $cipher = new phpseclib\Crypt\Rijndael();
    $cipher->disablePadding();
    $cipher->setBlockLength(256);
    $cipher->setKey($key);
    $cipher->setIV($key);

    return $cipher->encrypt($string);
}


/**
 * @param $string
 *
 * @return string
 */
function osc_decrypt_alert($string)
{
    $key = hash('sha256', osc_get_alert_private_key(), true);

    if (function_exists('openssl_digest') && function_exists('openssl_encrypt') && function_exists('openssl_decrypt')
        && in_array('aes-256-ctr', openssl_get_cipher_methods(true))
        && in_array('sha256', openssl_get_md_methods(true))
    ) {
        try {
            return trim(substr(Cryptor::Decrypt($string, $key, 0), 32));
        } catch (Exception $e) {
            trigger_error($e->getMessage().' in '.$e->getFile().' at line '.$e->getLine(), E_USER_WARNING);
        }
    }

    // COMPATIBILITY

    $cipher = new phpseclib\Crypt\Rijndael();
    $cipher->disablePadding();
    $cipher->setBlockLength(256);
    $cipher->setKey($key);
    $cipher->setIV($key);

    return trim(substr($cipher->decrypt($string), 32));
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
