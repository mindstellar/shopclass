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
use mindstellar\api\ApiSettings;
use mindstellar\api\auth\Credential;
use mindstellar\api\Kernel;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\Format;
use mindstellar\api\serializer\LocationSerializer;

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

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        // Built per request, not cached: every value is a preference already in memory, and a
        // cached copy would outlive a settings change.
        $facts   = $this->api->facts();
        $locales = [];
        foreach ($facts->locales() as $code => $locale) {
            $locales[] = ['code' => $code, 'name' => $locale['name'], 'direction' => $locale['direction']];
        }
        $links = [];
        foreach (['listings', 'categories', 'countries', 'currencies', 'fields', 'openapi.json'] as $path) {
            $links[basename($path, '.json')] = $this->api->links()->api($path);
        }
        $settings = $this->settings;
        $site     = [
            'name'           => (string) osc_page_title(),
            'description'    => Format::text(osc_page_description()),
            'url'            => (string) osc_base_url(),
            'version'        => OSCLASS_VERSION,
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
                'public_reads' => $facts->publicReads(),
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

    /**
     * @param array<string,string> $args
     */
    public function currencies(Request $request, Credential $credential, array $args): Response
    {
        $rows = $this->api->currencyService()->enabled();
        $serializer = new LocationSerializer();

        return Response::collection(array_map([$serializer, 'currency'], $rows));
    }
}
