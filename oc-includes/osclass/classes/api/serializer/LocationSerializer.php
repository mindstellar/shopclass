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

/**
 * The reference lists: countries, regions, cities, city areas and currencies.
 */
final class LocationSerializer
{
    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function country(array $row): array
    {
        return [
            'code' => strtoupper((string) ($row['pk_c_code'] ?? '')),
            'name' => (string) ($row['s_name'] ?? ''),
            'slug' => Format::text($row['s_slug'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function region(array $row): array
    {
        return [
            'id'           => Format::int($row['pk_i_id'] ?? 0),
            'country_code' => strtoupper((string) ($row['fk_c_country_code'] ?? '')),
            'name'         => (string) ($row['s_name'] ?? ''),
            'slug'         => Format::text($row['s_slug'] ?? null),
            'lat'          => Format::float($row['d_coord_lat'] ?? null),
            'lng'          => Format::float($row['d_coord_long'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function city(array $row): array
    {
        return [
            'id'           => Format::int($row['pk_i_id'] ?? 0),
            'region_id'    => Format::int($row['fk_i_region_id'] ?? 0),
            'country_code' => Format::text(isset($row['fk_c_country_code']) ? strtoupper((string) $row['fk_c_country_code']) : null),
            'name'         => (string) ($row['s_name'] ?? ''),
            'slug'         => Format::text($row['s_slug'] ?? null),
            'lat'          => Format::float($row['d_coord_lat'] ?? null),
            'lng'          => Format::float($row['d_coord_long'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function area(array $row): array
    {
        return [
            'id'      => Format::int($row['pk_i_id'] ?? 0),
            'city_id' => Format::int($row['fk_i_city_id'] ?? 0),
            'name'    => (string) ($row['s_name'] ?? ''),
        ];
    }

    /**
     * A currency.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function currency(array $row): array
    {
        return [
            'code'   => strtoupper((string) ($row['pk_c_code'] ?? '')),
            'name'   => (string) ($row['s_name'] ?? ''),
            'symbol' => Format::text($row['s_description'] ?? null),
        ];
    }
}
