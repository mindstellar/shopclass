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

namespace mindstellar\container;

/**
 * Builds each service on first use and shares it after. A service is a factory given to set(),
 * an object given to instance(), or any class whose constructor takes no arguments.
 */
final class Container implements ContainerInterface
{
    private static ?Container $root = null;

    /** @var array<string,callable(Container): mixed> */
    private array $factories = [];

    /** @var array<string,mixed> services already built */
    private array $shared = [];

    /** @var array<string,bool> whether an unregistered id can be built with new $id() */
    private array $buildable = [];

    /** @var array<string,true> ids being built now, to catch a factory that needs itself */
    private array $building = [];

    /**
     * The one site-wide container.
     */
    public static function root(): self
    {
        return self::$root ??= new self();
    }

    /**
     * Make $container the site-wide one and return the one it replaces. Tests use it to start clean.
     */
    public static function use(Container $container): self
    {
        $previous   = self::root();
        self::$root = $container;

        return $previous;
    }

    /**
     * Register a factory for $id. It runs on the first get($id), with this container, and its result is shared.
     *
     * @param callable(Container): mixed $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->shared[$id]);
    }

    /**
     * Register an object that is already built.
     */
    public function instance(string $id, object $service): void
    {
        unset($this->factories[$id]);
        $this->shared[$id] = $service;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->shared) || isset($this->factories[$id]) || $this->canBuild($id);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id)
    {
        if (array_key_exists($id, $this->shared)) {
            return $this->shared[$id];
        }
        if (isset($this->building[$id])) {
            throw new \LogicException(sprintf('The service "%s" needs itself to be built.', $id));
        }
        if (!isset($this->factories[$id]) && !$this->canBuild($id)) {
            throw NotFoundException::forId($id);
        }

        $this->building[$id] = true;
        try {
            $service = isset($this->factories[$id]) ? ($this->factories[$id])($this) : new $id();
        } finally {
            unset($this->building[$id]);
        }

        return $this->shared[$id] = $service;
    }

    private function canBuild(string $id): bool
    {
        if (!isset($this->buildable[$id])) {
            $ok = false;
            if (class_exists($id)) {
                $class       = new \ReflectionClass($id);
                $constructor = $class->getConstructor();
                $ok          = $class->isInstantiable() && ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0);
            }
            $this->buildable[$id] = $ok;
        }

        return $this->buildable[$id];
    }
}
