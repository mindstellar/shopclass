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
 * Custom field groups for the classifieds sample listings. Each group applies to the listed top categories and their children.
 *
 * @return array<int, array<string, mixed>>
 */
return [
    [
        'name'       => __('Vehicle details'),
        'categories' => [1],
        'fields'     => [
            ['key' => 'make', 'name' => __('Make'), 'type' => 'TEXT', 'searchable' => true],
            ['key' => 'model', 'name' => __('Model'), 'type' => 'TEXT', 'searchable' => false],
            ['key' => 'year', 'name' => __('Year'), 'type' => 'NUMBER', 'searchable' => true],
            ['key' => 'mileage', 'name' => __('Mileage'), 'type' => 'NUMBER', 'searchable' => false],
            ['key' => 'fuel', 'name' => __('Fuel'), 'type' => 'DROPDOWN', 'searchable' => false, 'options' => [__('Petrol'), __('Diesel'), __('Hybrid'), __('Electric')]],
            ['key' => 'gearbox', 'name' => __('Transmission'), 'type' => 'DROPDOWN', 'searchable' => false, 'options' => [__('Manual'), __('Automatic')]],
            ['key' => 'vcond', 'name' => __('Vehicle condition'), 'type' => 'DROPDOWN', 'searchable' => false, 'options' => [__('New'), __('Used')]],
        ],
    ],
    [
        'name'       => __('Property details'),
        'categories' => [2],
        'fields'     => [
            ['key' => 'ptype', 'name' => __('Property type'), 'type' => 'DROPDOWN', 'searchable' => true, 'options' => [__('House'), __('Flat'), __('Room'), __('Land'), __('Commercial')]],
            ['key' => 'beds', 'name' => __('Bedrooms'), 'type' => 'NUMBER', 'searchable' => true],
            ['key' => 'baths', 'name' => __('Bathrooms'), 'type' => 'NUMBER', 'searchable' => false],
            ['key' => 'area', 'name' => __('Floor area (m²)'), 'type' => 'NUMBER', 'searchable' => false],
            ['key' => 'furnished', 'name' => __('Furnished'), 'type' => 'CHECKBOX', 'searchable' => false],
            ['key' => 'available', 'name' => __('Available from'), 'type' => 'DATE', 'searchable' => false],
        ],
    ],
    [
        'name'       => __('Job details'),
        'categories' => [3],
        'fields'     => [
            ['key' => 'company', 'name' => __('Company'), 'type' => 'TEXT', 'searchable' => false],
            ['key' => 'jtype', 'name' => __('Job type'), 'type' => 'DROPDOWN', 'searchable' => false, 'options' => [__('Full-time'), __('Part-time'), __('Contract'), __('Internship')]],
            ['key' => 'salary', 'name' => __('Salary'), 'type' => 'TEXT', 'searchable' => false],
            ['key' => 'remote', 'name' => __('Remote'), 'type' => 'CHECKBOX', 'searchable' => false],
            ['key' => 'applyby', 'name' => __('Apply by'), 'type' => 'DATE', 'searchable' => false],
        ],
    ],
    [
        'name'       => __('Item details'),
        'categories' => [4, 5, 6, 7, 8, 12],
        'fields'     => [
            ['key' => 'brand', 'name' => __('Brand'), 'type' => 'TEXT', 'searchable' => true],
            ['key' => 'cond', 'name' => __('Condition'), 'type' => 'DROPDOWN', 'searchable' => true, 'options' => [__('New'), __('Like new'), __('Used'), __('For parts')]],
            ['key' => 'colour', 'name' => __('Colour'), 'type' => 'TEXT', 'searchable' => false],
        ],
    ],
    [
        'name'       => __('Pet details'),
        'categories' => [9],
        'fields'     => [
            ['key' => 'age', 'name' => __('Age'), 'type' => 'TEXT', 'searchable' => false],
            ['key' => 'sex', 'name' => __('Sex'), 'type' => 'DROPDOWN', 'searchable' => false, 'options' => [__('Male'), __('Female')]],
            ['key' => 'vaccinated', 'name' => __('Vaccinated'), 'type' => 'CHECKBOX', 'searchable' => false],
        ],
    ],
    [
        'name'       => __('Service details'),
        'categories' => [10, 11],
        'fields'     => [
            ['key' => 'area_served', 'name' => __('Service area'), 'type' => 'TEXT', 'searchable' => false],
            ['key' => 'rate', 'name' => __('Rate'), 'type' => 'TEXT', 'searchable' => false],
            ['key' => 'website', 'name' => __('Website'), 'type' => 'URL', 'searchable' => false],
        ],
    ],
    [
        'name'       => __('Event details'),
        'categories' => [13],
        'fields'     => [
            ['key' => 'date', 'name' => __('Date'), 'type' => 'DATE', 'searchable' => false],
            ['key' => 'venue', 'name' => __('Venue'), 'type' => 'TEXT', 'searchable' => false],
            ['key' => 'free', 'name' => __('Free entry'), 'type' => 'CHECKBOX', 'searchable' => false],
        ],
    ],
];
