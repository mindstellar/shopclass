<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\push;

use mindstellar\model\KeyValue;
use mindstellar\utility\Clock;

/**
 * The site's VAPID key pair (P-256) and the signed header that proves a push comes from this
 * site. The pair is made once, under a lock so two first requests cannot each make one, and
 * kept as two preferences that no settings screen or API answer shows.
 */
final class Vapid
{
    public const PUBLIC_PREF  = 'push_vapid_public';
    public const PRIVATE_PREF = 'push_vapid_private';
    public const LOCK_GROUP   = 'push';
    public const LOCK_KEY     = 'vapid-init';
    public const JWT_TTL      = 43200;

    private const WAIT_ROUNDS = 15;
    private const WAIT_MICROS = 200000;

    private string $public = '';
    private string $private = '';

    public function __construct(private KeyValue $kv, private Clock $clock)
    {
    }

    /**
     * The public key as base64url of the 65-byte uncompressed point, for applicationServerKey.
     *
     * @throws \RuntimeException when no key could be made or read
     */
    public function publicKey(): string
    {
        $this->load();

        return $this->public;
    }

    /**
     * @throws \RuntimeException when no key could be made or read
     */
    public function privatePem(): string
    {
        $this->load();

        return $this->private;
    }

    /**
     * The Authorization header value for one push to $endpoint.
     *
     * @throws \RuntimeException when the key is missing or signing failed
     */
    public function authorization(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $host  = (string) ($parts['host'] ?? '');
        if (!is_array($parts) || $host === '') {
            throw new \RuntimeException('A push endpoint needs a host.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port   = isset($parts['port']) ? (int) $parts['port'] : null;
        $aud    = $scheme . '://' . strtolower($host);
        if ($port !== null && $port !== ($scheme === 'http' ? 80 : 443)) {
            $aud .= ':' . $port;
        }

        $claims = ['aud' => $aud, 'exp' => $this->clock->now() + self::JWT_TTL, 'sub' => 'mailto:' . (string) osc_contact_email()];
        $input  = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR))
            . '.' . self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $key = openssl_pkey_get_private($this->privatePem());
        if ($key === false || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The push key could not sign.');
        }

        return 'vapid t=' . $input . '.' . self::b64(self::rawSignature((string) $der)) . ', k=' . $this->publicKey();
    }

    /**
     * An ECDSA signature as OpenSSL gives it (DER SEQUENCE of r and s) in the 64-byte r||s form
     * a JWS uses.
     *
     * @throws \InvalidArgumentException on anything that is not such a signature
     */
    public static function rawSignature(string $der): string
    {
        $len = strlen($der);
        if ($len < 8 || $der[0] !== "\x30") {
            throw new \InvalidArgumentException('Not a DER signature.');
        }
        $at   = 1;
        $body = ord($der[$at++]);
        if ($body === 0x81) {
            $body = ord($der[$at++]);
        } elseif ($body > 0x7F) {
            throw new \InvalidArgumentException('Not a DER signature.');
        }
        if ($at + $body !== $len) {
            throw new \InvalidArgumentException('Not a DER signature.');
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if ($at + 2 > $len || $der[$at] !== "\x02") {
                throw new \InvalidArgumentException('Not a DER signature.');
            }
            $size = ord($der[$at + 1]);
            $at  += 2;
            if ($size === 0 || $at + $size > $len) {
                throw new \InvalidArgumentException('Not a DER signature.');
            }
            $int = ltrim(substr($der, $at, $size), "\x00");
            $at += $size;
            if (strlen($int) > 32) {
                throw new \InvalidArgumentException('Not a P-256 signature.');
            }
            $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }
        if ($at !== $len) {
            throw new \InvalidArgumentException('Not a DER signature.');
        }

        return $out;
    }

    /**
     * Read the pair, or make it when there is none.
     *
     * @throws \RuntimeException
     */
    private function load(): void
    {
        if ($this->public !== '' && $this->private !== '') {
            return;
        }
        if ($this->read(false)) {
            return;
        }
        $now = $this->clock->now();
        if ($this->kv->claim(self::LOCK_GROUP, self::LOCK_KEY, $now + 30, 'locked', null, $now)) {
            try {
                // Another request may have finished making the pair after this one loaded its preferences.
                if (!$this->read(true)) {
                    $this->generate();
                }
            } finally {
                $this->kv->delete(self::LOCK_GROUP, self::LOCK_KEY);
            }

            return;
        }
        for ($i = 0; $i < self::WAIT_ROUNDS; $i++) {
            if ($this->read(true)) {
                return;
            }
            usleep(self::WAIT_MICROS);
        }

        throw new \RuntimeException('The push keys are being made; try again.');
    }

    private function read(bool $fresh): bool
    {
        if ($fresh) {
            osc_reset_preferences();
        }
        $public  = (string) osc_get_preference(self::PUBLIC_PREF);
        $private = (string) osc_get_preference(self::PRIVATE_PREF);
        if ($public === '' || $private === '') {
            return false;
        }
        $this->public  = $public;
        $this->private = $private;

        return true;
    }

    /**
     * @throws \RuntimeException
     */
    private function generate(): void
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('This server cannot make a P-256 key.');
        }
        $ec = openssl_pkey_get_details($key)['ec'] ?? null;
        if (!is_array($ec) || !isset($ec['x'], $ec['y'])) {
            throw new \RuntimeException('This server cannot make a P-256 key.');
        }
        $point = "\x04" . str_pad((string) $ec['x'], 32, "\x00", STR_PAD_LEFT) . str_pad((string) $ec['y'], 32, "\x00", STR_PAD_LEFT);
        $public = self::b64($point);
        // The private key first: a reader takes the pair only when both are there.
        if (!osc_set_preference(self::PRIVATE_PREF, (string) $pem, 'osclass', 'STRING')
            || !osc_set_preference(self::PUBLIC_PREF, $public, 'osclass', 'STRING')
        ) {
            throw new \RuntimeException('The push keys could not be saved.');
        }
        $this->public  = $public;
        $this->private = (string) $pem;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
