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

/**
 * The named schemas a `$ref` points at. The names are known up front, so a route's `$ref`
 * is checked without building anything; the schemas themselves are built on first use.
 */
final class Definitions
{
    /** @var array<string,true> */
    private array $names;

    /** @var array<string,array<string,mixed>>|null */
    private ?array $schemas;

    /** @var (\Closure(): array<string,array<string,mixed>>)|null */
    private ?\Closure $build;

    /**
     * @param string[]                                $names
     * @param array<string,array<string,mixed>>|null $schemas
     */
    private function __construct(array $names, ?array $schemas, ?\Closure $build)
    {
        $this->names   = array_fill_keys($names, true);
        $this->schemas = $schemas;
        $this->build   = $build;
    }

    /**
     * @param array<string,array<string,mixed>> $schemas
     */
    public static function of(array $schemas): self
    {
        return new self(array_keys($schemas), $schemas, null);
    }

    /**
     * @param string[]                                    $names what $build returns, by name
     * @param \Closure(): array<string,array<string,mixed>> $build
     */
    public static function lazy(array $names, \Closure $build): self
    {
        return new self($names, null, $build);
    }

    public function has(string $name): bool
    {
        return isset($this->names[$name]);
    }

    /**
     * @return array<string,mixed>
     * @throws \OutOfBoundsException for a name that is not defined
     */
    public function get(string $name): array
    {
        return $this->all()[$name] ?? throw new \OutOfBoundsException('No schema named ' . $name . '.');
    }

    /**
     * @return array<string,array<string,mixed>>
     * @throws \LogicException when the built schemas are not the ones named
     */
    public function all(): array
    {
        if ($this->schemas === null) {
            $schemas = ($this->build)();
            $built   = array_keys($schemas);
            $named   = array_keys($this->names);
            sort($built);
            sort($named);
            if ($built !== $named) {
                throw new \LogicException('The schemas built are not the ones named.');
            }
            $this->schemas = $schemas;
            $this->build   = null;
        }

        return $this->schemas;
    }

    public function isBuilt(): bool
    {
        return $this->schemas !== null;
    }
}
