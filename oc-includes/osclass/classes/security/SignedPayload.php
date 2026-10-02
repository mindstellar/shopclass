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
     *
     * @return string
     */
    public static function pack(string $purpose, array $data, int $ttl): string
    {
        $data['x'] = time() + $ttl;
        $payload   = self::b64((string) json_encode($data));

        return $payload . '.' . self::sign($purpose, $payload);
    }

    /**
     * @param string $purpose
     * @param string $token
     *
     * @return array<string,mixed>|null null when forged, damaged, made for another purpose or expired
     */
    public static function unpack(string $purpose, string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals(self::sign($purpose, $parts[0]), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!is_array($data) || !isset($data['x']) || (int) $data['x'] < time()) {
            return null;
        }
        unset($data['x']);

        return $data;
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
