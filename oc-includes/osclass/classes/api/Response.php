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

namespace mindstellar\api;

use mindstellar\api\identity\WebIdentity;

/**
 * One API answer: a status, a JSON body and headers. Immutable, built as a value and sent
 * separately; it never sets a cookie, and send() removes any Set-Cookie queued elsewhere.
 */
final class Response
{
    public const JSON_TYPE    = 'application/json; charset=UTF-8';
    public const PROBLEM_TYPE = 'application/problem+json; charset=UTF-8';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_HEX_TAG | JSON_HEX_AMP;

    /** @var array<string,array{0:string,1:string}> lowercase name => [name, value] */
    private array $headers = [];

    private ?string $json = null;

    /**
     * @param array<string,mixed>|null $body    null sends no body
     * @param array<string,string>     $headers
     */
    public function __construct(private int $status, private ?array $body = null, array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    /**
     * One resource: `{"data": {...}}`.
     *
     * @param array<mixed>        $data
     * @param array<string,mixed> $extra more top-level members, e.g. `warnings`
     *
     * @api
     */
    public static function ok(array $data, int $status = 200, array $extra = []): self
    {
        return new self($status, ['data' => $data] + $extra);
    }

    /**
     * 201 for a new resource, with its address in Location.
     *
     * @param array<mixed>        $data
     * @param string              $location the new resource's URL
     * @param array<string,mixed> $extra    more top-level members, e.g. `warnings`
     *
     * @api
     */
    public static function created(array $data, string $location, array $extra = []): self
    {
        return self::ok($data, 201, $extra)->withHeader('Location', $location);
    }

    /**
     * A list: `{"data": [...], "meta": {...}, "links": {...}}`.
     *
     * @param array<int,mixed>      $items
     * @param array<string,mixed>   $meta
     * @param array<string,?string> $links
     *
     * @api
     */
    public static function collection(array $items, array $meta = [], array $links = ['next' => null]): self
    {
        $body = ['data' => array_values($items)];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }
        $body['links'] = $links;

        return new self(200, $body);
    }

    /** @api */
    public static function noContent(): self
    {
        return new self(204);
    }

    /** @api */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * @api
     *
     * @return array<string,mixed>|null
     */
    public function body(): ?array
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][1] ?? null;
    }

    /**
     * @return array<string,string> name => value
     */
    public function headers(): array
    {
        return array_column($this->headers, 1, 0);
    }

    public function isProblem(): bool
    {
        return $this->status >= 400;
    }

    /**
     * A copy with one header set. A Set-Cookie header is refused.
     *
     * @api
     */
    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->setHeader($name, $value);

        return $copy;
    }

    /**
     * A copy with each header set that is not set already.
     *
     * @param array<string,string> $headers
     */
    public function withDefaultHeaders(array $headers): self
    {
        $copy = clone $this;
        foreach ($headers as $name => $value) {
            if (!isset($copy->headers[strtolower($name)])) {
                $copy->setHeader($name, $value);
            }
        }

        return $copy;
    }

    /**
     * A copy with one top-level body member set.
     *
     * @api
     */
    public function withBodyMember(string $name, mixed $value): self
    {
        $copy              = clone $this;
        $copy->body        = $copy->body ?? [];
        $copy->body[$name] = $value;
        $copy->json        = null;

        return $copy;
    }

    /**
     * The body as JSON, encoded once for the length, the ETag and the wire.
     */
    private function json(): string
    {
        return $this->json ??= json_encode($this->body, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
    }

    /**
     * The ETag a GET of this answer carries; null unless it is a 200 with a body.
     */
    public function etag(): ?string
    {
        if ($this->status !== 200 || $this->body === null) {
            return null;
        }

        return $this->header('ETag') ?? osc_response_etag_value($this->json());
    }

    /**
     * A copy whose ETag is `"<version>.<body hash>"`: If-Match compares the stored version,
     * If-None-Match the whole tag. Unchanged unless it is a 200 with a body.
     */
    public function withVersion(string $version): self
    {
        if ($this->status !== 200 || $this->body === null) {
            return $this;
        }

        return $this->withHeader('ETag', '"' . $version . '.' . trim((string) osc_response_etag_value($this->json()), '"') . '"');
    }

    /**
     * Whether an If-Match header names this stored version: `*`, or a tag from withVersion(),
     * weak or strong. A proxy that compresses the answer (nginx gzip, Cloudflare) turns the
     * strong tag weak, and the version inside it is still exact, so `W/` is accepted.
     */
    public static function versionMatches(string $header, string $version): bool
    {
        $header = trim($header);
        if ($header === '*') {
            return true;
        }
        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);
            $tag = trim(str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag, '"');
            if (hash_equals($version, explode('.', $tag, 2)[0])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What send() would put on the wire. GET answers get core's ETag, and a matching
     * If-None-Match turns them into a 304 with no body.
     *
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function prepare(string $method = 'GET', string $ifNoneMatch = ''): array
    {
        $response = $this->withDefaultHeaders([
            'Content-Type'           => $this->isProblem() ? self::PROBLEM_TYPE : self::JSON_TYPE,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
        $status  = $this->status;
        $headers = $response->headers();
        $body    = $this->body === null ? '' : $this->json();

        $method = strtoupper($method);
        if (($method === 'GET' || $method === 'HEAD') && $status === 200 && $this->body !== null) {
            $etag            = $this->etag();
            $headers['ETag'] = $etag;
            if (self::etagMatches($ifNoneMatch, $etag)) {
                $status = 304;
                $body   = '';
                unset($headers['Content-Type']);
            }
        }
        if ($method === 'HEAD' || $status === 204) {
            $body = '';
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /**
     * Send it and stop. Output buffers are discarded first, so nothing printed earlier or
     * queued for shutdown can change the answer.
     */
    public function send(?Request $request = null): void
    {
        $out = $this->prepare($request?->method() ?? 'GET', $request?->ifNoneMatch() ?? '');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header_remove('Set-Cookie');
            foreach ($out['headers'] as $name => $value) {
                header($name . ': ' . $value);
            }
            // After the headers: PHP turns any answer carrying WWW-Authenticate into a 401.
            http_response_code($out['status']);
        }
        echo $out['body'];
        flush();
        self::tickAutoCron();
        exit;
    }

    /**
     * With auto-cron as the site's mode, lets the job queue run after the answer is sent.
     * A failure here never changes the response.
     */
    private static function tickAutoCron(): void
    {
        if (!function_exists('osc_auto_cron_dispatch')) {
            return;
        }
        try {
            // Scheduled tasks run as nobody, not as the caller the request signed in.
            WebIdentity::forget();
            osc_auto_cron_dispatch(true);
        } catch (\Throwable $e) {
            error_log('api: auto-cron tick failed: ' . $e->getMessage());
        }
    }

    /**
     * @param string $header `*` or a list of tags, weak or strong
     */
    public static function etagMatches(string $header, string $etag): bool
    {
        $header = trim($header);
        if ($header === '*') {
            return true;
        }
        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);
            if ((str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag) === $etag) {
                return true;
            }
        }

        return false;
    }

    private function setHeader(string $name, string $value): void
    {
        $key = strtolower($name);
        if ($key === 'set-cookie') {
            return;
        }
        // A header value cannot carry a line break.
        $this->headers[$key] = [$name, str_replace(["\r", "\n"], '', $value)];
    }
}
