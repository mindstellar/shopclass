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
 * Sample products for a shop site. Field values are keyed by the field keys in fields-shop.php; variants name their options and stock.
 *
 * @return array<int, array<string, mixed>>
 */
return [
    [
        'category'    => 101,
        'title'       => __('Cotton t-shirt'),
        'description' => __('Soft 100 percent cotton tee with a relaxed fit. Machine washable.'),
        'price'       => '19',
        'image'       => 'tshirt',
        'fields'      => ['brand' => 'Fieldhouse', 'material' => 'Cotton', 'care' => 'Machine wash at 30 C'],
        'variants'    => [
            ['options' => [__('Size') => __('S'), __('Colour') => __('Navy')], 'stock' => 12],
            ['options' => [__('Size') => __('M'), __('Colour') => __('Navy')], 'stock' => 20],
            ['options' => [__('Size') => __('L'), __('Colour') => __('Navy')], 'stock' => 15],
            ['options' => [__('Size') => __('S'), __('Colour') => __('White')], 'stock' => 9],
            ['options' => [__('Size') => __('M'), __('Colour') => __('White')], 'stock' => 18],
            ['options' => [__('Size') => __('L'), __('Colour') => __('White')], 'stock' => 2],
        ],
    ],
    [
        'category'    => 100,
        'title'       => __('Linen summer dress'),
        'description' => __('Light and airy, with pockets. Cut to fall just below the knee.'),
        'price'       => '59',
        'image'       => 'dress',
        'fields'      => ['brand' => 'Fieldhouse', 'material' => 'Linen', 'care' => 'Wash cold, line dry'],
        'stock'       => 14,
    ],
    [
        'category'    => 103,
        'title'       => __('Canvas sneakers'),
        'description' => __('Everyday sneakers with a cushioned sole. True to size.'),
        'price'       => '49',
        'image'       => 'sneakers',
        'fields'      => ['brand' => 'Fieldhouse', 'material' => 'Canvas', 'care' => 'Wipe clean'],
        'variants'    => [
            ['options' => [__('Size') => __('40')], 'stock' => 8],
            ['options' => [__('Size') => __('41')], 'stock' => 10],
            ['options' => [__('Size') => __('42')], 'stock' => 6],
            ['options' => [__('Size') => __('43')], 'stock' => 4],
        ],
    ],
    [
        'category'    => 106,
        'title'       => __('Wireless earbuds'),
        'description' => __('Up to eight hours of play time and a pocket charging case.'),
        'price'       => '39',
        'image'       => 'earbuds',
        'fields'      => ['maker' => 'Nova', 'model' => 'Buds 2', 'warranty' => 12],
        'stock'       => 30,
    ],
    [
        'category'    => 108,
        'title'       => __('Smart speaker'),
        'description' => __('Compact speaker with room-filling sound and a built-in assistant.'),
        'price'       => '69',
        'image'       => 'speaker',
        'fields'      => ['maker' => 'Nova', 'model' => 'Hub Mini', 'warranty' => 24],
        'stock'       => 3,
    ],
    [
        'category'    => 111,
        'title'       => __('Ceramic mug'),
        'description' => __('Hand glazed stoneware mug that holds 350 ml. Dishwasher safe.'),
        'price'       => '14',
        'image'       => 'mug',
        'fields'      => ['brand' => 'Hearth', 'material' => 'Stoneware', 'care' => 'Dishwasher safe'],
        'variants'    => [
            ['options' => [__('Colour') => __('Blue')], 'stock' => 25],
            ['options' => [__('Colour') => __('Green')], 'stock' => 18],
            ['options' => [__('Colour') => __('White')], 'stock' => 30],
        ],
    ],
    [
        'category'    => 112,
        'title'       => __('Cotton bath towel set'),
        'description' => __('Three thick towels in a set. Gets softer with every wash.'),
        'price'       => '29',
        'image'       => 'towels',
        'fields'      => ['brand' => 'Hearth', 'material' => 'Cotton', 'care' => 'Machine wash at 40 C'],
        'stock'       => 22,
    ],
    [
        'category'    => 113,
        'title'       => __('Scented candle'),
        'description' => __('Soy wax candle in a reusable glass jar. Burns for about 40 hours.'),
        'price'       => '16',
        'image'       => 'candle',
        'fields'      => ['brand' => 'Hearth', 'material' => 'Soy wax', 'care' => 'Trim the wick'],
        'stock'       => 40,
    ],
    [
        'category'    => 115,
        'title'       => __('Face serum'),
        'description' => __('Light daily serum with vitamin C. Suitable for most skin types.'),
        'price'       => '24',
        'image'       => 'serum',
        'fields'      => [],
        'stock'       => 35,
    ],
    [
        'category'    => 119,
        'title'       => __('Yoga mat'),
        'description' => __('Non-slip mat, 6 mm thick, with a carry strap.'),
        'price'       => '28',
        'image'       => 'yogamat',
        'fields'      => ['brand' => 'Fieldhouse', 'material' => 'Natural rubber', 'care' => 'Wipe clean'],
        'stock'       => 16,
    ],
    [
        'category'    => 123,
        'title'       => __('Wooden building blocks'),
        'description' => __('Fifty natural wood blocks in a cotton bag. Smooth edges and safe finishes.'),
        'price'       => '22',
        'image'       => 'blocks',
        'fields'      => [],
        'stock'       => 20,
    ],
    [
        'category'    => 127,
        'title'       => __('Single-origin coffee beans'),
        'description' => __('Medium roast with notes of cocoa and orange. 250 g bag, roasted weekly.'),
        'price'       => '12',
        'image'       => 'coffee-beans',
        'fields'      => ['ingredients' => '100 percent Arabica coffee beans', 'allergens' => 'None'],
        'stock'       => 50,
    ],
    [
        'category'    => 129,
        'title'       => __('Dark chocolate gift box'),
        'description' => __('Twelve hand made dark chocolates in a keepsake box.'),
        'price'       => '25',
        'image'       => 'chocolate-box',
        'fields'      => ['ingredients' => 'Cocoa mass, sugar, cocoa butter, vanilla', 'allergens' => 'May contain nuts and milk'],
        'stock'       => 24,
    ],
    [
        'category'    => 131,
        'title'       => __('Dotted notebook'),
        'description' => __('Lay-flat binding and thick paper that takes ink well.'),
        'price'       => '9',
        'image'       => 'notebook',
        'fields'      => [],
        'variants'    => [
            ['options' => [__('Size') => __('A5')], 'stock' => 40],
            ['options' => [__('Size') => __('A6')], 'stock' => 35],
        ],
    ],
    [
        'category'    => 134,
        'title'       => __('Photography handbook, e-book'),
        'description' => __('A clear guide to light, framing and editing. Read it on any device.'),
        'price'       => '12',
        'image'       => 'ebook',
        'fields'      => ['format' => __('PDF')],
        'stock'       => 999,
    ],
    [
        'category'    => 137,
        'title'       => __('Gift card'),
        'description' => __('A card for any amount the recipient likes. Delivered by email.'),
        'price'       => '25',
        'image'       => 'giftcard',
        'fields'      => [],
        'stock'       => 999,
    ],
];
