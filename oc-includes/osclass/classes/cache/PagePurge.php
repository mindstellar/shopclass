<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\cache;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Clears a local nginx page cache with one PURGE request, as the Docker image's
 * `fastcgi_cache_purge PURGE purge_all` line expects. Failures are logged, never thrown.
 */
final class PagePurge
{
    /** @var HttpClientInterface|null */
    private $http;

    public function __construct(?HttpClientInterface $http = null)
    {
        $this->http = $http;
    }

    /**
     * Send PURGE to $url with the site's host.
     *
     * 200 means cleared; 404 and 412 mean nothing was cached. Anything else is logged.
     *
     * @param string $url  e.g. http://127.0.0.1/index.php
     * @param string $host the site's host name; empty keeps the URL's own
     *
     * @return bool whether the cache is now empty
     */
    public function purge(string $url, string $host): bool
    {
        $options = array('timeout' => 2, 'max_duration' => 2, 'max_redirects' => 0);
        if ($host !== '') {
            $options['headers'] = array('Host' => $host);
        }
        try {
            $response = ($this->http ?? HttpClient::create())->request('PURGE', $url, $options);
            $status   = $response->getStatusCode();
            unset($response);
        } catch (\Throwable $e) {
            error_log('Page cache purge to ' . $url . ' failed: ' . $e->getMessage());

            return false;
        }
        if ($status === 200 || $status === 404 || $status === 412) {
            return true;
        }
        error_log('Page cache purge to ' . $url . ' answered HTTP ' . $status);

        return false;
    }
}
