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
 * Category tree installed for a shop site. Ids are fixed so sample data can point at them.
 *
 * @return array<int, array{id: int, parent: int|null, name: string}>
 */
return [
    ['id' => 1, 'parent' => null, 'name' => __('Clothing')],
    ['id' => 2, 'parent' => null, 'name' => __('Electronics')],
    ['id' => 3, 'parent' => null, 'name' => __('Home & Kitchen')],
    ['id' => 4, 'parent' => null, 'name' => __('Beauty & Health')],
    ['id' => 5, 'parent' => null, 'name' => __('Sports & Outdoors')],
    ['id' => 6, 'parent' => null, 'name' => __('Toys & Baby')],
    ['id' => 7, 'parent' => null, 'name' => __('Food & Drink')],
    ['id' => 8, 'parent' => null, 'name' => __('Books & Stationery')],
    ['id' => 9, 'parent' => null, 'name' => __('Digital Goods')],
    ['id' => 10, 'parent' => null, 'name' => __('Gifts')],
    ['id' => 100, 'parent' => 1, 'name' => __('Women')],
    ['id' => 101, 'parent' => 1, 'name' => __('Men')],
    ['id' => 102, 'parent' => 1, 'name' => __('Kids')],
    ['id' => 103, 'parent' => 1, 'name' => __('Shoes')],
    ['id' => 104, 'parent' => 1, 'name' => __('Accessories')],
    ['id' => 105, 'parent' => 2, 'name' => __('Phones & Tablets')],
    ['id' => 106, 'parent' => 2, 'name' => __('Audio & Headphones')],
    ['id' => 107, 'parent' => 2, 'name' => __('Computers')],
    ['id' => 108, 'parent' => 2, 'name' => __('Smart Home')],
    ['id' => 109, 'parent' => 2, 'name' => __('Accessories')],
    ['id' => 110, 'parent' => 3, 'name' => __('Furniture')],
    ['id' => 111, 'parent' => 3, 'name' => __('Kitchen & Dining')],
    ['id' => 112, 'parent' => 3, 'name' => __('Bedding & Bath')],
    ['id' => 113, 'parent' => 3, 'name' => __('Decor & Lighting')],
    ['id' => 114, 'parent' => 3, 'name' => __('Storage')],
    ['id' => 115, 'parent' => 4, 'name' => __('Skincare')],
    ['id' => 116, 'parent' => 4, 'name' => __('Hair Care')],
    ['id' => 117, 'parent' => 4, 'name' => __('Fragrance')],
    ['id' => 118, 'parent' => 4, 'name' => __('Wellness')],
    ['id' => 119, 'parent' => 5, 'name' => __('Fitness')],
    ['id' => 120, 'parent' => 5, 'name' => __('Cycling')],
    ['id' => 121, 'parent' => 5, 'name' => __('Camping & Hiking')],
    ['id' => 122, 'parent' => 5, 'name' => __('Sportswear')],
    ['id' => 123, 'parent' => 6, 'name' => __('Toys & Games')],
    ['id' => 124, 'parent' => 6, 'name' => __('Baby Essentials')],
    ['id' => 125, 'parent' => 6, 'name' => __('Kids\' Learning')],
    ['id' => 126, 'parent' => 7, 'name' => __('Snacks')],
    ['id' => 127, 'parent' => 7, 'name' => __('Coffee & Tea')],
    ['id' => 128, 'parent' => 7, 'name' => __('Baking & Pantry')],
    ['id' => 129, 'parent' => 7, 'name' => __('Gift Boxes')],
    ['id' => 130, 'parent' => 8, 'name' => __('Books')],
    ['id' => 131, 'parent' => 8, 'name' => __('Notebooks & Planners')],
    ['id' => 132, 'parent' => 8, 'name' => __('Art Supplies')],
    ['id' => 133, 'parent' => 8, 'name' => __('Office')],
    ['id' => 134, 'parent' => 9, 'name' => __('E-books')],
    ['id' => 135, 'parent' => 9, 'name' => __('Templates & Printables')],
    ['id' => 136, 'parent' => 9, 'name' => __('Courses & Audio')],
    ['id' => 137, 'parent' => 10, 'name' => __('Gift Cards')],
    ['id' => 138, 'parent' => 10, 'name' => __('Personalised')],
    ['id' => 139, 'parent' => 10, 'name' => __('Seasonal')],
];
