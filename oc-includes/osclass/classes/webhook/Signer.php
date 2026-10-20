<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\webhook;

/**
 * Standard Webhooks signatures (https://www.standardwebhooks.com/): `webhook-signature` is
 * `v1,<base64 HMAC-SHA256 of "{id}.{timestamp}.{body}">`, keyed with the secret's bytes,
 * one per secret and space separated while a rotated-out secret still signs.
 */
final class Signer
{
    public const SECRET_PREFIX = 'whsec_';

    /** Seconds a receiver should accept a timestamp either side of its clock. */
    public const TOLERANCE = 300;

    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private function __construct()
    {
    }

    /**
     * A new secret: `whsec_` and 24 random bytes in base64.
     */
    public static function newSecret(): string
    {
        return self::SECRET_PREFIX . base64_encode(random_bytes(24));
    }

    /**
     * A message id, `msg_` and a ULID, so ids sort by when they were made.
     */
    public static function messageId(?int $nowMs = null): string
    {
        $time = $nowMs ?? (int) floor(microtime(true) * 1000);
        $out  = '';
        for ($i = 0; $i < 10; $i++) {
            $out  = self::CROCKFORD[$time % 32] . $out;
            $time = intdiv($time, 32);
        }
        $random = random_bytes(10);
        $bits   = '';
        foreach (str_split($random) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::CROCKFORD[bindec($chunk)];
        }

        return 'msg_' . $out;
    }

    /**
     * The `webhook-signature` header value.
     *
     * @param string[] $secrets
     */
    public static function sign(string $id, int $timestamp, string $body, array $secrets): string
    {
        $signatures = [];
        foreach ($secrets as $secret) {
            $signatures[] = 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, self::key($secret), true));
        }

        return implode(' ', $signatures);
    }

    /**
     * What a receiver does: true when one of the header's signatures matches and the
     * timestamp is within TOLERANCE of $now.
     */
    public static function verify(string $secret, string $id, string $timestamp, string $body, string $header, int $now): bool
    {
        if (preg_match('/^\d{1,12}$/D', $timestamp) !== 1 || abs($now - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }
        $expected = self::sign($id, (int) $timestamp, $body, [$secret]);
        foreach (preg_split('/\s+/', trim($header)) ?: [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The HMAC key: the bytes the base64 after `whsec_` stands for.
     */
    private static function key(string $secret): string
    {
        $encoded = str_starts_with($secret, self::SECRET_PREFIX) ? substr($secret, strlen(self::SECRET_PREFIX)) : $secret;
        $bytes   = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException('A webhook secret is whsec_ followed by base64.');
        }

        return $bytes;
    }
}
