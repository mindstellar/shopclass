<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\http;

use mindstellar\utility\Curl;

/**
 * The real client: HTTPS only, certificates checked, no redirects or cookies, short timeouts,
 * and an answer cut off past max_bytes.
 */
final class CurlHttpClient implements HttpClient
{
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT         = 15;
    public const MAX_BYTES       = 1048576;

    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

    /** @var \Closure(array<int,mixed>):array{ok:bool,status:int,errno:int} */
    private \Closure $transport;

    /**
     * @param callable|null $transport runs the cURL options and returns ok, status and errno; tests pass a fake
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = \Closure::fromCallable($transport ?? [self::class, 'curl']);
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): array
    {
        $method      = strtoupper($method);
        $max         = self::option($options, 'max_bytes', self::MAX_BYTES);
        $buffer      = new ResponseBuffer($max);
        $curlOptions = self::options($method, $url, $headers, $body, $options, $buffer);

        ['ok' => $ok, 'status' => $status, 'errno' => $errno] = ($this->transport)($curlOptions);

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($buffer->overflowed()) {
            throw new HttpException(sprintf('The answer from %s was larger than %d bytes', $host, $max));
        }
        if (!$ok || $status === 0) {
            throw new HttpException(sprintf('%s could not be reached (cURL error %d: %s)', $host, $errno, (string) curl_strerror($errno)));
        }

        return ['status' => $status, 'headers' => $buffer->headers(), 'body' => $buffer->body()];
    }

    /**
     * Run one request with cURL.
     *
     * @param array<int,mixed> $curlOptions
     *
     * @return array{ok:bool,status:int,errno:int}
     */
    public static function curl(array $curlOptions): array
    {
        if (!Curl::available()) {
            throw new HttpException('This server cannot make HTTP requests (no cURL)');
        }
        $curl = curl_init();
        curl_setopt_array($curl, $curlOptions);
        $ok     = curl_exec($curl) !== false;
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($curl);
        curl_close($curl);

        return ['ok' => $ok, 'status' => $status, 'errno' => $errno];
    }

    /**
     * The cURL options for one request; checked here, so a bad call fails before any connection.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>  $options
     *
     * @return array<int,mixed>
     * @throws \InvalidArgumentException
     */
    public static function options(string $method, string $url, array $headers, ?string $body, array $options, ResponseBuffer $buffer): array
    {
        $method = strtoupper($method);
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Unsupported HTTP method');
        }
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || (string) parse_url($url, PHP_URL_HOST) === '') {
            throw new \InvalidArgumentException('Only https:// URLs can be requested');
        }

        $lines = ['Expect:'];
        $agent = false;
        foreach ($headers as $name => $value) {
            $name  = (string) $name;
            $value = (string) $value;
            if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
                throw new \InvalidArgumentException('Invalid HTTP header');
            }
            $agent   = $agent || strtolower($name) === 'user-agent';
            $lines[] = $name . ': ' . $value;
        }
        if (!$agent) {
            $lines[] = 'User-Agent: Shopclass/' . (defined('OSCLASS_VERSION') ? OSCLASS_VERSION : '7');
        }

        $curl = [
            CURLOPT_URL               => $url,
            CURLOPT_CUSTOMREQUEST     => $method,
            CURLOPT_HTTPHEADER        => $lines,
            CURLOPT_PROTOCOLS         => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS   => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_MAXREDIRS         => 0,
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_SSL_VERIFYHOST    => 2,
            CURLOPT_NOSIGNAL          => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(self::seconds($options, 'connect_timeout', self::CONNECT_TIMEOUT) * 1000),
            CURLOPT_TIMEOUT_MS        => (int) round(self::seconds($options, 'timeout', self::TIMEOUT) * 1000),
            CURLOPT_HEADER            => false,
            CURLOPT_HEADERFUNCTION    => static fn ($handle, string $line): int => $buffer->header($line),
            CURLOPT_WRITEFUNCTION     => static fn ($handle, string $chunk): int => $buffer->append($chunk),
        ];
        if ($method === 'HEAD') {
            $curl[CURLOPT_NOBODY] = true;
        }
        if ($body !== null) {
            $curl[CURLOPT_POSTFIELDS] = $body;
        }

        return $curl;
    }

    /**
     * A positive whole-number option, or the default when it is not given.
     *
     * @param array<string,mixed> $options
     */
    private static function option(array $options, string $key, int $default): int
    {
        $value = $options[$key] ?? $default;
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException($key . ' must be a positive whole number');
        }

        return $value;
    }

    /**
     * A positive number of seconds, or the default when it is not given.
     *
     * @param array<string,mixed> $options
     */
    private static function seconds(array $options, string $key, int $default): float
    {
        $value = $options[$key] ?? $default;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value <= 0) {
            throw new \InvalidArgumentException($key . ' must be a positive number of seconds');
        }

        return (float) $value;
    }
}
