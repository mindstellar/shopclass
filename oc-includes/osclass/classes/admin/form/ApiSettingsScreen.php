<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

use mindstellar\apiaccess\ApiSettings;
use mindstellar\base\SettingsScreen;

/**
 * The settings half of Settings -> API, stored in the `api` preference section.
 *
 * @package mindstellar\admin\form
 */
final class ApiSettingsScreen extends SettingsScreen
{
    public const PAGE_ID = 'core.settings_api';
    protected const ACTION    = 'api_post';
    protected const FORM_NAME = 'api_form';

    /** An origin as a browser sends it: scheme, host and an optional port, nothing else. */
    private const ORIGIN = '~^https?://[a-z0-9.-]+(:\d{1,5})?$~iD';

    protected static function declareFields(): void
    {
        $perMinute = __('requests a minute');

        CoreSettings::page(self::PAGE_ID, __('API'), ApiSettings::SECTION)
            ->group(__('Access'))
            ->checkbox('api_enabled', __('Turn on the REST API'), __('Off by default. When off, every call to /api/v1 is refused.'))
                ->rowLabel(__('API'))
                ->default(ApiSettings::DEFAULTS['api_enabled'])
            ->checkbox(
                'api_public_reads',
                __('Allow reading public data without a key'),
                __('Off by default. When on, anyone can read listings, categories and locations without a key, which makes copying your site easy.')
            )
                ->rowLabel(__('Anonymous reads'))
                ->default(ApiSettings::DEFAULTS['api_public_reads'])
            ->textarea(
                'api_cors_origins',
                __('Allowed origins (CORS)'),
                __('Websites whose pages may call the API from a browser, one per line, such as https://app.example.com. Use * for any site; keyed calls still need an exact origin.')
            )
                ->sanitize(static fn ($value): string => self::origins((string) $value))
                ->validate(static fn ($value): ?string => self::badOrigin((string) $value))
                ->default(ApiSettings::DEFAULTS['api_cors_origins'])
            ->group(__('Rate limits'))
            ->number('api_rate_limit_default', __('Per key or signed-in user'))
                ->suffix($perMinute)
                ->required()
                ->clampMin(1)
                ->default(ApiSettings::DEFAULTS['api_rate_limit_default'])
            ->number('api_rate_limit_anon', __('Per address, without a key'))
                ->suffix($perMinute)
                ->required()
                ->clampMin(1)
                ->default(ApiSettings::DEFAULTS['api_rate_limit_anon'])
            ->number('api_rate_limit_write', __('Writes, per key or user'))
                ->suffix($perMinute)
                ->required()
                ->clampMin(1)
                ->default(ApiSettings::DEFAULTS['api_rate_limit_write'])
            ->number(
                'api_listing_rate',
                __('New listings, per user'),
                sprintf(
                    __('0 uses the default: %1$d, or %2$d while the listing form asks for a captcha.'),
                    ApiSettings::LISTINGS_PER_HOUR,
                    ApiSettings::LISTINGS_PER_HOUR_CAPTCHA
                )
            )
                ->suffix(__('an hour'))
                ->required()
                ->clampMin(0)
                ->default(ApiSettings::DEFAULTS['api_listing_rate'])
            ->group(__('Responses'))
            ->number('api_cache_max_age', __('Public cache time'), __('How long browsers and CDNs may keep an anonymous answer.'))
                ->suffix(__('seconds'))
                ->required()
                ->clampMin(0)
                ->default(ApiSettings::DEFAULTS['api_cache_max_age'])
            ->checkbox('api_hide_phone', __('Leave the seller phone number out of listings'), __('By default the API shows the phone wherever your theme does.'))
                ->rowLabel(__('Phone numbers'))
                ->default(ApiSettings::DEFAULTS['api_hide_phone'])
            ->checkbox('api_photo_urls', __('Let new listings name photos by web address'), __('The site downloads each photo itself. Only public addresses are fetched.'))
                ->rowLabel(__('Photos by address'))
                ->default(ApiSettings::DEFAULTS['api_photo_urls'])
            ->group(__('Users'))
            ->checkbox('api_user_keys', __('Let users make personal API keys'), __('Keys a user makes for their own scripts. Each one must expire within a year.'))
                ->rowLabel(__('Personal keys'))
                ->default(ApiSettings::DEFAULTS['api_user_keys'])
            ->checkbox('api_registration', __('Allow sign-up through the API'), __('New accounts get the same activation e-mail as the sign-up form sends.'))
                ->rowLabel(__('Registration'))
                ->default(ApiSettings::DEFAULTS['api_registration'])
            ->checkbox('api_password_grant', __('Allow apps to sign in with a password'), __('Lets your own mobile apps send a username and password to the token endpoint. Turn it off if no app needs it. Refresh tokens keep working.'))
                ->rowLabel(__('Password sign-in'))
                ->default(ApiSettings::DEFAULTS['api_password_grant'])
            ->group(__('Webhooks'))
            ->checkbox(
                'api_webhooks_allow_private',
                __('Allow webhook addresses on a private network'),
                __('Off by default. Turn on only to send webhooks to a server on your own network, such as http://192.168.1.20. The port is still 80 for http and 443 for https.')
            )
                ->rowLabel(__('Private addresses'))
                ->default(ApiSettings::DEFAULTS['api_webhooks_allow_private'])
            ->register();
    }

    /**
     * The origins list tidied: one per line, no blanks, no trailing slash, no repeats.
     */
    public static function origins(string $value): string
    {
        $lines = preg_split('/[\r\n,]+/', $value) ?: array();
        $lines = array_map(static fn (string $l): string => rtrim(trim($l), '/'), $lines);

        return implode("\n", array_values(array_unique(array_filter($lines, static fn (string $l): bool => $l !== ''))));
    }

    /**
     * The error for the first line that is not an origin, or null when all are.
     */
    public static function badOrigin(string $value): ?string
    {
        foreach (explode("\n", $value) as $line) {
            if ($line !== '' && $line !== '*' && preg_match(self::ORIGIN, $line) !== 1) {
                return sprintf(__('%s is not an origin. Write it as https://example.com, with no path.'), $line);
            }
        }

        return null;
    }
}
