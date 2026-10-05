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

/**
 * The route table and its matcher: the core routes it is given, then plugin routes from the
 * `api_routes` filter (which osc_api_register_route() feeds). Plugin paths must start with
 * `ext/<plugin-slug>/`, except a route with both `deprecated` and `sunset`, which may keep a
 * plugin's old path until its sunset day if no core route could answer that path (with any
 * method). None can replace a core route. Anything else is refused and logged.
 */
final class Router
{
    /** A plugin path: `ext/<slug>/...`. */
    public const PLUGIN_PATH = '#^ext/[a-z0-9-]+/.+#D';

    /** @var array<string,array<string,RouteSpec>> method => 'METHOD path' => route */
    private array $byMethod = [];

    /** @var array<string,true> keys of core routes */
    private array $core = [];

    /** @var \Closure(string): void */
    private \Closure $log;

    /** Today, `Y-m-d` UTC, for a deprecated route's sunset. */
    private string $today;

    /**
     * @param array<string,array<string,mixed>> $coreRoutes 'METHOD path' => spec
     * @param callable|null                     $log        receives each refusal; error_log() by default
     * @param \Closure|null                     $handlers   fn(class-string): object for core handlers; plugin
     *                                                      handler classes are always built with no arguments
     * @param string|null                       $today      `Y-m-d`; today in UTC by default
     *
     * @throws \InvalidArgumentException when a core route cannot be served
     */
    public function __construct(private Validator $validator, array $coreRoutes = [], ?callable $log = null, ?\Closure $handlers = null, ?string $today = null)
    {
        $this->log   = \Closure::fromCallable($log ?? 'error_log');
        $this->today = $today ?? gmdate('Y-m-d');
        foreach ($coreRoutes as $key => $spec) {
            [$method, $path] = self::split((string) $key);
            $route = new RouteSpec($method, $path, $spec, $handlers);

            $this->byMethod[$route->method()][$route->key()] = $route;
            $this->core[$route->key()]                       = true;
        }
    }

    /**
     * Core routes plus every plugin route.
     *
     * @param array<string,array<string,mixed>> $coreRoutes 'METHOD path' => spec
     */
    public static function build(Validator $validator, array $coreRoutes, ?callable $log = null, ?\Closure $handlers = null, ?string $today = null): self
    {
        $router = new self($validator, $coreRoutes, $log, $handlers, $today);
        $routes = [];
        $routes = osc_apply_filter('api_routes', $routes);
        foreach ((array) $routes as $key => $spec) {
            [$method, $path] = self::split((string) $key);
            $router->addPlugin($method, $path, is_array($spec) ? $spec : []);
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
        $path = trim($path, '/');
        $key  = strtoupper($method) . ' ' . $path;
        $outside = preg_match(self::PLUGIN_PATH, $path) !== 1;
        if ($outside && (empty($spec['deprecated']) || empty($spec['sunset']))) {
            return $this->refuse($key, 'plugin paths must start with ext/<plugin-slug>/ unless the route is deprecated with a sunset date');
        }
        if (isset($this->core[$key])) {
            return $this->refuse($key, 'it would replace a core route');
        }
        try {
            $route = new RouteSpec($method, $path, $spec);
            $route->check($this->validator);
        } catch (\InvalidArgumentException $e) {
            return $this->refuse($key, $e->getMessage());
        }
        if ($outside && $this->today >= (string) $route->sunset()) {
            return $this->refuse($key, 'its sunset date has passed');
        }
        if ($outside && ($core = $this->coreOverlap($route)) !== null) {
            return $this->refuse($key, 'core route ' . $core . ' answers that path');
        }
        $this->byMethod[$route->method()][$route->key()] = $route;

        return true;
    }

    /**
     * The route for a method and path. HEAD is answered by GET routes.
     */
    public function match(string $method, string $path): ?RouteMatch
    {
        $method = strtoupper($method);
        foreach ($this->byMethod[$method === 'HEAD' ? 'GET' : $method] ?? [] as $route) {
            $args = $route->match($path);
            if ($args !== null) {
                return new RouteMatch($route, $args);
            }
        }

        return null;
    }

    /**
     * The methods a path answers, for a 405's Allow header.
     *
     * @return string[]
     */
    public function methodsFor(string $path): array
    {
        $methods = [];
        foreach ($this->byMethod as $method => $routes) {
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
     * @return array<string,RouteSpec> 'METHOD path' => route
     */
    public function all(): array
    {
        return array_merge([], ...array_values($this->byMethod));
    }

    public function isCore(string $key): bool
    {
        return isset($this->core[$key]);
    }

    /**
     * The first core route, of any method, that could answer a request path $route answers.
     */
    private function coreOverlap(RouteSpec $route): ?string
    {
        foreach ($this->byMethod as $routes) {
            foreach ($routes as $key => $other) {
                if (isset($this->core[$key]) && $route->overlaps($other)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function split(string $key): array
    {
        $parts = explode(' ', $key, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private function refuse(string $key, string $why): bool
    {
        ($this->log)('API route ' . $key . ' refused: ' . $why . '.');

        return false;
    }
}
