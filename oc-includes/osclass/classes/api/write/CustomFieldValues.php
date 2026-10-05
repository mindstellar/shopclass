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

namespace mindstellar\api\write;

use Params;

/**
 * Custom field values as the listing form would post them: only the target category's
 * fields, each in the shape its type takes, purified as the form's request values are.
 */
final class CustomFieldValues
{
    /** @var \Closure(int): array<int,array<string,mixed>> */
    private \Closure $definitions;

    /**
     * @param \Closure|null $definitions fn(category id) => its field rows; Field::findByCategory() by default
     */
    public function __construct(?\Closure $definitions = null)
    {
        $this->definitions = $definitions ?? static fn (int $categoryId): array => (array) \Field::newInstance()->findByCategory($categoryId);
    }

    /**
     * @param array<int|string,mixed> $meta field id => value
     *
     * @return array<int,mixed> the values the category's fields take; a category with no
     *                          fields takes none
     */
    public function clean(int $categoryId, array $meta): array
    {
        if ($meta === []) {
            return [];
        }
        $types = [];
        foreach (($this->definitions)($categoryId) as $field) {
            $types[(int) $field['pk_i_id']] = (string) ($field['e_type'] ?? 'TEXT');
        }
        $out = [];
        foreach ($meta as $id => $value) {
            $type = $types[(int) $id] ?? null;
            if ($type === null || !ctype_digit((string) $id)) {
                continue;
            }
            $value = self::shaped($type, $value);
            if ($value !== null) {
                $out[(int) $id] = Params::purifyText($value);
            }
        }

        return $out;
    }

    /**
     * A date range is {from, to}; every other type one value.
     *
     * @return string|array{from:string,to:string}|null null for a value of the wrong shape
     */
    private static function shaped(string $type, mixed $value): string|array|null
    {
        if ($type === 'DATEINTERVAL') {
            if (!is_array($value)) {
                return null;
            }
            $range = [];
            foreach (['from', 'to'] as $end) {
                $range[$end] = is_scalar($value[$end] ?? null) ? (string) $value[$end] : '';
            }

            return $range;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
