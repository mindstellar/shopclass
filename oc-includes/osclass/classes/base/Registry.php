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

namespace mindstellar\base;

/**
 * Base for the plugin registries: one shared instance per subclass, an entries map,
 * and id validation. A subclass adds its own register() and checks.
 */
abstract class Registry
{
    protected const ID_PATTERN = '/^[a-z0-9_.-]{1,60}$/';

    /** @var array<class-string,static> */
    private static array $instances = [];

    /** @var array<string,mixed> registered entries, keyed by id */
    protected array $entries = [];

    protected function __construct()
    {
    }

    /**
     * Shared registry instance, created on first use.
     */
    public static function getInstance(): static
    {
        return self::$instances[static::class] ??= new static();
    }

    /**
     * @deprecated 7.0.0 Use getInstance().
     */
    public static function instance(): static
    {
        return static::getInstance();
    }

    /**
     * The entry registered under $id, or null.
     *
     * @return mixed
     */
    public function get(string $id)
    {
        return $this->entries[$id] ?? null;
    }

    /**
     * All registered entries, keyed by id.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * Whether $id is a well-formed id for this registry.
     */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match(static::ID_PATTERN, $id);
    }
}
