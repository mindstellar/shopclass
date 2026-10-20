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

namespace mindstellar\routing;

/**
 * Runs the controller a `?page=` value maps to in a PageRoutes table.
 *
 * Plugins add pages with the `page_routes` (site) or `admin_page_routes` (admin) filter.
 * They receive the core table and may add keys; a core page cannot be replaced.
 */
final class PageDispatcher
{
    /** @var array<string,string|array<string,mixed>> */
    private array $routes;
    private string $fallback;

    /**
     * @param array<string,string|array<string,mixed>> $routes
     */
    public function __construct(array $routes, string $fallback)
    {
        $this->routes   = $routes;
        $this->fallback = $fallback;
    }

    /**
     * The site's dispatcher, with the pages plugins add.
     */
    public static function web(): self
    {
        $core = PageRoutes::web();

        return new self(self::merge($core, osc_apply_filter('page_routes', $core)), PageRoutes::WEB_FALLBACK);
    }

    /**
     * The admin's dispatcher, with the pages plugins add.
     */
    public static function admin(): self
    {
        $core = PageRoutes::admin();

        return new self(self::merge($core, osc_apply_filter('admin_page_routes', $core)), PageRoutes::ADMIN_FALLBACK);
    }

    /**
     * Core routes plus the new keys of a filtered table. A non-array result adds nothing.
     *
     * @param array<string,string|array<string,mixed>> $core
     * @param mixed                                    $filtered
     *
     * @return array<string,string|array<string,mixed>>
     */
    public static function merge(array $core, $filtered): array
    {
        return is_array($filtered) ? $core + $filtered : $core;
    }

    /**
     * The controller class, or 'Class::method' handler, that answers a request.
     *
     * @param callable(): bool $isGuest asked only for a route with guest actions
     */
    public function resolve(string $page, string $action, callable $isGuest): string
    {
        $route = $this->routes[$page] ?? null;
        if (is_string($route)) {
            return $this->usable($route) ? $route : $this->fallback;
        }
        if (!is_array($route)) {
            return $this->fallback;
        }

        if (isset($route['handler']) && is_string($route['handler'])) {
            return is_callable($route['handler']) ? $route['handler'] : $this->fallback;
        }

        $target = $route['controller'] ?? null;
        if (isset($route['actions'][$action])) {
            $target = $route['actions'][$action];
        } elseif (isset($route['guest'][$action]) && $isGuest()) {
            $target = $route['guest'][$action];
        }

        return is_string($target) && $this->usable($target) ? $target : $this->fallback;
    }

    /**
     * Resolve and run the request.
     */
    public function dispatch(string $page, string $action): void
    {
        $target = $this->resolve($page, $action, static fn (): bool => !osc_is_web_user_logged_in());
        if (str_contains($target, '::')) {
            $target();

            return;
        }

        $controller = new $target();
        $controller->doModel();
    }

    private function usable(string $class): bool
    {
        return class_exists($class) && method_exists($class, 'doModel');
    }
}
