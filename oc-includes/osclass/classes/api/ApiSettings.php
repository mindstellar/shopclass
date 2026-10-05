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

namespace mindstellar\api;

/**
 * The site's API settings (preference section `api`). The defaults here are the only ones:
 * the settings screen and the migration read them, and the installer seeds the same values.
 * Limits no screen sets are constants on the class that uses them, such as AccessTokens::TTL.
 */
final class ApiSettings
{
    public const SECTION = 'api';

    /** New listings one user may post through the API in an hour, when the setting is 0. */
    public const LISTINGS_PER_HOUR = 30;

    /** The same while the listing form asks for a captcha, which the API cannot show. */
    public const LISTINGS_PER_HOUR_CAPTCHA = 10;

    /** preference => default */
    public const DEFAULTS = [
        'api_enabled'            => false,
        'api_public_reads'       => false,
        'api_rate_limit_default' => 120,
        'api_rate_limit_anon'    => 60,
        'api_rate_limit_write'   => 30,
        'api_cache_max_age'      => 60,
        'api_hide_phone'         => false,
        'api_cors_origins'       => '',
        'api_user_keys'          => false,
        'api_registration'       => false,
        'api_photo_urls'         => false,
        'api_listing_rate'       => 0,
        'api_webhooks_allow_private' => false,
        'api_password_grant'     => true,
    ];

    public function __construct(
        private bool $enabled = self::DEFAULTS['api_enabled'],
        private bool $publicReads = self::DEFAULTS['api_public_reads'],
        private int $rateDefault = self::DEFAULTS['api_rate_limit_default'],
        private int $rateAnon = self::DEFAULTS['api_rate_limit_anon'],
        private int $rateWrite = self::DEFAULTS['api_rate_limit_write'],
        private int $cacheMaxAge = self::DEFAULTS['api_cache_max_age'],
        private bool $hidePhone = self::DEFAULTS['api_hide_phone'],
        private string $corsOrigins = self::DEFAULTS['api_cors_origins'],
        private bool $userKeys = self::DEFAULTS['api_user_keys'],
        private bool $registration = self::DEFAULTS['api_registration'],
        private bool $photoUrls = self::DEFAULTS['api_photo_urls'],
        private int $listingRate = self::DEFAULTS['api_listing_rate'],
        private bool $listingCaptcha = false,
        private bool $webhooksAllowPrivate = self::DEFAULTS['api_webhooks_allow_private'],
        private bool $passwordGrant = self::DEFAULTS['api_password_grant']
    ) {
    }

    /**
     * The stored preferences; a missing one takes its default.
     */
    public static function fromPreferences(): self
    {
        $value = static function (string $name): string {
            $stored = (string) osc_get_preference($name, self::SECTION);

            return $stored === '' ? self::seedValue($name) : $stored;
        };

        return new self(
            $value('api_enabled') === '1',
            $value('api_public_reads') === '1',
            (int) $value('api_rate_limit_default'),
            (int) $value('api_rate_limit_anon'),
            (int) $value('api_rate_limit_write'),
            (int) $value('api_cache_max_age'),
            $value('api_hide_phone') === '1',
            $value('api_cors_origins'),
            $value('api_user_keys') === '1',
            $value('api_registration') === '1',
            $value('api_photo_urls') === '1',
            (int) $value('api_listing_rate'),
            function_exists('osc_recaptcha_items_enabled') && (bool) osc_recaptcha_items_enabled(),
            $value('api_webhooks_allow_private') === '1',
            $value('api_password_grant') === '1'
        );
    }

    /**
     * A default as t_preference stores it: '1'/'0' for a switch, digits for a number.
     */
    public static function seedValue(string $name): string
    {
        $default = self::DEFAULTS[$name];

        return is_bool($default) ? ($default ? '1' : '0') : (string) $default;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function publicReads(): bool
    {
        return $this->publicReads;
    }

    public function rateDefault(): int
    {
        return $this->rateDefault;
    }

    public function rateAnon(): int
    {
        return $this->rateAnon;
    }

    public function rateWrite(): int
    {
        return $this->rateWrite;
    }

    public function cacheMaxAge(): int
    {
        return $this->cacheMaxAge;
    }

    /**
     * Whether listings leave out the seller's phone for everyone but the owner and admins.
     */
    public function hidePhone(): bool
    {
        return $this->hidePhone;
    }

    /**
     * Origins allowed to call the API from a browser, one per line; '' for none.
     */
    public function corsOrigins(): string
    {
        return $this->corsOrigins;
    }

    /**
     * Whether users may make their own API keys.
     */
    public function userKeys(): bool
    {
        return $this->userKeys;
    }

    /**
     * Whether new accounts may be registered through the API.
     */
    public function registration(): bool
    {
        return $this->registration;
    }

    /**
     * Whether a new listing may name photos by URL for the server to fetch.
     */
    public function photoUrls(): bool
    {
        return $this->photoUrls;
    }

    /**
     * New listings one user may post through the API in an hour: the setting, or when it is
     * 0 the default, which is lower while the listing form asks for a captcha.
     */
    public function listingsPerHour(): int
    {
        if ($this->listingRate > 0) {
            return $this->listingRate;
        }

        return $this->listingCaptcha ? self::LISTINGS_PER_HOUR_CAPTCHA : self::LISTINGS_PER_HOUR;
    }

    /**
     * Whether webhook endpoints may be on a private network, such as a server on the LAN.
     */
    public function webhooksAllowPrivate(): bool
    {
        return $this->webhooksAllowPrivate;
    }

    /**
     * Whether apps may sign a user in with a username and password (the OAuth password grant).
     */
    public function passwordGrant(): bool
    {
        return $this->passwordGrant;
    }
}
