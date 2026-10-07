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

use mindstellar\api\identity\SignInCookie;
use mindstellar\api\identity\WebIdentity;
use Params;

/**
 * One API request: method, path below /api/, query, headers, caller's address and body.
 * Immutable; withQuery() returns a changed copy.
 *
 * Built from the server globals in production and by hand in tests. No cookie is read but
 * the web user's sign-in cookie, which only the same-site session mode looks at.
 */
final class Request
{
    /** The largest body the API reads. */
    public const MAX_BODY = 1048576;

    /** The JSON Merge Patch type (RFC 7396) a PATCH body may be sent as. */
    public const MERGE_PATCH = 'application/merge-patch+json';

    /** The form encoding an OAuth token request may use. */
    public const FORM = 'application/x-www-form-urlencoded';

    /** The largest raw image body the API reads, for a photo upload. */
    public const MAX_UPLOAD = 16777216;

    /** How deep a JSON body may nest. */
    public const MAX_DEPTH = 32;

    private string $method;

    /** Decoded segments joined by '/', or null when the path cannot be served. */
    private ?string $path;

    /** @var array<string,string> lowercase name => value */
    private array $headers = [];

    /** The most body bytes read: MAX_BODY, or MAX_UPLOAD once the route takes an upload. */
    private int $cap = self::MAX_BODY;

