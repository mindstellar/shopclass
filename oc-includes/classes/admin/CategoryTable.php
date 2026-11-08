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

namespace mindstellar\admin;

use mindstellar\category\CategoryTree;

/**
 * Flat, pre-order rows for the admin Categories table, built from Category::toTreeAll().
 * Pure view model: no database, no globals.
 */
final class CategoryTable
{
    /** @var list<array{id:int,parent:int,depth:int,name:string,enabled:bool,subs:int,below:int,height:int,listings:int,expiry:int,price:bool,deep:bool,canAddChild:bool}> */
    private array $rows = array();

    /** @var array{all:int,on:int,off:int} */
    private array $counts = array('all' => 0, 'on' => 0, 'off' => 0);

    /** @var array{categories:int,groups:int,listings:int} */
    private array $summary = array('categories' => 0, 'groups' => 0, 'listings' => 0);

    /**
     * @param array<int,array<string,mixed>> $tree Category::toTreeAll()
     */
    public function __construct(array $tree, private readonly ?int $depthLimit = null)
    {
        $nodes  = array();
        $parent = array();
        $this->collect($tree, 0, $nodes, $parent);

        $ids = new CategoryTree(array_map(static fn (int $p): ?int => $p ?: null, $parent));
        foreach ($nodes as $id => $node) {
            $depth = $ids->depth($id);
            $below = count($ids->subtree($id)) - 1;

            $this->rows[] = array(
                'id'          => $id,
                'parent'      => $parent[$id],
                'depth'       => $depth,
                'name'        => (string) ($node['s_name'] ?? ''),
                'enabled'     => !empty($node['b_enabled']),
                'subs'        => count($ids->children($id)),
                'below'       => $below,
                'height'      => $below === 0 ? 1 : $ids->height($id),
                'listings'    => (int) ($node['i_num_items'] ?? 0),
                'expiry'      => (int) ($node['i_expiration_days'] ?? 0),
                'price'       => !empty($node['b_price_enabled']),
                'deep'        => $this->depthLimit !== null && $depth > $this->depthLimit,
                'canAddChild' => $this->depthLimit === null || $depth < $this->depthLimit,
            );

            $this->counts['all']++;
            $this->counts[!empty($node['b_enabled']) ? 'on' : 'off']++;
            if ($parent[$id] === 0) {
                $this->summary['groups']++;
                $this->summary['listings'] += (int) ($node['i_num_items'] ?? 0);
            }
        }
        $this->summary['categories'] = $this->counts['all'];
    }

    /**
     * @param array<int,array<string,mixed>> $level
     * @param array<int,array<string,mixed>> $nodes
     * @param array<int,int>                 $parent
     */
    private function collect(array $level, int $parentId, array &$nodes, array &$parent): void
    {
        foreach ($level as $node) {
            $id = (int) $node['pk_i_id'];
            if (isset($nodes[$id])) {
                continue;
            }
            $nodes[$id]  = $node;
            $parent[$id] = $parentId;
            if (!empty($node['categories']) && is_array($node['categories'])) {
                $this->collect($node['categories'], $id, $nodes, $parent);
            }
        }
    }

    /**
     * @return list<array{id:int,parent:int,depth:int,name:string,enabled:bool,subs:int,below:int,height:int,listings:int,expiry:int,price:bool,deep:bool,canAddChild:bool}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array{all:int,on:int,off:int}
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * @return array{categories:int,groups:int,listings:int}
     */
    public function summary(): array
    {
        return $this->summary;
    }

    public function depthLimit(): ?int
    {
        return $this->depthLimit;
    }
}
