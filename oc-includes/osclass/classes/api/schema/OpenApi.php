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

namespace mindstellar\api\schema;

use mindstellar\api\idempotency\Idempotency;
use mindstellar\api\Kernel;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\RouteSpec;
use mindstellar\api\routing\Router;
use mindstellar\api\routing\RouteTable;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\PageTokens;
use mindstellar\apiaccess\Scopes;
use mindstellar\webhook\Events;

/**
 * The API described in OpenAPI 3.1, built from the route table and the component schemas,
 * so the document cannot promise what the kernel does not serve. Served live at
 * GET /api/v1/openapi.json (with the site's plugins) and written for core alone to
 * docs/site/developers/api/openapi.json by tools/gen-openapi.php.
 */
final class OpenApi
{
    public const SPEC_VERSION = '3.1.0';

    private const STATUS_TEXT = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No content', 301 => 'Moved permanently',
        304 => 'Not modified', 308 => 'Moved permanently, same method', 400 => 'Bad request',
        401 => 'No valid credential', 403 => 'Not allowed for this credential', 404 => 'Not found',
        409 => 'Conflict', 412 => 'Precondition failed', 413 => 'Body too large', 415 => 'Unsupported content type',
        422 => 'Not valid', 429 => 'Too many requests', 500 => 'Server error', 503 => 'Maintenance',
    ];

    /** What each core tag groups, for the reference. */
    private const TAGS = [
        'Account'         => 'The signed-in user: profile, alerts, keys and sessions.',
        'Admin comments'  => 'Moderating comments.',
        'Admin listings'  => 'Moderating and editing any listing.',
        'Admin settings'  => 'The settings an admin key may read and change.',
        'Admin taxonomy'  => 'Categories, custom fields, locations and currencies.',
        'Admin users'     => 'Users and their sessions.',
        'Admin webhooks'  => 'Webhook endpoints and their deliveries.',
        'Auth'            => 'Signing in and out, and tokens.',
        'Categories'      => 'The category tree.',
        'Listings'        => 'Listings, their photos and comments.',
        'Locations'       => 'Countries, regions and cities.',
        'Meta'            => 'This description.',
        'Site'            => 'The site, its locales and its collections.',
        'Users'           => 'Public user profiles and their listings.',
    ];

    /** The response headers, described once under components.headers. */
    private const HEADERS = [
        'ETag'             => ['description' => 'The answer\'s version. Send it back in If-None-Match for a 304, or in If-Match on a PATCH or DELETE. A PATCH sent with If-Match answers with the new one.', 'schema' => ['type' => 'string']],
        'RateLimit'        => ['description' => 'The tightest limit this call counted against: its name, requests left (r) and seconds to reset (t).', 'schema' => ['type' => 'string']],
        'RateLimit-Policy' => ['description' => 'Every limit this call counted against.', 'schema' => ['type' => 'string']],
        'Retry-After'      => ['description' => 'Seconds to wait before trying again.', 'schema' => ['type' => 'integer']],
        'Deprecation'      => ['description' => 'When the operation was deprecated, as @<unix time> (RFC 9745).', 'schema' => ['type' => 'string']],
        'Sunset'           => ['description' => 'When the operation stops working (RFC 8594).', 'schema' => ['type' => 'string']],
        'Location'         => ['description' => 'The new resource, or where it moved.', 'schema' => ['type' => 'string', 'format' => 'uri']],
        'Request-Id'       => ['description' => 'An id for this call. Quote it when asking for support.', 'schema' => ['type' => 'string']],
        'Idempotency-Replayed' => ['description' => 'true on an answer replayed for a repeated Idempotency-Key.', 'schema' => ['type' => 'string', 'enum' => ['true']]],
    ];

    /** info.version: the API's, not the CMS's, so a release does not change the document. */
    public const API_VERSION = '1';

    /** Seconds a cached document is kept, in case a plugin changed what the fingerprint cannot see. */
    private const CACHE_TTL = 300;

    /** The webhook events' payloads: event => the schema of `data`. */
    private Events $events;

    /**
     * @param string                          $version info.version
     * @param array<int,array<string,string>> $servers
     */
    public function __construct(
        private Router $router,
        private Definitions $definitions,
        private Scopes $scopes,
        private string $version,
        private array $servers,
        ?Events $events = null
    ) {
        $this->events = $events ?? new Events(Events::core());
    }

    /**
     * The document for this site, from the kernel's router, schemas and scopes, with its own URL.
     */
    public static function forSite(Router $router, Definitions $definitions, Scopes $scopes): self
    {
        return new self($router, $definitions, $scopes, self::API_VERSION, [['url' => rtrim(osc_api_url(), '/'), 'description' => 'This site']], Events::fromHooks());
    }

    /**
     * The document for core alone, with no database and no plugins. Its version is the
     * API's, so it does not change with every release.
     */
    public static function core(): self
    {
        $definitions = Schema::definitions();

        return new self(new Router(new Validator($definitions), RouteTable::core()), $definitions, new Scopes(), self::API_VERSION, self::relativeServers());
    }

    /**
     * GET /openapi.json
     */
    public function show(): Response
    {
        return new Response(200, $this->cached());
    }

    /**
     * The document from the object cache, keyed on the route table, site URL, version and
     * active plugins. A per-request cache driver builds it every time.
     *
     * @return array<string,mixed>
     */
    private function cached(): array
    {
        if (!function_exists('osc_cache_get') || \Object_Cache_Factory::getInstance() instanceof \Object_Cache_default) {
            return $this->build();
        }
        $key = 'api_openapi_' . $this->fingerprint();
        $doc = osc_cache_get($key, $found);
        if ($found && is_array($doc)) {
            return $doc;
        }
        $doc = $this->build();
        osc_cache_set($key, $doc, self::CACHE_TTL);

        return $doc;
    }

    private function fingerprint(): string
    {
        $routes = [];
        foreach ($this->router->all() as $key => $route) {
            $handler  = $route->handler();
            $routes[] = [
                $key, is_array($handler) ? implode('::', array_map('strval', $handler)) : spl_object_id((object) $handler),
                $route->auth(), $route->scope(), $route->summary(), $route->description(), $route->tags(), $route->query(), $route->body(),
                $route->responses(), $route->replayable(), $route->upload(), $route->oauth(), $route->deprecated(), $route->sunset(),
            ];
        }

        return md5((string) json_encode([$routes, $this->version, $this->servers, function_exists('osc_active_plugins') ? osc_active_plugins() : '']));
    }

    /**
     * @return array<int,array<string,string>>
     */
    public static function relativeServers(): array
    {
        return [
            ['url' => '/api/' . Kernel::VERSION, 'description' => 'With friendly URLs'],
            ['url' => '/index.php?page=api&path=' . Kernel::VERSION, 'description' => 'Without friendly URLs: the rest of the path follows in the path parameter'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $paths = [];
        $tags  = [];
        foreach ($this->router->all() as $route) {
            $path = '/' . $route->path();
            $paths[$path][strtolower($route->method())] = $this->operation($route);
            array_push($tags, ...$route->tags());
        }
        ksort($paths);
        $tags = array_values(array_unique($tags));
        sort($tags);

        return [
            'openapi' => self::SPEC_VERSION,
            'info'    => [
                'title'       => 'Shopclass REST API',
                'version'     => $this->version,
                'description' => 'JSON over HTTPS. Send a credential as `Authorization: Bearer <token>`: `sck_...` for an admin\'s or a user\'s key, '
                    . '`scp_...` for a public key, which only reads public data (it may also go in `?api_key=` on a GET), or `sca_...`, '
                    . 'a signed-in user\'s access token from POST /auth/token. Writes accept an `Idempotency-Key` header. '
                    . 'Errors are RFC 9457 problem documents. A cookie alone never authenticates a call: theme JavaScript on the '
                    . 'site\'s own pages sends the signed-in user\'s page token in `X-Shopclass-Token` with the sign-in cookie.',
                'license'     => ['name' => 'GPL-3.0-or-later', 'identifier' => 'GPL-3.0-or-later'],
            ],
            'servers'    => $this->servers,
            'tags'       => array_map(static fn (string $t): array => isset(self::TAGS[$t]) ? ['name' => $t, 'description' => self::TAGS[$t]] : ['name' => $t], $tags),
            'paths'      => $paths,
            'webhooks'   => $this->webhooks(),
            'components' => [
                'schemas'         => $this->definitions->all(),
                'headers'         => $this->usedHeaders($paths),
                'securitySchemes' => [
                    'bearer' => [
                        'type'        => 'http',
                        'scheme'      => 'bearer',
                        'description' => 'An API key (`sck_<id>.<secret>`, `scp_<id>.<secret>`) or an access token (`sca_...`). The scopes an operation needs are listed in its security requirement and in `x-scope`.',
                    ],
                    'publicKey' => [
                        'type'        => 'apiKey',
                        'in'          => 'query',
                        'name'        => 'api_key',
                        'description' => 'A public key (`scp_...`) on a GET, for pages that cannot set a header.',
                    ],
                    'pageSession' => [
                        'type'        => 'apiKey',
                        'in'          => 'header',
                        'name'        => PageTokens::HEADER,
                        'description' => 'Same-site session: a page token (`scs_...`) from osc_api_session_token(), sent with the web '
                            . 'sign-in cookie by JavaScript on the site\'s own pages. No CORS, never `account:write` or admin scopes.',
                    ],
                ],
            ],
            'x-scopes' => $this->scopes->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operation(RouteSpec $route): array
    {
        $op = ['operationId' => self::operationId($route)];
        if ($route->summary() !== '') {
            $op['summary'] = $route->summary();
        }
        if ($route->description() !== '') {
            $op['description'] = $route->description();
        }
        if ($route->tags() !== []) {
            $op['tags'] = $route->tags();
        }
        $parameters = $this->parameters($route);
        if ($parameters !== []) {
            $op['parameters'] = $parameters;
        }
        if ($route->body() !== null) {
            $content = ['application/json' => ['schema' => $route->body()]];
            if ($route->method() === 'PATCH') {
                $content[Request::MERGE_PATCH] = ['schema' => $route->body()];
            }
            if ($route->oauth()) {
                $content[Request::FORM] = ['schema' => $route->body()];
            }
            $op['requestBody'] = ['required' => $this->bodyRequired($route->body()), 'content' => $content];
        }
        if ($route->upload()) {
            $op['requestBody'] = ['required' => true, 'content' => self::uploadContent()];
        }
        $op['responses'] = $this->responses($route);
        $op['security']  = $this->security($route);
        if ($route->deprecated() !== null) {
            $op['deprecated'] = true;
        }
        if ($route->sunset() !== null) {
            $op['x-sunset'] = $route->sunset();
        }
        $op['x-auth']  = $route->auth();
        $op['x-scope'] = $route->scope();

        return $op;
    }

    /**
     * Whether a body must be sent: an object schema naming no required member accepts none.
     *
     * @param array<string,mixed> $schema
     */
    private function bodyRequired(array $schema): bool
    {
        if (isset($schema['$ref'])) {
            $schema = $this->definitions->get(substr((string) $schema['$ref'], strlen(Validator::REF_PREFIX)));
        }

        return !in_array('object', (array) ($schema['type'] ?? []), true) || ($schema['required'] ?? []) !== [];
    }

    /**
     * An upload's body: a multipart form with the file in `photo`, or the image itself.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function uploadContent(): array
    {
        return [
            'multipart/form-data' => ['schema' => [
                'type'       => 'object',
                'properties' => ['photo' => ['type' => 'string', 'contentMediaType' => 'image/*', 'description' => 'The image file.']],
                'required'   => ['photo'],
            ]],
            'image/*'             => ['schema' => ['type' => 'string', 'contentMediaType' => 'image/*', 'description' => 'The image bytes, at most 16 MB.']],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parameters(RouteSpec $route): array
    {
        $parameters = [];
        foreach ($route->argNames() as $name) {
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => $name === 'id' ? 'integer' : 'string']];
        }
        $query    = $route->query() ?? [];
        $required = (array) ($query['required'] ?? []);
        foreach ((array) ($query['properties'] ?? []) as $name => $schema) {
            $parameter = ['name' => (string) $name, 'in' => 'query', 'required' => in_array($name, $required, true)];
            if (isset($schema['description'])) {
                $parameter['description'] = $schema['description'];
                unset($schema['description']);
            }
            $types = (array) ($schema['type'] ?? []);
            if (in_array('object', $types, true)) {
                $parameter['style']   = 'deepObject';
                $parameter['explode'] = true;
            } elseif (in_array('array', $types, true)) {
                $parameter['style']   = 'form';
                $parameter['explode'] = true;
            }
            $parameter['schema'] = $schema;
            $parameters[]        = $parameter;
        }
        if ($route->method() === 'GET') {
            $parameters[] = ['name' => 'If-None-Match', 'in' => 'header', 'required' => false, 'description' => 'An ETag from an earlier answer: 304 with no body while it still matches.', 'schema' => ['type' => 'string']];
        } elseif ($this->hasRead($route)) {
            $parameters[] = ['name' => 'If-Match', 'in' => 'header', 'required' => false, 'description' => 'An ETag from a GET of the same path (any fields, include or locale) or from the last write\'s answer, or `*`: 412 precondition_failed when the resource has changed since.', 'schema' => ['type' => 'string']];
        }
        if ($this->idempotent($route)) {
            $parameters[] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'description' => 'Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true.', 'schema' => ['type' => 'string', 'maxLength' => Idempotency::MAX_KEY]];
        }

        return $parameters;
    }

    /**
     * Whether the route takes an Idempotency-Key.
     */
    private function idempotent(RouteSpec $route): bool
    {
        return $route->method() !== 'GET' && $route->replayable() && $route->auth() !== RouteSpec::AUTH_NONE;
    }

    /**
     * Whether a PATCH or DELETE has a GET of the same path to take its If-Match ETag from.
     */
    private function hasRead(RouteSpec $route): bool
    {
        return in_array($route->method(), ['PATCH', 'DELETE'], true) && isset($this->router->all()['GET ' . $route->path()]);
    }

    /**
     * The route's own responses, plus the problems every route of its kind can answer.
     *
     * @return array<int|string,array<string,mixed>>
     */
    private function responses(RouteSpec $route): array
    {
        $declared = $route->responses();
        $problem  = Schema::ref('Problem');
        if ($route->auth() !== RouteSpec::AUTH_NONE) {
            $declared += [401 => $problem, 403 => $problem, 429 => $problem];
        }
        if ($route->query() !== null || $route->body() !== null) {
            $declared += [422 => $problem];
        }
        if ($route->body() !== null || $route->upload()) {
            $declared += [400 => $problem, 413 => $problem, 415 => $problem];
        }
        if ($route->upload()) {
            $declared += [422 => $problem];
        }
        if (array_filter(array_keys($declared), static fn ($s): bool => (int) $s < 400) === []) {
            // A route that names no success, such as a plugin's: say only that it answers.
            $declared[$route->method() === 'POST' ? 201 : 200] = ['type' => 'null'];
        }
        if ($this->hasRead($route)) {
            $declared += [412 => $problem];
        }
        if ($route->method() === 'GET' && isset($declared[200])) {
            $declared += [304 => ['type' => 'null']];
        }
        if ($this->idempotent($route)) {
            $declared += [409 => $problem];
        }
        $declared += [500 => $problem, 503 => $problem];
        ksort($declared);

        $out = [];
        foreach ($declared as $status => $schema) {
            $status   = (int) $status;
            $response = ['description' => self::STATUS_TEXT[$status] ?? 'HTTP ' . $status];
            $headers  = $this->responseHeaders($route, $status);
            if ($headers !== []) {
                $response['headers'] = $headers;
            }
            if ($status >= 400) {
                $response['content'] = ['application/problem+json' => ['schema' => $problem]];
            } elseif ($status !== 204 && $status < 300 && $schema !== ['type' => 'null']) {
                $response['content'] = ['application/json' => ['schema' => $schema]];
            }
            $out[(string) $status] = $response;
        }

        return $out;
    }

    /**
     * The headers an answer of $status to $route carries.
     *
     * @return array<string,array<string,string>>
     */
    private function responseHeaders(RouteSpec $route, int $status): array
    {
        $names = ['Request-Id'];
        if ($status === 201 || ($status >= 300 && $status < 400 && $status !== 304)) {
            $names[] = 'Location';
        }
        if (($route->method() === 'GET' && ($status === 200 || $status === 304)) || ($route->method() === 'PATCH' && $status === 200 && $this->hasRead($route))) {
            $names[] = 'ETag';
        }
        if ($route->auth() !== RouteSpec::AUTH_NONE) {
            array_push($names, 'RateLimit', 'RateLimit-Policy');
        }
        if ($status === 429 || $status === 503 || ($status === 409 && $this->idempotent($route))) {
            $names[] = 'Retry-After';
        }
        if ($status < 500 && $this->idempotent($route)) {
            $names[] = 'Idempotency-Replayed';
        }
        if ($route->deprecated() !== null) {
            $names[] = 'Deprecation';
        }
        if ($route->sunset() !== null) {
            $names[] = 'Sunset';
        }
        $out = [];
        foreach ($names as $name) {
            $out[$name] = ['$ref' => '#/components/headers/' . $name];
        }

        return $out;
    }

    /**
     * The headers some response refers to; Deprecation and Sunset only while a route is.
     *
     * @param array<string,mixed> $paths
     *
     * @return array<string,array<string,mixed>>
     */
    private function usedHeaders(array $paths): array
    {
        preg_match_all('~#/components/headers/([A-Za-z-]+)~', (string) json_encode($paths, JSON_UNESCAPED_SLASHES), $m);

        return array_intersect_key(self::HEADERS, array_flip($m[1]));
    }

    /**
     * The webhook events (OpenAPI 3.1 `webhooks`): the POST the site sends for each, its
     * body a WebhookMessage whose `data` is the event's schema.
     *
     * @return array<string,array<string,mixed>>
     */
    private function webhooks(): array
    {
        $out = [];
        foreach ($this->events->all() as $type => $event) {
            $data   = $event['schema'] !== '' && $this->definitions->has($event['schema']) ? Schema::ref($event['schema']) : ['type' => 'object'];
            $schema = ['allOf' => [Schema::ref('WebhookMessage'), ['type' => 'object', 'properties' => ['type' => ['const' => $type], 'data' => $data]]]];
            $out[$type] = ['post' => [
                'operationId' => 'webhook' . self::camel($type),
                'summary'     => $event['description'],
                'parameters'  => [
                    ['name' => 'webhook-id', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'webhook-timestamp', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'webhook-signature', 'in' => 'header', 'required' => true, 'description' => 'Standard Webhooks: v1,<base64 HMAC-SHA256>, space separated while a rotated secret is valid.', 'schema' => ['type' => 'string']],
                ],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]],
                // The site signs the call (webhook-signature); the receiver needs no credential of ours.
                'security'    => [],
                'responses'   => ['2XX' => ['description' => 'Received. Anything else is retried with backoff.']],
            ]];
        }

        return $out;
    }

    /**
     * @return array<int,array<string,string[]>|object>
     */
    private function security(RouteSpec $route): array
    {
        if ($route->auth() === RouteSpec::AUTH_NONE) {
            return [];
        }
        $scopes      = $route->scope() === null ? [] : [$route->scope()];
        $requirement = [['bearer' => $scopes]];
        if ($this->sessionMayCall($route)) {
            $requirement[] = ['pageSession' => $scopes];
        }
        if ($route->auth() === RouteSpec::AUTH_PUBLIC && (in_array($route->scope(), [null, Scopes::PUBLIC_READ], true))) {
            $requirement[] = ['publicKey' => $scopes];
            // A site may let anyone read public data with no credential at all.
            $requirement[] = (object) [];
        }

        return $requirement;
    }

    /**
     * Whether a same-site session call may use the route: a public or user route whose scope
     * a session holds. A user route naming no scope wants a token or key of its own.
     */
    private function sessionMayCall(RouteSpec $route): bool
    {
        if ($route->auth() !== RouteSpec::AUTH_PUBLIC && $route->auth() !== RouteSpec::AUTH_USER) {
            return false;
        }
        if ($route->scope() === null) {
            return $route->auth() === RouteSpec::AUTH_PUBLIC;
        }

        return Scopes::implies($this->scopes->allowedFor(CredentialKind::SESSION, KeyOwner::user(0)), $route->scope());
    }

    private static function operationId(RouteSpec $route): string
    {
        $id = strtolower($route->method());
        if ($route->path() === '') {
            return $id . 'Root';
        }
        foreach (explode('/', $route->path()) as $segment) {
            if (preg_match('/^\{([a-zA-Z0-9_]+)\}$/D', $segment, $m) === 1) {
                $id .= 'By' . self::camel($m[1]);
                continue;
            }
            $id .= self::camel($segment);
        }

        return $id;
    }

    private static function camel(string $value): string
    {
        return implode('', array_map('ucfirst', preg_split('/[^a-zA-Z0-9]+/', $value) ?: []));
    }
}
