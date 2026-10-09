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

namespace mindstellar\api\routing;

use mindstellar\api\RouteSpec;
use mindstellar\api\schema\Validator;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Scopes;

/**
 * The route table and its matcher, per API version: the core routes, then plugin routes from
 * the `api_routes` filter, checked against the plugin rules and refused with a log line when
 * they break one. A table key is `METHOD path`, or `<version> METHOD path` for one version only.
 */
final class Router
{
    /** @api A plugin path: `ext/<slug>/...`. */
    public const PLUGIN_PATH = '#^ext/([a-z0-9-]+)/.+#D';

    /** @api The core components a plugin schema may `$ref`; its own are named `Ext...`. */
    public const SHARED_COMPONENTS = ['Problem', 'Listing', 'ListingPage', 'Photo', 'PageMeta', 'PageLinks'];

    /** @var array<string,array<string,array<string,RouteSpec>>> version => method => 'METHOD path' => route */
    private array $byVersion = [];

    /** @var array<string,true> keys of core routes */
    private array $core = [];

    /** @var \Closure(string): void */
    private \Closure $log;

    /** @var string[] the versions the site answers */
    private array $live;

    /** The declared scopes admin plugin routes are checked against; read from the hooks when first needed. */
    private ?Scopes $scopes;

    /**
     * Core's v1 route table, 'METHOD path' => spec: the public routes, then the admin ones.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function core(): array
    {
        return PublicRoutes::all() + AdminRoutes::all();
    }

    /**
     * @param array<string,array<string,mixed>> $coreRoutes 'METHOD path' => spec
     * @param callable|null                     $log        receives each refusal; error_log() by default
     * @param \Closure|null                     $handlers   fn(class-string): object for core handlers; plugin
     *                                                      handler classes are always built with no arguments
     * @param \Closure|null                     $kit        fn(): ApiKit, for ApiCall::kit()
     * @param string[]|null                     $versions   the versions the site answers; ApiSettings::VERSIONS by default
     * @param Scopes|null                       $scopes     the declared scopes admin plugin routes are checked against;
     *                                                      Scopes::fromHooks() when null
     *
     * @throws \InvalidArgumentException when a core route cannot be served
     */
    public function __construct(
        private Validator $validator,
        array $coreRoutes = [],
        ?callable $log = null,
        ?\Closure $handlers = null,
        private ?\Closure $kit = null,
        ?array $versions = null,
        ?Scopes $scopes = null
    ) {
        $this->log    = \Closure::fromCallable($log ?? 'error_log');
        $this->live   = $versions ?? array_keys(ApiSettings::VERSIONS);
        $this->scopes = $scopes;
        $named        = [];
        foreach ($coreRoutes as $key => $spec) {
            [$method, $path, $spec] = self::split((string) $key, $spec);
            $route = new RouteSpec($method, $path, $spec, $handlers, $kit, $this->live);
            if (isset($spec['versions'])) {
                $named[] = $route;
                continue;
            }
            $this->add($route);
            $this->core[$route->key()] = true;
        }
        // A route that names its versions replaces, in those versions, one that serves them all.
        foreach ($named as $route) {
            $this->add($route);
            $this->core[$route->key()] = true;
        }
    }

    /**
     * Core routes plus every plugin route.
     *
     * @param array<string,array<string,mixed>> $coreRoutes 'METHOD path' => spec
     */
    public static function build(
        Validator $validator,
        array $coreRoutes,
        ?callable $log = null,
        ?\Closure $handlers = null,
        ?\Closure $kit = null
    ): self {
        $router = new self($validator, $coreRoutes, $log, $handlers, $kit);
        $routes = osc_apply_filter('api_routes', []);
        foreach ((array) $routes as $key => $spec) {
            [$method, $path, $spec] = self::split((string) $key, is_array($spec) ? $spec : []);
            $router->addPlugin($method, $path, $spec);
        }

        return $router;
    }

