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

namespace mindstellar\category;

/**
 * The category tree as plain arrays, built from a map of id => parent id. No database.
 */
final class CategoryTree
{
    /** @var array<int,int[]> */
    private array $children = array();

    /**
     * @param array<int,?int> $parentMap id => parent id, null for the top level
     */
    public function __construct(private readonly array $parentMap)
    {
        foreach ($parentMap as $id => $parent) {
            if ($parent !== null) {
                $this->children[$parent][] = (int) $id;
            }
        }
    }

    public function exists(int $id): bool
    {
        return array_key_exists($id, $this->parentMap);
    }

    /**
     * @return array<int,?int> the map the tree was built from
     */
    public function map(): array
    {
        return $this->parentMap;
    }

    public function parent(int $id): ?int
    {
        return $this->parentMap[$id] ?? null;
    }

    /**
     * Ids directly under $parentId (null = top level), in the map's order.
     *
     * @return int[]
     */
    public function children(?int $parentId): array
    {
        if ($parentId !== null) {
            return $this->children[$parentId] ?? array();
        }

        return array_keys(array_filter($this->parentMap, static fn (?int $p): bool => $p === null));
    }

    /**
     * Parent first, then upwards. Stops if the data loops back on itself.
     *
     * @return int[]
     */
    public function ancestors(int $id): array
    {
        $out  = array();
        $seen = array($id => true);
        $next = $this->parentMap[$id] ?? null;
        while ($next !== null && !isset($seen[$next])) {
            $out[]       = $next;
            $seen[$next] = true;
            $next        = $this->parentMap[$next] ?? null;
        }

        return $out;
    }

    /**
     * Levels from the top, which is 1. Unknown ids count as 1.
     */
    public function depth(int $id): int
    {
        return count($this->ancestors($id)) + 1;
    }

    /**
     * The id and everything below it, deepest first.
     *
     * @return int[]
     */
    public function subtree(int $id): array
    {
        if (!$this->exists($id)) {
            return array();
        }

        $ids   = array();
        $seen  = array();
        $stack = array($id);
        while ($stack !== array()) {
            $node = array_pop($stack);
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            $ids[]       = $node;
            foreach ($this->children[$node] ?? array() as $child) {
                $stack[] = $child;
            }
        }

        return array_reverse($ids);
    }

    /**
     * Levels in the subtree starting at $id: 1 for a leaf.
     */
    public function height(int $id): int
    {
        $height = 1;
        $level  = array($id => true);
        $seen   = array($id => true);
        while (true) {
            $next = array();
            foreach (array_keys($level) as $node) {
                foreach ($this->children[$node] ?? array() as $child) {
                    if (!isset($seen[$child])) {
                        $seen[$child] = true;
                        $next[$child] = true;
                    }
                }
            }
            if ($next === array()) {
                return $height;
            }
            $height++;
            $level = $next;
        }
    }

    /**
     * Whether $id is below $ancestorId.
     */
    public function isInside(int $id, int $ancestorId): bool
    {
        return in_array($ancestorId, $this->ancestors($id), true);
    }

    /**
     * Drops ids that sit under another id in the list. Keeps input order.
     *
     * @param int[] $ids
     *
     * @return int[]
     */
    public function topMost(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $set = array_flip($ids);

        return array_values(array_filter($ids, function (int $id) use ($set): bool {
            foreach ($this->ancestors($id) as $ancestor) {
                if (isset($set[$ancestor])) {
                    return false;
                }
            }

            return true;
        }));
    }
}
