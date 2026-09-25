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
 * Time-based one-time codes (RFC 6238): SHA-1, 30-second steps, 6 digits, which every
 * authenticator app reads. Secrets are base32.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD   = 30;
    private const DIGITS   = 6;

    /**
     * @return string a base32 secret of 20 random bytes
     */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * @param string   $secret base32
     * @param int|null $step   time step, now when null
     *
     * @return string
     */
    public static function code(string $secret, ?int $step = null): string
    {
        $step   = $step ?? self::step();
        $hash   = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string)($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Check a code against the current step and one step either side. A step at or before
     * $lastStep is refused, so a code works once.
     *
     * @param string   $secret
     * @param string   $code
     * @param int      $lastStep the last step accepted for this secret
     * @param int|null $now      time step to check around, now when null
     *
     * @return int|null the matching step, or null
     */
    public static function verify(string $secret, string $code, int $lastStep = 0, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^[0-9]{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $now = $now ?? self::step();
        foreach (array($now - 1, $now, $now + 1) as $step) {
            if ($step > $lastStep && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param int $count
     *
     * @return array<int,string> one-time backup codes, 10 base32 characters each
     */
    public static function newBackupCodes(int $count = 8): array
    {
        $codes = array();
        for ($i = 0; $i < $count; $i++) {
            $codes[] = substr(self::base32Encode(random_bytes(7)), 0, 10);
        }

        return $codes;
    }

    /**
     * The stored form of a backup code. The codes are random, so a fast hash is enough.
     *
     * @param string $code
     *
     * @return string
     */
    public static function hashBackupCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[\s-]+/', '', $code)));
    }

    /**
     * @param string $secret
     * @param string $account shown in the app under the issuer
     * @param string $issuer
     *
     * @return string an otpauth:// URI for a QR code
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
               . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
               . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /**
     * @param int|null $time unix time, now when null
     *
     * @return int
     */
    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value !== false) {
                $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
