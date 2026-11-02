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

namespace mindstellar\webhook;

use mindstellar\security\AddressGuard;
use mindstellar\utility\Curl;

/**
 * The POST with cURL: pinned to the checked IP, never through a proxy, no redirects, short
 * timeouts, and at most MAX_RESPONSE bytes of the answer read before it is cut off.
 */
final class CurlTransport implements Transport
{
    public const CONNECT_TIMEOUT = 10;
    public const TIMEOUT         = 15;
    public const MAX_RESPONSE    = 65536;

    /** CURLE_OPERATION_TIMEDOUT. */
    private const TIMED_OUT = 28;

    public function post(string $url, string $ip, array $headers, string $body): TransportResult
    {
        if (!Curl::available()) {
            return TransportResult::failed('This server cannot make HTTP requests (no cURL).');
        }
        $lines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $read       = 0;
        $retryAfter = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, self::options($url, $ip) + [
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => $body,
            CURLOPT_HTTPHEADER    => $lines,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($line, 12));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$read): int {
                $read += strlen($chunk);

                return $read > self::MAX_RESPONSE ? 0 : strlen($chunk);
            },
        ]);
        $ok     = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($curl);
        curl_close($curl);

        // An answer cut off for its size still said its status.
        if ($status > 0) {
            return TransportResult::answered($status, self::seconds($retryAfter));
        }

        return TransportResult::failed($ok === false ? self::failure($errno) : 'No answer');
    }

    /**
     * A fixed status for a cURL error. cURL's own text can name internal hosts and addresses,
     * and the status is shown to admins and kept on the job queue.
     */
    /**
     * A Retry-After value (seconds or an HTTP date) as seconds from now; 0 when it is neither.
     */
    public static function seconds(string $value, ?int $now = null): int
    {
        if (preg_match('/^\d{1,9}$/', $value) === 1) {
            return (int) $value;
        }
        $at = $value === '' ? false : strtotime($value);

        return $at === false ? 0 : max(0, $at - ($now ?? time()));
    }

    public static function failure(int $errno): string
    {
        return $errno === self::TIMED_OUT ? 'Timed out' : 'Could not connect';
    }

    /**
     * @return array<int,mixed>
     */
    public static function options(string $url, string $ip): array
    {
        return AddressGuard::curlOptions($url, $ip) + [
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HEADER         => false,
        ];
    }
}
