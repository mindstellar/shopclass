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

use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\Scopes;

/**
 * One endpoint: method, path, handler, who may call it and what it accepts. The kernel
 * enforces these fields and the OpenAPI document is built from them; immutable.
 */
final class RouteSpec
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** No credential needed. */
    public const AUTH_NONE = 'none';
    /** Any credential, or none when the site allows anonymous reads. GET only. */
    public const AUTH_PUBLIC = 'public';
    /** A user's key or access token. */
    public const AUTH_USER = 'user';
    /** An admin's key. */
    public const AUTH_ADMIN = 'admin';

    public const AUTH = [self::AUTH_NONE, self::AUTH_PUBLIC, self::AUTH_USER, self::AUTH_ADMIN];

    private const PLACEHOLDER = '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#';

    private string $method;

    private string $path;

    /** @var callable|array{0:string,1:string} */
    private $handler;

    private ?\Closure $resolved = null;

    /** @var \Closure(class-string): object */
    private \Closure $handlers;

    private string $auth;

    private ?string $scope;

    private string $summary;

    private string $description;

    /** @var string[] */
    private array $tags;

    /** @var array<string,mixed>|null */
    private ?array $query;

    /** @var array<string,mixed>|null */
    private ?array $body;

    /** @var array<int|string,mixed> */
    private array $responses;

    private ?string $deprecated;

    private ?string $sunset;

    private bool $replayable;

    private bool $upload;

    private bool $oauth;

    private string $regex;

    /** @var string[] */
    private array $argNames;

    /** @var array<string,string> placeholder => its pattern, where one was given */
    private array $where = [];

    /**
     * @param string              $path     below the version, e.g. `listings/{id}`; '' is the version root
     * @param array<string,mixed> $spec     handler, auth (default public for GET, admin otherwise),
     *                                      scope, summary, description, tags, query
     *                                      (an object schema), body (a schema), responses
     *                                      (status => schema), deprecated and sunset (Y-m-d dates),
     *                                      replayable (false: a write whose answer holds a secret,
     *                                      so it is never stored for an Idempotency-Key),
     *                                      upload (true: the body is one file, multipart field
     *                                      `photo` or a raw image, not JSON),
     *                                      oauth (true: an OAuth 2 token endpoint, RFC 6749: the
     *                                      body may be a form too, and a refusal is a 400 with
     *                                      the OAuth `error` member),
     *                                      where (placeholder => regex for one segment, instead
     *                                      of digits for `{id}` and anything else otherwise)
     * @param \Closure|null       $handlers fn(class-string): object, builds the instance a
     *                                      `[class, method]` handler runs on; `new $class()` by default
     *
     * Only the shape is checked here, so building the core table loads no handler class and
     * walks no schema; check() does the rest for a route from a plugin.
     *
     * @throws \InvalidArgumentException when the route cannot be served
     */
    public function __construct(string $method, string $path, array $spec, ?\Closure $handlers = null)
    {
        $method = strtoupper($method);
        $path   = trim($path, '/');
        $key    = $method . ' ' . $path;
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException($key . ': unsupported method.');
        }
        if ($path !== '' && preg_match('#^[A-Za-z0-9_:{}-]+(?:\.[A-Za-z0-9_]+)?(?:/[A-Za-z0-9_:{}-]+(?:\.[A-Za-z0-9_]+)?)*$#D', $path) !== 1) {
            throw new \InvalidArgumentException($key . ': invalid path.');
        }
        $write = $method !== 'GET';
        $auth  = (string) ($spec['auth'] ?? ($write ? self::AUTH_ADMIN : self::AUTH_PUBLIC));
        if (!in_array($auth, self::AUTH, true)) {
            throw new \InvalidArgumentException($key . ': unknown auth level ' . $auth . '.');
        }
        if ($write && $auth === self::AUTH_PUBLIC) {
            throw new \InvalidArgumentException($key . ': a write cannot be public; use user, admin, or none for a throttled sign-in.');
        }
        $handler = $spec['handler'] ?? null;
        // A [class, method] pair is checked by shape: is_callable() would load the class.
        if (!self::isPair($handler) && !is_callable($handler)) {
            throw new \InvalidArgumentException($key . ': no handler.');
        }
        foreach (['query', 'body'] as $name) {
            if (isset($spec[$name]) && !is_array($spec[$name])) {
                throw new \InvalidArgumentException($key . ': ' . $name . ' schema: a schema must be an object.');
            }
        }

        $this->method      = $method;
        $this->path        = $path;
        $this->handler     = $handler;
        $this->handlers    = $handlers ?? static fn (string $class): object => new $class();
        $this->auth        = $auth;
        $this->scope       = isset($spec['scope']) && $spec['scope'] !== '' ? (string) $spec['scope'] : null;
        $this->summary     = (string) ($spec['summary'] ?? '');
        $this->description = (string) ($spec['description'] ?? '');
        $this->tags        = array_values(array_map('strval', (array) ($spec['tags'] ?? [])));
        $this->query       = isset($spec['query']) ? (array) $spec['query'] : null;
        $this->body        = isset($spec['body']) ? (array) $spec['body'] : null;
        $this->responses   = (array) ($spec['responses'] ?? []);
        $this->deprecated  = self::date($key, 'deprecated', $spec['deprecated'] ?? null);
        $this->sunset      = self::date($key, 'sunset', $spec['sunset'] ?? null);
        $this->replayable  = (bool) ($spec['replayable'] ?? true);
        $this->upload      = (bool) ($spec['upload'] ?? false);
        $this->oauth       = (bool) ($spec['oauth'] ?? false);
        if ($this->upload && $this->body !== null) {
            throw new \InvalidArgumentException($key . ': an upload takes a file, not a JSON body.');
        }
        if ($this->sunset !== null && $this->deprecated !== null && $this->sunset < $this->deprecated) {
            throw new \InvalidArgumentException($key . ': sunset comes before deprecated.');
        }

        preg_match_all(self::PLACEHOLDER, $path, $names);
        $this->argNames = $names[1];
        $where          = (array) ($spec['where'] ?? []);
        foreach ($where as $name => $pattern) {
            if (!in_array($name, $this->argNames, true) || !is_string($pattern) || @preg_match('#^(?:' . $pattern . ')$#D', '') === false) {
                throw new \InvalidArgumentException($key . ': where: ' . $name . ' is not a placeholder with a valid pattern.');
            }
        }
        $this->where = $where;
        $this->regex = '#^' . preg_replace_callback(
            '#\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}#',
            static fn (array $m): string => '(' . ($where[$m[1]] ?? ($m[1] === 'id' ? '[0-9]+' : '[^/]+')) . ')',
            preg_quote($path, '#')
        ) . '$#D';
    }

    /**
     * The spec of a read: by default a public one with the `listings:read` scope; one tag, a
     * query object and the schema a 200 answers with.
     *
     * @param array{0:class-string,1:string}      $handler
     * @param string                              $response the component a 200 answers with
     * @param array<string,array<string,mixed>>   $query    the query parameters' schemas
     * @param int[]                               $errors   problem statuses the route answers besides the usual ones
     * @param string                              $auth     who may call it
     * @param string|null                         $scope    the scope it needs
     *
     * @return array<string,mixed>
     */
    public static function read(
        array $handler,
        string $tag,
        string $summary,
        string $response,
        array $query = [],
        array $errors = [],
        string $auth = self::AUTH_PUBLIC,
        ?string $scope = Scopes::PUBLIC_READ
    ): array {
        $responses = [200 => Schema::ref($response)];
        foreach ($errors as $status) {
            $responses[$status] = Schema::ref('Problem');
        }

        return [
            'handler'   => $handler,
            'auth'      => $auth,
            'scope'     => $scope,
            'tags'      => [$tag],
            'summary'   => $summary,
            'query'     => ['type' => 'object', 'properties' => $query, 'additionalProperties' => false],
            'responses' => $responses,
        ];
    }

    /**
     * The spec of a write: who may call it, the body it takes and what a success answers.
     * A write cannot be public; the constructor refuses it.
     *
     * @param array{0:class-string,1:string} $handler
     * @param string                         $auth     AUTH_USER, AUTH_ADMIN, or AUTH_NONE for a throttled sign-in
     * @param string|null                    $body     the component the body must match, or null for none
     * @param string|null                    $response the component a success answers with, or null for no body
     * @param int[]                          $errors   problem statuses the route answers besides the usual ones
     * @param bool                           $replayable false when the answer holds a secret
     * @param bool                           $upload   true when the body is one file instead of JSON
     * @param bool                           $oauth    true for an OAuth 2 token endpoint
     *
     * @return array<string,mixed>
     */
    public static function write(
        array $handler,
        string $tag,
        string $summary,
        string $auth,
        ?string $scope,
        ?string $body = null,
        ?string $response = null,
        int $status = 200,
        array $errors = [],
        bool $replayable = true,
        bool $upload = false,
        bool $oauth = false
    ): array {
        $responses = [$status => $response === null ? ['type' => 'null'] : Schema::ref($response)];
        foreach ($errors as $code) {
            $responses[$code] = Schema::ref('Problem');
        }

        return [
            'handler'    => $handler,
            'auth'       => $auth,
            'scope'      => $scope,
            'tags'       => [$tag],
            'summary'    => $summary,
            'body'       => $body === null ? null : Schema::ref($body),
            'responses'  => $responses,
            'replayable' => $replayable,
            'upload'     => $upload,
            'oauth'      => $oauth,
        ];
    }

    /**
     * What the constructor leaves to a plugin route: its handler exists and its schemas use
     * only what the validator knows. Core routes are checked by the test suite instead.
     *
     * @throws \InvalidArgumentException naming the first problem
     */
    public function check(Validator $validator): void
    {
        if (self::isPair($this->handler) && !method_exists($this->handler[0], $this->handler[1])) {
            throw new \InvalidArgumentException($this->key() . ': no handler.');
        }
        $schemas = array_filter(['query' => $this->query, 'body' => $this->body] + $this->responses);
        foreach ($schemas as $name => $schema) {
            $problems = is_array($schema) ? $validator->schemaProblems($schema) : ['a schema must be an object'];
            if ($problems !== []) {
                throw new \InvalidArgumentException($this->key() . ': ' . $name . ' schema: ' . implode('; ', $problems) . '.');
            }
        }
    }

    /**
     * `GET listings/{id}`
     */
    public function key(): string
    {
        return $this->method . ' ' . $this->path;
    }

    /**
     * The path's arguments for a request path, or null when it does not match. `{id}`
     * matches digits, any other `{name}` one path segment. The request path is already
     * decoded, so the values are used as they are.
     *
     * @return array<string,string>|null
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->regex, $path, $m) !== 1) {
            return null;
        }

        return $this->argNames === [] ? [] : array_combine($this->argNames, array_slice($m, 1));
    }

    /**
     * Whether some request path could match both this route and $other, whatever their
     * methods. Tried with sample values for each placeholder, which tells a digits-only
     * `{id}` from a pattern that refuses digits.
     */
    public function overlaps(self $other): bool
    {
        foreach ([[$this, $other], [$other, $this]] as [$from, $to]) {
            foreach ($from->samples() as $path) {
                if ($to->match($path) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Request paths this route matches: each placeholder in turn takes every sample value its
     * pattern accepts, the others their first.
     *
     * @return string[]
     */
    private function samples(): array
    {
        $values = [];
        foreach ($this->argNames as $name) {
            $pattern = '#^(?:' . ($this->where[$name] ?? ($name === 'id' ? '[0-9]+' : '[^/]+')) . ')$#D';
            $values[$name] = array_values(array_filter(
                ['1', '42', 'a', 'abc', 'a1', '1a', 'A_b-c', 'a.b', 'a:b', '-', 'Z9'],
                static fn (string $value): bool => preg_match($pattern, $value) === 1
            ));
            if ($values[$name] === []) {
                return [];
            }
        }
        $first = array_map(static fn (array $list): string => $list[0], $values);
        $paths = [$this->fill($first)];
        foreach ($values as $name => $list) {
            foreach ($list as $value) {
                $paths[] = $this->fill([$name => $value] + $first);
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<string,string> $values placeholder => value
     */
    private function fill(array $values): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, static fn (array $m): string => $values[$m[1]], $this->path);
    }

    /**
     * Run the handler. A `[class, method]` pair whose method is not static runs on an
     * instance from the handler factory, built on the first call.
     *
     * @param array<string,string> $args
     */
    public function call(Request $request, Credential $credential, array $args): Response
    {
        if ($this->resolved === null) {
            $handler = $this->handler;
            if (self::isPair($handler)) {
                if (!method_exists($handler[0], $handler[1])) {
                    throw new \LogicException('API handler for ' . $this->key() . ' does not exist.');
                }
                if (!(new \ReflectionMethod($handler[0], $handler[1]))->isStatic()) {
                    $handler = [($this->handlers)($handler[0]), $handler[1]];
                }
            }
            $this->resolved = \Closure::fromCallable($handler);
        }
        $response = ($this->resolved)($request, $credential, $args);
        if (!$response instanceof Response) {
            throw new \UnexpectedValueException('API handler for ' . $this->key() . ' did not return a Response.');
        }

        return $response;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array{0:class-string,1:string}|callable
     */
    public function handler(): array|callable
    {
        return $this->handler;
    }

    public function auth(): string
    {
        return $this->auth;
    }

    public function scope(): ?string
    {
        return $this->scope;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * @return string[]
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function query(): ?array
    {
        return $this->query;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function body(): ?array
    {
        return $this->body;
    }

    /**
     * @return array<int|string,mixed>
     */
    public function responses(): array
    {
        return $this->responses;
    }

    /**
     * Whether an answer may be stored and replayed for an Idempotency-Key.
     */
    public function replayable(): bool
    {
        return $this->replayable;
    }

    /**
     * Whether the body is one uploaded file rather than JSON.
     */
    public function upload(): bool
    {
        return $this->upload;
    }

    /**
     * Whether this is an OAuth 2 token endpoint (RFC 6749).
     */
    public function oauth(): bool
    {
        return $this->oauth;
    }

    /**
     * The day the route was deprecated, `Y-m-d`, or null.
     */
    public function deprecated(): ?string
    {
        return $this->deprecated;
    }

    /**
     * The day the route stops answering, `Y-m-d`, or null.
     */
    public function sunset(): ?string
    {
        return $this->sunset;
    }

    /**
     * The path's placeholder names, in order.
     *
     * @return string[]
     */
    public function argNames(): array
    {
        return $this->argNames;
    }

    /**
     * A `Y-m-d` date from a spec, checked.
     */
    private static function date(string $key, string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException($key . ': ' . $field . ' must be a date, Y-m-d.');
        }

        return $value;
    }

    /**
     * A `[class, method]` pair of names.
     */
    private static function isPair(mixed $handler): bool
    {
        return is_array($handler) && count($handler) === 2 && is_string($handler[0] ?? null) && is_string($handler[1] ?? null);
    }
}
