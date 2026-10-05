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

namespace mindstellar\api\http;

use mindstellar\api\ApiSettings;
use mindstellar\api\auth\ApiKeys;
use mindstellar\api\auth\Authenticator;
use mindstellar\api\Request;
use mindstellar\api\Response;

/**
 * Cross-origin access for browser apps.
 *
 * The site lists allowed origins (preference `api_cors_origins`, one per line, plus the
 * `api_cors_origins` filter). `*` admits any page, but only for calls with no key or a
 * public key; a call with any other key is answered for an exactly listed origin only.
 * Credentials (cookies) are never allowed, and the page token header of the same-site session
 * mode is not an allowed header, so a page on another site can never make a session call.
 */
final class Cors
{
    public const ALLOW_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public const ALLOW_HEADERS = ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'If-None-Match', 'If-Match', 'Request-Id', 'X-Request-Id'];

    public const EXPOSE_HEADERS = [
        'ETag', 'Location', 'Retry-After', 'Deprecation', 'Sunset', 'Link', 'Idempotency-Replayed', 'Accept-Patch',
        'RateLimit-Policy', 'RateLimit', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Request-Id',
    ];

    /** Seconds a browser may reuse a preflight answer. */
    public const MAX_AGE = 600;

    /** @var array<string,true> lowercase origin => true */
    private array $exact = [];

    private bool $any = false;

    /**
     * @param string[] $origins `https://app.example.com`, or `*`
     */
    public function __construct(array $origins)
    {
        foreach ($origins as $origin) {
            $origin = strtolower(rtrim(trim((string) $origin), '/'));
            if ($origin === '*') {
                $this->any = true;
            } elseif ($origin !== '') {
                $this->exact[$origin] = true;
            }
        }
    }

    /**
     * The site's list: the preference, then the `api_cors_origins` filter.
     */
    public static function fromSettings(ApiSettings $settings, Request $request): self
    {
        $origins = preg_split('/[\r\n]+/', $settings->corsOrigins()) ?: [];
        $origins = osc_apply_filter('api_cors_origins', $origins, $request);

        return new self(is_array($origins) ? $origins : []);
    }

    /**
     * Whether any origin is allowed, so answers depend on the Origin header.
     */
    public function enabled(): bool
    {
        return $this->any || $this->exact !== [];
    }

    /**
     * The Access-Control-Allow-Origin value for a request, or null to send none.
     */
    public function allowedOrigin(Request $request): ?string
    {
        $origin = trim($request->header('Origin'));
        if ($origin === '') {
            return null;
        }
        if (isset($this->exact[strtolower($origin)])) {
            return $origin;
        }

        return $this->any && !self::keyed($request) ? '*' : null;
    }

    /**
     * Headers for an actual (non-preflight) answer.
     *
     * @return array<string,string>
     */
    public function headers(Request $request): array
    {
        $origin = $this->allowedOrigin($request);
        if ($origin === null) {
            return [];
        }

        return ['Access-Control-Allow-Origin' => $origin, 'Access-Control-Expose-Headers' => implode(', ', self::EXPOSE_HEADERS)];
    }

    /**
     * The answer to an OPTIONS request: 204 with what the path allows. A preflight from an
     * allowed origin also gets the CORS grant; it carries no credential, so `*` answers it
     * and the actual call is held to the stricter rule.
     *
     * @param string[] $methods the methods the path answers
     */
    public function preflight(Request $request, array $methods): Response
    {
        $methods  = array_values(array_unique(array_merge($methods, ['OPTIONS'])));
        $response = new Response(204, null, ['Allow' => implode(', ', $methods)]);
        $origin   = trim($request->header('Origin'));
        if ($request->header('Access-Control-Request-Method') === '' || $origin === '') {
            return $response;
        }
        $allowed = isset($this->exact[strtolower($origin)]) ? $origin : ($this->any ? '*' : null);
        if ($allowed === null) {
            return $response;
        }

        return $response->withDefaultHeaders([
            'Access-Control-Allow-Origin'  => $allowed,
            'Access-Control-Allow-Methods' => implode(', ', array_values(array_intersect(self::ALLOW_METHODS, $methods))),
            'Access-Control-Allow-Headers' => implode(', ', self::ALLOW_HEADERS),
            'Access-Control-Max-Age'       => (string) self::MAX_AGE,
        ]);
    }

    /**
     * Whether the request carries a key other than a public one.
     */
    private static function keyed(Request $request): bool
    {
        $token = Authenticator::token($request);

        return $token !== '' && !str_starts_with($token, ApiKeys::PUBLIC_PREFIX);
    }
}
