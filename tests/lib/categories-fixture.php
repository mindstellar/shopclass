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

/**
 * Category trees in the shape of Category::toTreeAll(), for the Categories screen tests:
 * the mockup's demo set and a large five-level tree for timing.
 */

/**
 * Builds one toTreeAll row and its children from [name, own listings, expiry, price, off, kids].
 *
 * @param array<int,mixed> $node
 * @param int              $next Next free id, advanced as rows are made
 *
 * @return array<string,mixed>
 */
function categories_fixture_row(array $node, ?int $parent, int $position, int &$next): array
{
    [$name, $own, $expiry, $price, $off, $kids] = $node;
    $id   = $next++;
    $row  = array(
        'pk_i_id'           => $id,
        'fk_i_parent_id'    => $parent,
        'i_expiration_days' => (int) $expiry,
        'i_position'        => $position,
        'b_enabled'         => $off ? 0 : 1,
        'b_price_enabled'   => $price ? 1 : 0,
        'i_num_items'       => (int) $own,
        'fk_c_locale_code'  => 'en_US',
        's_name'            => (string) $name,
        's_description'     => '',
        's_slug'            => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $name)), '-'),
        'categories'        => array(),
    );
    $sum = (int) $own;
    foreach ($kids as $i => $kid) {
        $child                = categories_fixture_row($kid, $id, $i, $next);
        $sum                 += $child['i_num_items'];
        $row['categories'][]  = $child;
    }
    $row['i_num_items'] = $sum;

    return $row;
}

/**
 * The mockup's seed: 8 top-level groups, 97 categories. A parent's i_num_items counts its
 * subtree, as the real table does; Vehicles holds 30.
 *
 * @return array<int,array<string,mixed>>
 */
