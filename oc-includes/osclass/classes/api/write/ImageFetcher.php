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
 * Downloads the photos a listing names by URL, a few at a time. Only public http(s) addresses on their usual
 * ports are fetched, the connection is pinned to the checked IP and never goes through a
 * proxy, redirects are not followed, and the body stops at the site's photo size.
 */
final class ImageFetcher
{
    /** Seconds for each download. */
    public const TIMEOUT = 15;

    /** Downloads open at the same time; the next starts as one finishes. */
    public const CONCURRENT = 4;

    /** Why no download ran when the server has no cURL. */
    public const NO_CURL = 'This server cannot download files.';

    /** A download slower than LOW_SPEED bytes a second for LOW_SPEED_TIME seconds is dropped. */
    public const LOW_SPEED = 1024;
    public const LOW_SPEED_TIME = 5;

    /** Why a download failed, whatever the cause, so the answer says nothing about the far server. */
    public const FAILED = 'The photo could not be downloaded.';

    /** @var \Closure(array<int,array{url:string,ip:string,file:string}>, int, int): array<int,?string> */
    private \Closure $transport;

    /**
     * @param callable|null $transport (downloads, max bytes, seconds) => an error or null per download, once the
     *                                 files are written; each download is {url, pinned ip, file}. cURL by default
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
     * Download each URL into its file, a few at a time. Every address is checked first, and
     * none is downloaded when one is refused.
     *
     * @param array<int,string> $urls  key => URL
     * @param array<int,string> $files key => the file to write, for each URL
     *
     * @return array<int,string|null> key => why it was not fetched, or null on success; only the
     *                                refused keys when an address is refused
     */
    public function fetchAll(array $urls, array $files, int $maxBytes): array
    {
        $errors = [];
        $jobs   = [];
        foreach ($urls as $key => $url) {
            $check = $this->guard->check($url);
            if ($check['ok']) {
                $jobs[$key] = ['url' => $url, 'ip' => (string) $check['ip'], 'file' => $files[$key]];
            } else {
                $errors[$key] = (string) ($check['error'] ?? 'The address is not fetched.');
            }
        }
        if ($errors === [] && $jobs !== []) {
            $errors = ($this->transport)($jobs, $maxBytes, self::TIMEOUT);
        }
        // The transport answers in finishing order; callers report errors in request order.
        ksort($errors);

        return $errors;
    }

    /**
     * HTTP GETs with cURL, CONCURRENT at a time, each connecting only to its pinned IP.
     *
     * @param array<int,array{url:string,ip:string,file:string}> $jobs
     *
     * @return array<int,string|null>
     */
    public static function curl(array $jobs, int $maxBytes, int $timeout = self::TIMEOUT): array
    {
        if (!function_exists('curl_multi_init')) {
            return array_fill_keys(array_keys($jobs), self::NO_CURL);
        }
        $errors = [];
        $open   = [];
        $queue  = $jobs;
        $multi  = curl_multi_init();
        do {
            while (count($open) < self::CONCURRENT && $queue !== []) {
                $key = array_key_first($queue);
                $job = $queue[$key];
                unset($queue[$key]);
                $out = @fopen($job['file'], 'wb');
                if ($out === false) {
                    $errors[$key] = 'The temp folder is not writable.';
                    continue;
                }
                $curl = curl_init();
                curl_setopt_array($curl, self::jobOptions($job, $out, $maxBytes, $timeout));
                curl_multi_add_handle($multi, $curl);
                $open[spl_object_id($curl)] = [$key, $curl, $out];
            }
            $status = curl_multi_exec($multi, $running);
            while (($info = curl_multi_info_read($multi)) !== false) {
                [$key, $curl, $out] = $open[spl_object_id($info['handle'])];
                unset($open[spl_object_id($curl)]);
                $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $ok   = $info['result'] === CURLE_OK && $code >= 200 && $code < 300;
                curl_multi_remove_handle($multi, $curl);
                fclose($out);
                $errors[$key] = $ok ? null : self::FAILED;
            }
            if ($running > 0 && curl_multi_select($multi, 1.0) === -1) {
                usleep(10000);
            }
        } while ($status === CURLM_OK && ($open !== [] || $queue !== []));

        foreach ($open as [$key, $curl, $out]) {
            curl_multi_remove_handle($multi, $curl);
            fclose($out);
            $errors[$key] = self::FAILED;
        }
        foreach (array_keys($queue) as $key) {
            $errors[$key] = self::FAILED;
        }
        curl_multi_close($multi);

        return $errors;
    }

    /**
     * Every cURL option one download's handle gets.
     *
     * @param array{url:string,ip:string,file:string} $job
     * @param resource                                $out the open file it writes to
     *
     * @return array<int,mixed>
     */
    public static function jobOptions(array $job, $out, int $maxBytes, int $timeout = self::TIMEOUT): array
    {
        return [CURLOPT_URL => $job['url'], CURLOPT_FILE => $out] + self::curlOptions($job['url'], $job['ip'], $maxBytes, $timeout);
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
