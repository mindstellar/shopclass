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

namespace mindstellar\api\serializer;

use mindstellar\api\read\CategoryCatalog;

/**
 * A category in the caller's locale, flat (with `parent_id`) or as a tree (`children`).
 * Ends with the `api_category` filter.
 */
final class CategorySerializer
{
    public const MEMBERS = [
        'id', 'parent_id', 'slug', 'name', 'description', 'position', 'listings_count', 'price_enabled', 'children', 'fields',
        'ext',
    ];

    public function __construct(private Extensions $extensions, private CustomFieldSerializer $fields)
    {
    }

    /**
     * @param array<string,mixed>                 $category a CategoryCatalog row
     * @param array<int,array<string,mixed>>|null $fields   the category's custom fields, sent when given
     *
     * @return array<string,mixed>
     */
    public function one(array $category, ViewContext $context, ?array $fields = null): array
    {
        $context = self::viewed($context);
        $data = $this->shape($category, $context);
        if ($fields !== null) {
            $data['fields'] = array_map(fn (array $f): array => $this->fields->definition($f, $context->locale()), $fields);
        }

        return $this->finish($data, $category, $context);
    }

    /**
     * Every category, flat in display order.
     *
     * @return array<int,array<string,mixed>>
     */
    public function flat(CategoryCatalog $catalog, ViewContext $context): array
    {
        $context = self::viewed($context);

        return array_map(fn (array $c): array => $this->finish($this->shape($c, $context), $c, $context), $catalog->all());
    }

    /**
     * The roots, each with its `children`, to any depth.
     *
     * @return array<int,array<string,mixed>>
     */
    public function tree(CategoryCatalog $catalog, ViewContext $context, int $parent = 0): array
    {
        $context = self::viewed($context);
        $out     = [];
        foreach ($catalog->childIds($parent) as $id) {
            $category         = (array) $catalog->find($id);
            $data             = $this->shape($category, $context);
            $data['children'] = $this->tree($catalog, $context, $id);
            $out[]            = $this->finish($data, $category, $context);
        }

        return $out;
    }

    /**
     * `{id, slug, name}`, the reference a listing carries.
     *
     * @param array<string,mixed> $category
     *
     * @return array{id:int,slug:string,name:string}
     */
    public static function reference(array $category, string $locale): array
    {
        return [
            'id'   => Format::int($category['pk_i_id'] ?? 0),
            'slug' => CategoryCatalog::text($category, 's_slug', $locale),
            'name' => CategoryCatalog::text($category, 's_name', $locale),
        ];
    }

    private static function viewed(ViewContext $context): ViewContext
    {
        return $context->withView($context->viewFor(null, ViewContext::TAXONOMY_SCOPE));
    }

    /**
     * @param array<string,mixed> $category
     *
     * @return array<string,mixed>
     */
    private function shape(array $category, ViewContext $context): array
    {
        $locale = $context->locale();

        return [
            'id'             => Format::int($category['pk_i_id'] ?? 0),
            'parent_id'      => Format::id($category['fk_i_parent_id'] ?? null),
            'slug'           => CategoryCatalog::text($category, 's_slug', $locale),
            'name'           => CategoryCatalog::text($category, 's_name', $locale),
            'description'    => Format::text(CategoryCatalog::text($category, 's_description', $locale)),
            'position'       => Format::int($category['i_position'] ?? 0),
            'listings_count' => Format::int($category['i_num_items'] ?? 0),
            'price_enabled'  => Format::bool($category['b_price_enabled'] ?? 1),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $category
     *
     * @return array<string,mixed>
     */
    private function finish(array $data, array $category, ViewContext $context): array
    {
        $filtered = osc_apply_filter('api_category', $data, $category, $context);

        return $this->extensions->finish('api_category', 'category', self::MEMBERS, $data, $filtered, [$category, $context], $context);
    }

    /**
     * A category as the admin edits it: its settings, and its texts in every language.
     *
     * @param array<string,mixed>            $row   a t_category row, with i_num_items when known
     * @param array<int,array<string,mixed>> $texts its t_category_description rows
     *
     * @return array<string,mixed>
     */
    public static function admin(array $row, array $texts): array
    {
        $translations = [];
        foreach ($texts as $text) {
            $translations[(string) $text['fk_c_locale_code']] = [
                'name'        => Format::text($text['s_name'] ?? null),
                'slug'        => (string) ($text['s_slug'] ?? ''),
                'description' => Format::text($text['s_description'] ?? null),
            ];
        }

        return [
            'id'              => Format::int($row['pk_i_id'] ?? 0),
            'parent_id'       => Format::id($row['fk_i_parent_id'] ?? null),
            'enabled'         => Format::bool($row['b_enabled'] ?? 0),
            'position'        => Format::int($row['i_position'] ?? 0),
            'expiration_days' => Format::int($row['i_expiration_days'] ?? 0),
            'price_enabled'   => Format::bool($row['b_price_enabled'] ?? 0),
            'listings_count'  => Format::int($row['i_num_items'] ?? 0),
            'translations'    => $translations,
        ];
    }
}
