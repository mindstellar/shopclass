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

namespace mindstellar\api\read;

use mindstellar\api\Problem;
use mindstellar\api\ProblemException;

/**
 * Every enabled category, with its translations, read once and looked up by id or slug.
 * A listing's category, its path and the category endpoints all come from here, so a page
 * of listings costs no category query of its own.
 */
final class CategoryCatalog
{
    /** @var array<int,array<string,mixed>> id => row, in display order */
    private array $byId = [];

    /** @var array<int,int[]> parent id (0 for roots) => child ids */
    private array $children = [];

    /**
     * @param array<int,array<string,mixed>> $rows Category::listEnabled() rows, each with a
     *                                             `locale` map of s_name, s_description, s_slug
     */
    public function __construct(array $rows)
    {
        foreach ($rows as $row) {
            $id = (int) ($row['pk_i_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            unset($row['categories']);
            $this->byId[$id] = $row;
        }
        foreach ($this->byId as $id => $row) {
            $parent = (int) ($row['fk_i_parent_id'] ?? 0);
            $this->children[isset($this->byId[$parent]) ? $parent : 0][] = $id;
        }
    }

    /**
     * The site's categories, from the cached tree core already keeps.
     */
    public static function fromSite(): self
    {
        $rows = [];
        $walk = static function (array $branch) use (&$walk, &$rows): void {
            foreach ($branch as $category) {
                $rows[] = $category;
                $walk((array) ($category['categories'] ?? []));
            }
        };
        $walk(\Category::getInstance()->toTree(true));

        return new self($rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->byId[$id] ?? null;
    }

    /**
     * A category by id, or by slug in any locale (the given one first). A slug path such as
     * `vehicles/cars` names its last slug, as search URLs do.
     *
     * @return array<string,mixed>|null
     */
    public function lookup(string $idOrSlug, string $locale): ?array
    {
        if (ctype_digit($idOrSlug)) {
            return $this->find((int) $idOrSlug);
        }
        $parts    = explode('/', trim($idOrSlug, '/'));
        $idOrSlug = end($parts);
        $fallback = null;
        foreach ($this->byId as $row) {
            foreach ((array) ($row['locale'] ?? []) as $code => $text) {
                if (($text['s_slug'] ?? null) === $idOrSlug) {
                    if ($code === $locale) {
                        return $row;
                    }
                    $fallback ??= $row;
                }
            }
        }

        return $fallback;
    }

    /**
     * The ancestors of a category, root first, without the category itself.
     *
     * @return array<int,array<string,mixed>>
     */
    public function ancestors(int $id): array
    {
        $path = [];
        $seen = [$id => true];
        $row  = $this->find($id);
        while ($row !== null) {
            $parent = (int) ($row['fk_i_parent_id'] ?? 0);
            if ($parent <= 0 || isset($seen[$parent]) || !isset($this->byId[$parent])) {
                break;
            }
            $seen[$parent] = true;
            $row           = $this->byId[$parent];
            array_unshift($path, $row);
        }

        return $path;
    }

    /**
     * Category ids for ids or slugs. An unknown one is refused, so a typo never widens a
     * filter to every category.
     *
     * @param string[] $values
     * @param bool     $anyId  take an id as given, so an admin may name a category that is off
     *
     * @return int[]
     * @throws ProblemException 422
     */
    public function resolve(array $values, string $locale, bool $anyId = false): array
    {
        $ids = [];
        foreach ($values as $value) {
            $row = $anyId && ctype_digit($value) ? ['pk_i_id' => $value] : $this->lookup($value, $locale);
            if ($row === null) {
                throw ProblemException::from(Problem::validation([
                    ['pointer' => '/category', 'code' => 'enum', 'message' => 'is not a known category: ' . $value, 'in' => 'query'],
                ]));
            }
            $ids[] = (int) $row['pk_i_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return int[] child ids in display order; 0 for the roots
     */
    public function childIds(int $id): array
    {
        return $this->children[$id] ?? [];
    }

    /**
     * @return array<int,array<string,mixed>> every category in display order, parents first
     */
    public function all(): array
    {
        $out  = [];
        $walk = function (int $parent) use (&$walk, &$out): void {
            foreach ($this->childIds($parent) as $id) {
                $out[] = $this->byId[$id];
                $walk($id);
            }
        };
        $walk(0);

        return $out;
    }

    /**
     * A translated text of a category row: the locale's own, else the row's default.
     *
     * @param array<string,mixed> $row
     */
    public static function text(array $row, string $key, string $locale): string
    {
        $value = $row['locale'][$locale][$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return (string) ($row[$key] ?? '');
    }
}
