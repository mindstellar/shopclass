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
use mindstellar\utility\Curl;

/**
 * Downloads the photos a listing names by URL, a few at a time. Only public http(s) addresses on their usual
 * ports are fetched, the connection is pinned to the checked IP and never goes through a
 * proxy, redirects are not followed, and the body stops at the site's photo size.
 */
final class ImageFetcher
{
    /** Seconds for each download. */
    public const TIMEOUT = 15;

    /** Seconds for the whole batch. */
    public const BUDGET = 30;

    /** Downloads open at the same time; the next starts as one finishes. */
    public const CONCURRENT = 4;

    /** Why no download ran when the server has no cURL. */
    public const NO_CURL = 'This server cannot download files.';

    /** A download slower than LOW_SPEED bytes a second for LOW_SPEED_TIME seconds is dropped. */
    public const LOW_SPEED = 1024;
    public const LOW_SPEED_TIME = 5;

    /** Why a download failed, whatever the cause, so the answer says nothing about the far server. */
    public const FAILED = 'The photo could not be downloaded.';

    /** Why an address was refused, whatever the reason, so the answer says nothing about how it resolves. */
    public const REFUSED = 'The address is not one the site downloads from.';

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
                $errors[$key] = self::REFUSED;
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
     * HTTP GETs with cURL, CONCURRENT at a time, each connecting only to its pinned IP. What is
     * still open or queued after $budget seconds fails.
     *
     * @param array<int,array{url:string,ip:string,file:string}> $jobs
     *
     * @return array<int,string|null>
     */
    public static function curl(array $jobs, int $maxBytes, int $timeout = self::TIMEOUT, int $budget = self::BUDGET): array
    {
        if (!Curl::available(true)) {
            return array_fill_keys(array_keys($jobs), self::NO_CURL);
        }
        $deadline = microtime(true) + $budget;
        $errors   = [];
        $open     = [];
        $queue    = $jobs;
        $multi    = curl_multi_init();
        do {
            while (count($open) < self::CONCURRENT && $queue !== [] && microtime(true) < $deadline) {
                $key = (int) array_key_first($queue);
                $job = $queue[$key];
                unset($queue[$key]);
                $left    = (int) ceil($deadline - microtime(true));
                $started = self::start($multi, $job, $maxBytes, max(1, min($timeout, $left)));
                if ($started === null) {
                    $errors[$key] = 'The temp folder is not writable.';
                } else {
                    $open[spl_object_id($started[0])] = [$key, ...$started];
                }
            }
            $status = curl_multi_exec($multi, $running);
            while (($info = curl_multi_info_read($multi)) !== false) {
                [$key, $curl, $out] = $open[spl_object_id($info['handle'])];
                unset($open[spl_object_id($curl)]);
                $errors[$key] = self::finish($multi, $curl, $out, $info['result'] === CURLE_OK) ? null : self::FAILED;
            }
            if ($running > 0 && curl_multi_select($multi, 1.0) === -1) {
                usleep(10000);
            }
        } while ($status === CURLM_OK && ($open !== [] || $queue !== []) && microtime(true) < $deadline);

        foreach ($open as [$key, $curl, $out]) {
            self::finish($multi, $curl, $out, false);
            $errors[$key] = self::FAILED;
        }
        foreach (array_keys($queue) as $key) {
            $errors[$key] = self::FAILED;
        }
        curl_multi_close($multi);

        return $errors;
    }

    /**
     * Open the job's file and add its handle to $multi.
     *
     * @param array{url:string,ip:string,file:string} $job
     *
     * @return array{0:\CurlHandle,1:resource}|null null when the file cannot be opened
     */
    private static function start(\CurlMultiHandle $multi, array $job, int $maxBytes, int $timeout): ?array
    {
        $out = @fopen($job['file'], 'wb');
        if ($out === false) {
            return null;
        }
        $curl = curl_init();
        curl_setopt_array($curl, self::curlOptions($job['url'], $job['ip'], $maxBytes, $out, $timeout));
        curl_multi_add_handle($multi, $curl);

        return [$curl, $out];
    }

    /**
     * Take a handle off $multi and close its file.
     *
     * @param resource $out
     *
     * @return bool whether it downloaded with a 2xx answer
     */
    private static function finish(\CurlMultiHandle $multi, \CurlHandle $curl, $out, bool $done): bool
    {
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $curl);
        fclose($out);

        return $done && $code >= 200 && $code < 300;
    }

    /**
     * Every cURL option of a download's handle.
     *
     * @param resource $out the open file it writes to
     *
     * @return array<int,mixed>
     */
    public static function curlOptions(string $url, string $ip, int $maxBytes, $out, int $timeout = self::TIMEOUT): array
    {
        return [CURLOPT_URL => $url, CURLOPT_FILE => $out] + AddressGuard::curlOptions($url, $ip) + [
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
