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

namespace mindstellar\api\ratelimit;

use mindstellar\api\Request;
use mindstellar\api\RouteSpec;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\security\AddressBucket;

/**
 * Every rate limit the API counts, in one place: per client address when anonymous, per key
 * (and address for a public key), per user for a token, session or personal key, plus a write bucket.
 * Hourly caps on new listings, photos fetched by URL and sign-ups stand in for a captcha.
 * Those caps and the write bucket are exact: counted in the database, never in APCu.
 */
final class RatePolicy
{
    /** One address may post this many times a user's listing cap, for offices and carriers that share one. */
    public const ADDRESS_FACTOR = 3;

    /** Photos one user may have fetched by URL in an hour, posting or editing listings. */
    public const PHOTO_FETCHES_PER_HOUR = 30;

    /** Sign-ups allowed per address in an hour. */
    public const SIGN_UPS_PER_ADDRESS = 5;

    /** Sign-ups allowed across the whole site in an hour. */
    public const SIGN_UPS_PER_SITE = 100;

    private const HOUR = 3600;

    public function __construct(private ApiSettings $settings)
    {
    }

    /**
     * @return RateBucket[]
     */
    public function bucketsFor(Request $request, RouteSpec $route, Credential $credential): array
    {
        $address = AddressBucket::of($request->ip());
        $max     = $credential->rateLimit() ?? $this->settings->rateDefault();
        // A user's own keys count with their tokens, so more keys do not mean a higher limit.
        [$name, $key, $max] = match (true) {
            $credential->kind() === CredentialKind::ANONYMOUS => ['api_anon', $address, $this->settings->rateAnon()],
            $credential->kind() === CredentialKind::PUBLIC    => ['api_key', $credential->id() . '@' . $address, $max],
            $credential->isUser(),
            $credential->kind() === CredentialKind::USER,
            $credential->kind() === CredentialKind::SESSION   => ['api_user', (string) $credential->userId(), $max],
            default                                           => ['api_key', (string) $credential->id(), $max],
        };

        $limit   = ['max' => $max, 'window' => 60];
        $limit   = (array) osc_apply_filter('api_rate_limit', $limit, $credential, $route);
        $buckets = [new RateBucket($name, $key, (int) ($limit['max'] ?? $max), (int) ($limit['window'] ?? 60))];

        if ($request->isWrite()) {
            $buckets[] = new RateBucket('api_write', $name . ':' . $key, $this->settings->rateWrite(), 60, true);
        }

        return $buckets;
    }

    /**
     * A new listing, counted per user and per address.
     *
     * @return RateBucket[]
     */
    public function newListing(int $userId, string $ip): array
    {
        $max = $this->settings->listingsPerHour();

        return [
            new RateBucket('api_listing_post', (string) $userId, $max, self::HOUR, true),
            new RateBucket('api_listing_ip', AddressBucket::of($ip), $max * self::ADDRESS_FACTOR, self::HOUR, true),
        ];
    }

    /**
     * One photo to fetch by URL.
     */
    public function photoFetch(int $userId): RateBucket
    {
        return new RateBucket('api_photo_fetch', (string) $userId, self::PHOTO_FETCHES_PER_HOUR, self::HOUR, true);
    }

    /**
     * A sign-up, counted per address and for the whole site.
     *
     * @return RateBucket[]
     */
    public function signUp(string $ip): array
    {
        return [
            new RateBucket('api_register', AddressBucket::of($ip), self::SIGN_UPS_PER_ADDRESS, self::HOUR, true),
            new RateBucket('api_register_site', 'all', self::SIGN_UPS_PER_SITE, self::HOUR, true),
        ];
    }
}
