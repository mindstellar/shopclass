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

namespace mindstellar\api\controller;

use mindstellar\api\ApiServices;
use mindstellar\api\Kernel;
use mindstellar\api\Response;
use mindstellar\api\serializer\Format;
use mindstellar\api\serializer\LocationSerializer;
use mindstellar\apiaccess\ApiSettings;

/**
 * `GET /`: what the site is, what the API allows on it and where its collections are.
 * `GET /currencies`.
 */
final class SiteController
{
    private ApiSettings $settings;

    public function __construct(private ApiServices $api)
    {
        $this->settings = $api->settings();
    }

    public function show(): Response
    {
        // Built per request, not cached: every value is a preference already in memory, and a
        // cached copy would outlive a settings change.
        $facts   = $this->api->facts();
        $locales = [];
        foreach ($facts->locales() as $code => $locale) {
            $locales[] = ['code' => $code, 'name' => $locale['name'], 'direction' => $locale['direction']];
        }
        $links = [];
        foreach (['listings' => 'listings', 'categories' => 'categories', 'countries' => 'countries', 'currencies' => 'currencies', 'custom_fields' => 'custom-fields', 'openapi' => 'openapi.json'] as $name => $path) {
            $links[$name] = $this->api->links()->api($path);
        }
        $settings = $this->settings;
        $site     = [
            'name'           => (string) osc_page_title(),
            'description'    => Format::text(osc_page_description()),
            'url'            => (string) osc_base_url(),
            'api_version'    => Kernel::VERSION,
            'default_locale' => $facts->defaultLocale(),
            'locales'        => $locales,
            'currency'       => Format::text(osc_currency()),
            'timezone'       => (string) osc_timezone(),
            'friendly_urls'  => (bool) osc_rewrite_enabled(),
            'features'       => [
                'users'        => $facts->usersEnabled(),
                'registration' => (bool) osc_user_registration_enabled(),
                'comments'     => $facts->commentsEnabled(),
            ],
            'api'            => [
                'registration'  => $settings->registration() && $facts->usersEnabled() && (bool) osc_user_registration_enabled(),
                'personal_keys' => $settings->userKeys(),
                'photo_urls'    => $settings->photoUrls(),
                'public_reads'  => $settings->publicReads(),
            ],
            'links'          => $links,
        ];

        return Response::ok($site);
    }

    public function currencies(): Response
    {
        $rows = $this->api->currencyService()->enabled();
        $serializer = new LocationSerializer();

        return Response::collection(array_map([$serializer, 'currency'], $rows));
    }
}
