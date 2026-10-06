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
 * Every rate limit the API counts, in one place. Each request is counted for who is calling,
 * plus a write bucket for POST, PUT, PATCH and DELETE: anonymous calls per client address, a
 * public key per key and address (one app has many users), any other key per key, an access
 * token or a same-site session per user.
 *
 * The API shows no captcha, so new listings, photos fetched by URL and sign-ups also have
 * hourly caps that stand in for one.
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
        [$name, $key, $max] = match ($credential->kind()) {
            CredentialKind::ANONYMOUS => ['api_anon', $address, $this->settings->rateAnon()],
            CredentialKind::PUBLIC    => ['api_key', $credential->id() . '@' . $address, $credential->rateLimit() ?? $this->settings->rateDefault()],
            CredentialKind::USER,
            CredentialKind::SESSION   => ['api_user', (string) $credential->userId(), $credential->rateLimit() ?? $this->settings->rateDefault()],
            default                   => ['api_key', (string) $credential->id(), $credential->rateLimit() ?? $this->settings->rateDefault()],
        };

        $limit   = ['max' => $max, 'window' => 60];
        $limit   = (array) osc_apply_filter('api_rate_limit', $limit, $credential, $route);
        $buckets = [new RateBucket($name, $key, (int) ($limit['max'] ?? $max), (int) ($limit['window'] ?? 60))];

        if ($request->isWrite()) {
            $buckets[] = new RateBucket('api_write', $name . ':' . $key, $this->settings->rateWrite());
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
            new RateBucket('api_listing_post', (string) $userId, $max, self::HOUR),
            new RateBucket('api_listing_ip', AddressBucket::of($ip), $max * self::ADDRESS_FACTOR, self::HOUR),
        ];
    }

    /**
     * One photo to fetch by URL.
     */
    public function photoFetch(int $userId): RateBucket
    {
        return new RateBucket('api_photo_fetch', (string) $userId, self::PHOTO_FETCHES_PER_HOUR, self::HOUR);
    }

    /**
     * A sign-up, counted per address and for the whole site.
     *
     * @return RateBucket[]
     */
    public function signUp(string $ip): array
    {
        return [
            new RateBucket('api_register', AddressBucket::of($ip), self::SIGN_UPS_PER_ADDRESS, self::HOUR),
            new RateBucket('api_register_site', 'all', self::SIGN_UPS_PER_SITE, self::HOUR),
        ];
    }
}
