<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Custom field groups for the shop sample products. Each group applies to the listed top categories and their children.
 *
 * @return array<int, array<string, mixed>>
 */
return [
    [
        'name'       => __('Product details'),
        'categories' => [1, 3, 5],
        'fields'     => [
            ['key' => 'brand', 'name' => __('Brand'), 'type' => 'TEXT', 'searchable' => true],
            ['key' => 'material', 'name' => __('Material'), 'type' => 'TEXT', 'searchable' => false],
            ['key' => 'care', 'name' => __('Care'), 'type' => 'TEXT', 'searchable' => false],
        ],
    ],
    [
        'name'       => __('Specs'),
        'categories' => [2],
        'fields'     => [
            ['key' => 'maker', 'name' => __('Manufacturer'), 'type' => 'TEXT', 'searchable' => true],
            ['key' => 'model', 'name' => __('Model'), 'type' => 'TEXT', 'searchable' => false],
            ['key' => 'warranty', 'name' => __('Warranty months'), 'type' => 'NUMBER', 'searchable' => false],
        ],
    ],
    [
        'name'       => __('Ingredients and allergens'),
        'categories' => [7],
        'fields'     => [
            ['key' => 'ingredients', 'name' => __('Ingredients'), 'type' => 'TEXTAREA', 'searchable' => false],
            ['key' => 'allergens', 'name' => __('Allergens'), 'type' => 'TEXT', 'searchable' => false],
        ],
    ],
    [
        'name'       => __('Digital file'),
        'categories' => [9],
        'fields'     => [
            ['key' => 'format', 'name' => __('File format'), 'type' => 'DROPDOWN', 'searchable' => false, 'options' => [__('PDF'), __('EPUB'), __('ZIP')]],
        ],
    ],
];
