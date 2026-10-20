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

namespace mindstellar\location;

use mindstellar\search\Distance;

/**
 * Geocodes through the active provider and keeps each answer in the key-value store, so a
 * repeated address costs no call. An address with no match is kept for a day.
 */
final class Geocoder
{
    public const KV_GROUP = 'geocode';

    /** Seconds a "no match" answer is kept. */
    private const MISS_TTL = 86400;

    /** The longest an answer is kept, so the cache cannot grow without end. */
    private const MAX_TTL = 31536000;

    public function __construct(private GeocodeProvider $provider)
    {
    }

    /**
     * A geocoder for the provider the owner picked, or null when none is set up.
     */
    public static function active(): ?self
    {
        $provider = GeocodeProviderRegistry::getInstance()->active();

        return $provider === null ? null : new self($provider);
    }

    public function provider(): GeocodeProvider
    {
        return $this->provider;
    }

    /**
     * The coordinates of an address, from the cache or the provider. The `geocode_address`
     * filter may rewrite the address; `geocode_coordinates` may change or drop the answer.
     *
     * @return array{0:float,1:float}|null
     * @throws \RuntimeException from the provider when it cannot answer; nothing is cached then
     */
    public function geocode(string $address, ?string $countryCode = null): ?array
    {
        $address = trim((string) preg_replace('/[\s,]*,[\s,]*/', ', ', (string) osc_apply_filter('geocode_address', $address, $countryCode)), ", \t\n\r\0\x0B");
        $country = $countryCode !== null && preg_match('/^[A-Za-z]{2}\z/', $countryCode) ? strtolower($countryCode) : null;
        if ($address === '') {
            return null;
        }

        $key    = hash('sha256', $this->provider->id() . '|' . ($country ?? '') . '|' . mb_strtolower($address));
        $cached = osc_kv_get(self::KV_GROUP, $key, false);
        if (is_array($cached)) {
            return $cached === [] ? null : [(float) $cached[0], (float) $cached[1]];
        }

        $coords = $this->provider->geocode($address, $country);
        if ($coords !== null && !self::valid($coords)) {
            $coords = null;
        }
        $providerId = $this->provider->id();
        $coords     = osc_apply_filter('geocode_coordinates', $coords, $address, $country, $providerId);
        $coords = is_array($coords) && self::valid($coords) ? [(float) $coords[0], (float) $coords[1]] : null;

        $ttl = $coords === null ? self::MISS_TTL : min($this->provider->cacheTtl() ?? self::MAX_TTL, self::MAX_TTL);
        if ($ttl > 0) {
            osc_kv_set(self::KV_GROUP, $key, $coords ?? [], $ttl);
        }

        return $coords;
    }

    /**
     * @param array<int|string,mixed> $coords
     */
    private static function valid(array $coords): bool
    {
        $lat = $coords[0] ?? null;
        $lng = $coords[1] ?? null;

        return Distance::validPoint($lat, $lng) && !((float) $lat == 0.0 && (float) $lng == 0.0);
    }
}
