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
 * A small array signed with the install's key, for links and cookies that must come back
 * unchanged: "payload.signature", both base64url. Each use names a purpose, so a token made
 * for one purpose is never accepted for another.
 */
final class SignedPayload
{
    /**
     * @param string              $purpose e.g. 'report-sender'
     * @param array<string,mixed> $data
     * @param int                 $ttl     seconds the token stays valid
     * @param int                 $round   when above 0, the expiry is rounded up to a multiple of this many
     *                                     seconds, so equal data packed in the same window gives the same token
     * @param int|null            $now     the current time; time() when null
     *
     * @return string
     */
    public static function pack(string $purpose, array $data, int $ttl, int $round = 0, ?int $now = null): string
    {
        $data['x'] = ($now ?? time()) + $ttl;
        if ($round > 0) {
            $data['x'] = (int) (ceil($data['x'] / $round) * $round);
        }
        $payload   = self::b64((string) json_encode($data));

        return $payload . '.' . self::sign($purpose, $payload);
    }

    /**
     * @param string   $purpose
     * @param string   $token
     * @param int|null $now the current time; time() when null
     *
     * @return array<string,mixed>|null null when forged, damaged, made for another purpose or expired
     */
    public static function unpack(string $purpose, string $token, ?int $now = null): ?array
    {
        $opened = self::open($purpose, $token, $now);

        return $opened === null || $opened['expired'] ? null : $opened['data'];
    }

    /**
     * Check the signature apart from the expiry, for a caller that answers an expired token
     * differently from a forged one.
     *
     * @param string   $purpose
     * @param string   $token
     * @param int|null $now the current time; time() when null
     *
     * @return array{data:array<string,mixed>,expired:bool}|null null when forged, damaged or made for another purpose
     */
    public static function open(string $purpose, string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals(self::sign($purpose, $parts[0]), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!is_array($data) || !isset($data['x'])) {
            return null;
        }
        $expired = (int) $data['x'] < ($now ?? time());
        unset($data['x']);

        return array('data' => $data, 'expired' => $expired);
    }

    private static function sign(string $purpose, string $payload): string
    {
        return self::b64(hash_hmac('sha256', $purpose . '|' . $payload, SigningKey::get(), true));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