function categories_fixture_demo(): array
{
    $seed = json_decode(<<<'JSON'
[["For sale",0,0,1,0,[["Art - Collectibles",0,0,1,0,[]],["Barter",0,0,1,0,[]],["Books - Magazines",1,0,1,0,[]],["Cameras - Camera Accessories",2,0,1,0,[]],["CDs - Records",0,0,1,0,[]],["Cell Phones - Accessories",2,0,1,0,[]],["Clothing",1,0,1,0,[]],["Computers - Hardware",3,0,1,0,[]],["DVD",0,0,1,0,[]],["Electronics",1,0,1,0,[]],["For Babies - Infants",1,0,1,0,[]],["Garage Sale",0,0,1,0,[]],["Health - Beauty",0,0,1,0,[]],["Home - Furniture - Garden Supplies",8,0,1,0,[]],["Jewelry - Watches",2,0,1,0,[]],["Musical Instruments",2,0,1,0,[]],["Sporting Goods - Bicycles",2,0,1,0,[]],["Tickets",0,0,1,0,[]],["Toys - Games - Hobbies",0,0,1,0,[]],["Video Games - Consoles",1,0,1,0,[]],["Everything Else",0,0,1,0,[]]]],["Vehicles",0,60,1,0,[["Cars",19,45,1,0,[]],["Car Parts",1,60,1,0,[["Engine - Gearbox",1,60,1,0,[]],["Tyres - Wheels",0,60,1,0,[["Winter tyres",2,60,1,0,[]]]]]],["Motorcycles",8,60,1,0,[]],["Boats - Ships",1,90,1,0,[]],["RVs - Campers - Caravans",0,60,1,0,[]],["Trucks - Commercial Vehicles",1,60,1,0,[]],["Other Vehicles",0,60,1,0,[]]]],["Classes",0,0,1,0,[["Computer - Multimedia Classes",0,0,1,0,[]],["Language Classes",1,0,1,0,[]],["Music - Theatre - Dance Classes",1,0,1,0,[]],["Tutoring - Private Lessons",1,0,1,0,[]],["Other Classes",0,0,1,0,[]]]],["Real estate",0,90,1,0,[["Houses - Apartments for Sale",1,90,1,0,[]],["Houses - Apartments for Rent",1,90,1,0,[]],["Rooms for Rent - Shared",1,90,1,0,[]],["Housing Swap",0,90,1,0,[]],["Vacation Rentals",1,90,1,0,[]],["Parking Spots",0,90,1,0,[]],["Land",1,90,1,0,[]],["Office - Commercial Space",1,90,1,0,[]],["Shops for Rent - Sale",0,90,1,0,[]]]],["Services",0,0,1,0,[["Babysitter - Nanny",0,0,1,0,[]],["Casting - Auditions",0,0,1,0,[]],["Computer",1,0,1,0,[]],["Event Services",1,0,1,0,[]],["Health - Beauty - Fitness",1,0,1,0,[]],["Horoscopes - Tarot",0,0,1,1,[]],["Household - Domestic Help",0,0,1,0,[]],["Moving - Storage",1,0,1,0,[]],["Repair",1,0,1,0,[]],["Writing - Editing - Translating",0,0,1,0,[]],["Other Services",0,0,1,0,[]]]],["Community",0,0,0,0,[["Carpool",0,0,0,0,[]],["Community Activities",0,0,0,0,[]],["Events",1,0,0,0,[]],["Musicians - Artists - Bands",0,0,0,0,[]],["Volunteers",0,0,0,0,[]],["Lost And Found",0,0,0,0,[]]]],["Personals",0,0,0,1,[["Women looking for Men",0,0,0,1,[]],["Men looking for Women",0,0,0,1,[]],["Men looking for Men",0,0,0,1,[]],["Women looking for Women",0,0,0,1,[]],["Friendship - Activity Partners",0,0,0,1,[]],["Missed Connections",2,0,0,1,[]]]],["Jobs",0,30,1,0,[["Accounting - Finance",0,30,1,0,[]],["Advertising - Public Relations",0,30,1,0,[]],["Arts - Entertainment - Publishing",0,30,1,0,[]],["Clerical - Administrative",0,30,1,0,[]],["Customer Service",1,30,1,0,[]],["Education - Training",0,30,1,0,[]],["Engineering - Architecture",0,30,1,0,[]],["Healthcare",0,30,1,0,[]],["Human Resource",0,30,1,0,[]],["Internet",0,30,1,0,[]],["Legal",0,30,1,0,[]],["Manual Labor",1,30,1,0,[]],["Manufacturing - Operations",0,30,1,0,[]],["Marketing",0,30,1,0,[]],["Non-profit - Volunteer",0,30,1,0,[]],["Real Estate",0,30,1,0,[]],["Restaurant - Food Service",1,30,1,0,[]],["Retail",0,30,1,0,[]],["Sales",0,30,1,0,[]],["Technology",1,30,1,0,[]],["Other Jobs",1,30,1,0,[]]]]]
JSON, true);
    $next = 1;
    $tree = array();
    foreach ($seed as $i => $node) {
        $tree[] = categories_fixture_row($node, null, $i, $next);
    }

    return $tree;
}

/**
 * A deterministic tree of about $n categories, five levels deep, four children per parent.
 *
 * @return array<int,array<string,mixed>>
 */
function categories_fixture_big(int $n = 1500): array
{
    $roots = 10;
    $made  = 0;
    $limit = 0;
    $make  = function (string $label, int $depth) use (&$make, &$made, &$limit): array {
        $made++;
        $kids = array();
        if ($depth < 5) {
            for ($i = 1; $i <= 4 && $made < $limit; $i++) {
                $kids[] = $make($label . '.' . $i, $depth + 1);
            }
        }
        $own = ($made * 7) % 5;
        $exp = array(0, 30, 60, 90)[$made % 4];

        return array(($depth === 1 ? 'Group ' : 'Category ') . $label, $own, $exp, 1, $made % 23 === 0 ? 1 : 0, $kids);
    };
    $seed = array();
    for ($r = 1; $r <= $roots; $r++) {
        $limit = intdiv($n * $r, $roots);
        $seed[] = $make((string) $r, 1);
    }
    $next = 1;
    $tree = array();
    foreach ($seed as $i => $node) {
        $tree[] = categories_fixture_row($node, null, $i, $next);
    }

    return $tree;
}

/* file end: ./tests/lib/categories-fixture.php */