    /**
     * Add a plugin route, or refuse and log it.
     *
     * @param array<string,mixed> $spec
     */
    public function addPlugin(string $method, string $path, array $spec): bool
    {
        $path    = trim($path, '/');
        $key     = strtoupper($method) . ' ' . $path;
        $plugin  = isset($spec['plugin']) && is_string($spec['plugin']) && $spec['plugin'] !== '' ? $spec['plugin'] : null;
        $label   = $key . ($plugin === null ? '' : ' (plugin ' . $plugin . ')');
        if (preg_match(self::PLUGIN_PATH, $path, $m) !== 1) {
            return $this->refuse($label, 'plugin paths must start with ext/<plugin-slug>/');
        }
        if (isset($this->core[$key])) {
            return $this->refuse($label, 'it would replace a core route');
        }
        $coreOnly = array_intersect(array_keys($spec), RouteSpec::CORE_KEYS);
        if ($coreOnly !== []) {
            return $this->refuse($label, 'only core routes may set ' . implode(', ', $coreOnly));
        }
        $unknown = array_diff(array_keys($spec), RouteSpec::PLUGIN_KEYS);
        if ($unknown !== []) {
            return $this->refuse($label, 'unknown spec key ' . implode(', ', $unknown));
        }
        if ($plugin !== $m[1]) {
            return $this->refuse($label, 'ext/' . $m[1] . '/ belongs to plugin ' . $m[1] . ', and the route\'s plugin must name it');
        }
        $spec['versions'] ??= [ApiSettings::PINNED_VERSION];
        try {
            $route = new RouteSpec($method, $path, $spec, null, $this->kit, $this->live);
            $route->check($this->validator);
        } catch (\InvalidArgumentException $e) {
            return $this->refuse($label, $e->getMessage());
        }
        if (in_array($route->auth(), [RouteSpec::AUTH_USER, RouteSpec::AUTH_ADMIN], true) && $route->scope() === null) {
            return $this->refuse($label, 'a route with user or admin auth must name a scope, so a key limited to other scopes cannot call it');
        }
        $scope = (string) $route->scope();
        if ($route->auth() === RouteSpec::AUTH_ADMIN && !str_starts_with($scope, 'admin:') && !str_starts_with($scope, 'ext:')) {
            return $this->refuse($label, 'an admin route must name an admin: or ext: scope, so a key holding only listings:read cannot run it as an admin');
        }
        if ($route->auth() === RouteSpec::AUTH_ADMIN && str_starts_with($scope, 'ext:')) {
            if (!str_starts_with($scope, 'ext:' . $m[1] . ':')) {
                return $this->refuse($label, 'an admin route may only name its own plugin\'s scope, ext:' . $m[1] . ':...');
            }
            $audience = ($this->scopes ??= Scopes::fromHooks())->pluginAudience($scope);
            if ($audience === null) {
                return $this->refuse($label, 'scope ' . $scope . ' is not declared on api_scopes');
            }
            if (!in_array($audience, [Scopes::AUDIENCE_ADMIN, Scopes::AUDIENCE_MODERATOR], true)) {
                return $this->refuse($label, 'an admin route\'s scope ' . $scope . ' must have the admin or moderator audience, so a user key cannot hold it');
            }
        }
        $foreign = array_filter($route->refs(), static fn (string $name): bool => !str_starts_with($name, 'Ext') && !in_array($name, self::SHARED_COMPONENTS, true));
        if ($foreign !== []) {
            return $this->refuse($label, 'core component ' . implode(', ', $foreign) . ' is not part of the plugin contract; register your own with osc_api_register_schema()');
        }
        foreach ($route->versions() as $version) {
            if (isset($this->byVersion[$version][$route->method()][$route->key()])) {
                return $this->refuse($label, 'another plugin route has the same method and path');
            }
        }
        if (($other = $this->pluginOverlap($route)) !== null) {
            ($this->log)('API route ' . $label . ' overlaps ' . $other->key() . ($other->plugin() === null ? '' : ' (plugin ' . $other->plugin() . ')') . ', which was registered first and answers first.');
        }
        $this->add($route);

        return true;
    }

    /**
     * The route for a method and path in a version. HEAD is answered by GET routes.
     */
    public function match(string $method, string $path, string $version = ApiSettings::VERSION): ?RouteMatch
    {
        $method = strtoupper($method);
        foreach ($this->byVersion[$version][$method === 'HEAD' ? 'GET' : $method] ?? [] as $route) {
            $args = $route->match($path);
            if ($args !== null) {
                return new RouteMatch($route, $args);
            }
        }

        return null;
    }

    /**
     * The methods a path answers in a version, for a 405's Allow header.
     *
     * @return string[]
     */
    public function methodsFor(string $path, string $version = ApiSettings::VERSION): array
    {
        $methods = [];
        foreach ($this->byVersion[$version] ?? [] as $method => $routes) {
            foreach ($routes as $route) {
                if ($route->match($path) !== null) {
                    $methods[] = $method;
                    break;
                }
            }
        }
        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }

        return $methods;
    }

    /**
     * @return array<string,RouteSpec> 'METHOD path' => route, for one version
     */
    public function all(string $version = ApiSettings::VERSION): array
    {
        return array_merge([], ...array_values($this->byVersion[$version] ?? []));
    }

    /**
     * Whether the site answers this API version.
     */
    public function serves(string $version): bool
    {
        return in_array($version, $this->live, true);
    }

    private function add(RouteSpec $route): void
    {
        foreach ($route->versions() as $version) {
            $this->byVersion[$version][$route->method()][$route->key()] = $route;
        }
    }

    /**
     * The first plugin route of the same method and a shared version that could answer a path $route answers.
     */
    private function pluginOverlap(RouteSpec $route): ?RouteSpec
    {
        foreach ($route->versions() as $version) {
            foreach ($this->byVersion[$version][$route->method()] ?? [] as $key => $other) {
                if (!isset($this->core[$key]) && $route->overlaps($other)) {
                    return $other;
                }
            }
        }

        return null;
    }

    /**
     * A table key's method and path, and the spec with the key's version, if it names one.
     *
     * @param array<string,mixed> $spec
     *
     * @return array{0:string,1:string,2:array<string,mixed>}
     */
    private static function split(string $key, array $spec): array
    {
        $parts = explode(' ', $key, 3);
        if (preg_match('/^v[0-9]+$/D', $parts[0]) === 1 && count($parts) > 1) {
            $spec['versions'] = [$parts[0]];
            array_shift($parts);
        } else {
            $parts = explode(' ', $key, 2);
        }

        return [$parts[0], $parts[1] ?? '', $spec];
    }

    private function refuse(string $key, string $why): bool
    {
        ($this->log)('API route ' . $key . ' refused: ' . $why . '.');

        return false;
    }
}
