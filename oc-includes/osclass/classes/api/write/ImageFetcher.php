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

namespace mindstellar\api\write;

use mindstellar\security\AddressGuard;

/**
 * Downloads a photo a listing names by URL. Only public http(s) addresses on their usual
 * ports are fetched, the connection is pinned to the checked IP and never goes through a
 * proxy, redirects are not followed, and the body stops at the site's photo size.
 */
final class ImageFetcher
{
    /** Seconds for the whole download. */
    public const TIMEOUT = 15;

    /** A download slower than LOW_SPEED bytes a second for LOW_SPEED_TIME seconds is dropped. */
    public const LOW_SPEED = 1024;
    public const LOW_SPEED_TIME = 5;

    /** @var \Closure(string, string, string, int, int): ?string */
    private \Closure $transport;

    /**
     * @param callable|null $transport (url, pinned ip, file, max bytes, seconds) => an error, or null
     *                                 once the file is written; cURL by default
     */
    public function __construct(private AddressGuard $guard, ?callable $transport = null)
    {
        $this->transport = \Closure::fromCallable($transport ?? [self::class, 'curl']);
    }

    public static function fromSite(): self
    {
        return new self(new AddressGuard());
    }

    /**
     * Download $url into $file within $timeout seconds.
     *
     * @return string|null why it was not fetched, or null on success
     */
    public function fetch(string $url, string $file, int $maxBytes, int $timeout = self::TIMEOUT): ?string
    {
        $check = $this->guard->check($url);
        if (!$check['ok']) {
            return (string) ($check['error'] ?? 'The address is not fetched.');
        }

        return ($this->transport)($url, (string) $check['ip'], $file, $maxBytes, max(1, min(self::TIMEOUT, $timeout)));
    }

    /**
     * An HTTP GET with cURL, connecting only to $ip.
     */
    public static function curl(string $url, string $ip, string $file, int $maxBytes, int $timeout = self::TIMEOUT): ?string
    {
        if (!function_exists('curl_init')) {
            return 'This server cannot download files.';
        }
        $out = @fopen($file, 'wb');
        if ($out === false) {
            return 'The temp folder is not writable.';
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, self::curlOptions($url, $ip, $maxBytes, $timeout) + [CURLOPT_FILE => $out]);
        $ok     = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        fclose($out);
        if ($ok === false) {
            return 'The photo could not be downloaded.';
        }

        return $status >= 200 && $status < 300 ? null : 'The address answered HTTP ' . $status . '.';
    }

    /**
     * The cURL options of a download, but for the file it writes to.
     *
     * @return array<int,mixed>
     */
    public static function curlOptions(string $url, string $ip, int $maxBytes, int $timeout = self::TIMEOUT): array
    {
        return AddressGuard::curlOptions($url, $ip) + [
            CURLOPT_CONNECTTIMEOUT   => min(5, $timeout),
            CURLOPT_TIMEOUT          => $timeout,
            CURLOPT_LOW_SPEED_LIMIT  => self::LOW_SPEED,
            CURLOPT_LOW_SPEED_TIME   => self::LOW_SPEED_TIME,
            CURLOPT_MAXFILESIZE      => $maxBytes,
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_XFERINFOFUNCTION => static fn ($c, $total, $now): int => $now > $maxBytes ? 1 : 0,
            CURLOPT_USERAGENT        => 'Shopclass/' . (defined('OSCLASS_VERSION') ? OSCLASS_VERSION : '7'),
        ];
    }
}
