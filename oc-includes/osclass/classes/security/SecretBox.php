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

namespace mindstellar\security;

/**
 * Secrets the site must read back, such as webhook signing secrets, kept encrypted at rest with
 * AES-256-GCM under a key derived from the install's signing key. Each use names a purpose, so
 * a value sealed for one purpose does not open for another.
 */
final class SecretBox
{
    private const PREFIX = 'enc1:';

    public static function seal(string $purpose, string $plain): string
    {
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('The secret could not be encrypted.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * The plain secret; a value stored before encryption is returned as it is.
     *
     * @return string|null null when a sealed value cannot be opened, such as after the signing key changed
     */
    public static function open(string $purpose, string $stored): ?string
    {
        if (!self::isSealed($stored)) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }

    public static function isSealed(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    private static function key(string $purpose): string
    {
        return hash_hmac('sha256', 'secret-box:' . $purpose, (string) SigningKey::get(), true);
    }
}
