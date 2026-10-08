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

use mindstellar\api\Request;

/**
 * The site's own origin (scheme, host and port of its base URL), and whether a request came from
 * one of the site's own pages. A browser sets Origin and Sec-Fetch-Site itself, so a read may also
 * show it with Sec-Fetch-Site or Referer; a request that shows nothing is not from the site.
 */
final class SiteOrigin
{
    private string $origin;

    /**
     * @param string $baseUrl the site's base URL, e.g. `https://example.com/shop/`
     */
    public function __construct(string $baseUrl)
    {
        $this->origin = self::of($baseUrl) ?? '';
    }

    /**
     * The site's own origin, from osc_base_url().
     */
    public static function fromSite(): self
    {
        return new self(osc_base_url());
    }

    /**
     * `scheme://host[:port]` of a URL, lowercase, without the scheme's default port; null for
     * anything that is not an http or https URL with a host.
     */
    public static function of(string $url): ?string
    {
        $parts  = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }

        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
    }

    /**
     * Whether the request came from a page of this site.
     */
    public function matches(Request $request): bool
    {
        if ($this->origin === '') {
            return false;
        }
        $origin = trim($request->header('Origin'));
        $fetch  = strtolower(trim($request->header('Sec-Fetch-Site')));
        if ($fetch !== '' && $fetch !== 'same-origin') {
            return false;
        }
        if ($origin !== '') {
            // An Origin is scheme, host and port only; anything more did not come from a browser.
            return preg_match('#^https?://[^/?\#@\s]+$#iD', $origin) === 1 && self::of($origin) === $this->origin;
        }
        if ($fetch === 'same-origin') {
            return true;
        }
        $referer = trim($request->header('Referer'));

        return $request->isRead() && $referer !== '' && self::of($referer) === $this->origin;
    }
}