    /**
     * @param string|null          $path    below /api/, already decoded; null for a path that was refused
     * @param array<string,mixed>  $query
     * @param array<string,string> $headers name => value, any case
     * @param string|null          $body    null when it was larger than MAX_BODY (MAX_UPLOAD for an upload)
     * @param array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     *                                      uploaded files by form field, one file each
     * @param \Closure|null        $reader  fn(int $cap): ?string, reads the body on first use in
     *                                      place of $body; null when it is larger than $cap
     * @param SignInCookie|null   $signIn  the web user's sign-in cookie, unchecked
     */
    public function __construct(
        string $method,
        ?string $path,
        private array $query = [],
        array $headers = [],
        private string $ip = '',
        private ?string $body = '',
        private array $files = [],
        private ?\Closure $reader = null,
        private ?SignInCookie $signIn = null
    ) {
        $this->method = strtoupper($method);
        $segments     = $path === null ? null : self::segments($path, false);
        $this->path   = $segments === null ? null : implode('/', $segments);
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    /**
     * The current request. The query is read as sent: the schemas check it, values reach SQL
     * only bound or escaped, and the search pattern is stripped of tags where it is parsed.
     */
    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_') && $key !== 'HTTP_COOKIE') {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT-TYPE'] = $_SERVER['CONTENT_TYPE'];
        }
        // Apache hides Authorization from PHP unless .htaccess copies it into the environment.
        if (($headers['AUTHORIZATION'] ?? '') === '' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['AUTHORIZATION'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $query = self::repeatedKeys((string) Params::getServerParam('QUERY_STRING', false, false)) + Params::getParamsAsArray('get', false);
        unset($query['page'], $query['path']);

        return new self(
            (string) Params::getServerParam('REQUEST_METHOD', false, false),
            self::pathFromUri(
                (string) Params::getServerParam('REQUEST_URI', false, false),
                defined('REL_WEB_URL') ? (string) REL_WEB_URL : '/',
                is_string($_GET['path'] ?? null) ? $_GET['path'] : ''
            ),
            $query,
            $headers,
            (string) Params::getServerParam('REMOTE_ADDR', false, false),
            null,
            self::uploadedFiles($_FILES),
            static function (int $cap): ?string {
                // One byte past the cap is enough to know the body is too large, without reading it all.
                $stream = fopen('php://input', 'rb');
                $body   = $stream ? stream_get_contents($stream, $cap + 1) : '';
                if ($stream) {
                    fclose($stream);
                }

                return is_string($body) && strlen($body) <= $cap ? $body : null;
            },
            WebIdentity::signInCookie()
        );
    }

    /**
     * The single-file entries of $_FILES; a field holding several files is left out.
     *
     * @param array<mixed> $files
     *
     * @return array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public static function uploadedFiles(array $files): array
    {
        $out = [];
        foreach ($files as $field => $file) {
            if (is_string($field) && is_array($file) && is_string($file['tmp_name'] ?? null) && is_int($file['error'] ?? null)) {
                $out[$field] = [
                    'name'     => (string) ($file['name'] ?? ''),
                    'type'     => (string) ($file['type'] ?? ''),
                    'tmp_name' => $file['tmp_name'],
                    'error'    => $file['error'],
                    'size'     => (int) ($file['size'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /**
     * The API path of a request: read from the raw URI and decoded once per segment for
     * /api/..., else the `path` query value (already decoded by PHP) for index.php?page=api.
     *
     * @return string|null null when a segment is empty, `.`, `..`, or decodes to a '/' or NUL
     */
    public static function pathFromUri(string $uri, string $base, string $pathParam): ?string
    {
        $path = explode('?', $uri, 2)[0];
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        if ($path === 'api' || str_starts_with($path, 'api/')) {
            $segments = self::segments(substr($path, 4), true);
        } else {
            $segments = self::segments($pathParam, false);
        }

        return $segments === null ? null : implode('/', $segments);
    }

    /**
     * @return string[]|null
     */
    private static function segments(string $path, bool $encoded): ?array
    {
        $path = trim($path, '/');
        if ($path === '') {
            return [];
        }
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($encoded) {
                $segment = rawurldecode($segment);
            }
            if ($segment === '' || $segment === '.' || $segment === '..'
                || str_contains($segment, '/') || str_contains($segment, "\0")
            ) {
                return null;
            }
            $out[] = $segment;
        }

        return $out;
    }

    public function method(): string
    {
        return $this->method;
    }

    /**
     * Below /api/, e.g. `v1/listings/12`; null when the path was refused.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    /**
     * The web user's sign-in cookie as sent, unchecked; null when there was none.
     */
    public function signIn(): ?SignInCookie
    {
        return $this->signIn;
    }

    /**
     * The raw body; null when it is larger than the cap. Read on first use.
     */
    public function body(): ?string
    {
        if ($this->reader !== null) {
            $this->body   = ($this->reader)($this->cap);
            $this->reader = null;
        }

        return $this->body;
    }

    /**
     * A copy that may read an upload's body, up to MAX_UPLOAD instead of MAX_BODY. Only an
     * upload route asks for it, so no other request makes the server read 16 MB.
     */
    public function forUpload(): self
    {
        $copy      = clone $this;
        $copy->cap = self::MAX_UPLOAD;

        return $copy;
    }

    /**
     * An uploaded file by its form field, or null.
     *
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
     */
    public function file(string $field): ?array
    {
        return $this->files[$field] ?? null;
    }

    /**
     * @return array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return array<string,mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @param array<string,mixed> $query
     */
    public function withQuery(array $query): self
    {
        $copy        = clone $this;
        $copy->query = $query;

        return $copy;
    }

    /**
     * @return string '' when absent
     */
    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function authorization(): string
    {
        return trim($this->header('Authorization'));
    }

    /**
     * The media type of the body, lowercase, without parameters.
     */
    public function contentType(): string
    {
        return strtolower(trim(explode(';', $this->header('Content-Type'))[0]));
    }

    public function ifNoneMatch(): string
    {
        return $this->header('If-None-Match');
    }

    public function ifMatch(): string
    {
        return $this->header('If-Match');
    }

    /**
     * A copy as the GET of the same path, with no query, body or conditional headers.
     */
    public function asRead(): self
    {
        $copy          = clone $this;
        $copy->method  = 'GET';
        $copy->query   = [];
        $copy->body    = '';
        $copy->reader  = null;
        unset($copy->headers['if-match'], $copy->headers['if-none-match']);

        return $copy;
    }

    public function idempotencyKey(): string
    {
        return trim($this->header('Idempotency-Key'));
    }

    public function isRead(): bool
    {
        return $this->method === 'GET' || $this->method === 'HEAD';
    }

    public function isWrite(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * The body as a JSON object.
     *
     * @return array<mixed>
     * @throws ProblemException 413, 415 or 400 when the body cannot be used
     */
    public function json(): array
    {
        $body = $this->body();
        if ($body === null) {
            throw ProblemException::of('too_large', 'The body is larger than 1 MB.');
        }
        $type = $this->contentType();
        if ($type !== 'application/json' && !($type === self::MERGE_PATCH && $this->method === 'PATCH')) {
            throw $this->method === 'PATCH'
                ? ProblemException::from(
                    Problem::make('unsupported_media_type', 'Send the body as application/json or ' . self::MERGE_PATCH . '.')
                        ->withHeader('Accept-Patch', self::MERGE_PATCH)
                )
                : ProblemException::of('unsupported_media_type', 'Send the body as application/json.');
        }
        try {
            $data = json_decode($body, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ProblemException::of('invalid_json', 'The body is not valid JSON: ' . $e->getMessage() . '.');
        }
        if (!is_array($data)) {
            throw ProblemException::of('invalid_json', 'The body must be a JSON object.');
        }

        return $data;
    }

    /**
     * A copy whose application/x-www-form-urlencoded body reads as JSON, as an OAuth token
     * request sends it (RFC 6749 §4.3). Any other body is left as it is.
     *
     * @throws ProblemException 413 when the body is too large
     */
    public function withFormAsJson(): self
    {
        if ($this->contentType() !== self::FORM) {
            return $this;
        }
        $body = $this->body();
        if ($body === null) {
            throw ProblemException::of('too_large', 'The body is larger than 1 MB.');
        }
        parse_str($body, $fields);
        $copy                          = clone $this;
        $copy->body                    = json_encode((object) $fields, JSON_THROW_ON_ERROR);
        $copy->headers['content-type'] = 'application/json';

        return $copy;
    }

    /**
     * The body as a JSON object; no body at all reads as an empty object.
     *
     * @return array<mixed>
     * @throws ProblemException 413, 415 or 400 when a body was sent that cannot be used
     */
    public function input(): array
    {
        return $this->body() === '' ? [] : $this->json();
    }

    /**
     * A query value as a string. An array yields $default.
     */
    public function queryString(string $name, string $default = ''): string
    {
        $value = $this->query[$name] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function queryInt(string $name, int $default = 0): int
    {
        $value = $this->query[$name] ?? null;

        return is_scalar($value) ? (int) $value : $default;
    }

    /**
     * `1/0`, `true/false`, `on/off`, `yes/no`; anything else yields $default.
     */
    public function queryBool(string $name, bool $default = false): bool
    {
        $value = $this->query[$name] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Keys sent more than once without brackets (`?city=1&city=2`), each as its list of values.
     * PHP's own parsing keeps only the last one.
     *
     * @return array<string,string[]>
     */
    public static function repeatedKeys(string $queryString): array
    {
        $seen = [];
        foreach ($queryString === '' ? [] : explode('&', $queryString) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if ($key !== '' && !str_contains($key, '[')) {
                $seen[$key][] = urldecode($value);
            }
        }

        return array_filter($seen, static fn (array $values): bool => count($values) > 1);
    }

    /**
     * A repeatable query value: `a[]=1&a[]=2` or a comma list `a=1,2`.
     *
     * @return string[]
     */
    public function queryList(string $name): array
    {
        $value  = $this->query[$name] ?? [];
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $out    = [];
        foreach ($values as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim((string) $item);
            }
        }

        return $out;
    }

    /**
     * queryList() kept to row ids: digits only, as integers.
     *
     * @return int[]
     */
    public function queryIds(string $name): array
    {
        return array_map('intval', array_values(array_filter($this->queryList($name), 'ctype_digit')));
    }
}
