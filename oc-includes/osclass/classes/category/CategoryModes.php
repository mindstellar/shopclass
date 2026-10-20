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

namespace mindstellar\category;

/**
 * A mode per category, as the category_modes settings field stores it: category id => mode,
 * with "off" for a subcategory switched off. A category without a mode follows its parent.
 */
final class CategoryModes
{
    /** A mode value. */
    public const MODE = '/^[A-Za-z0-9_-]+$/';

    /** The mode a subcategory takes to switch off what its parent turned on. */
    public const OFF = 'off';

    /**
     * Category id => mode from the stored JSON or an array of that shape. With $allowed
     * (mode => label) only those modes and "off" are kept.
     *
     * @param mixed                     $value
     * @param array<string,string>|null $allowed
     *
     * @return array<int,string>
     */
    public static function parse($value, ?array $allowed = null): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        $modes = array();
        foreach (is_array($value) ? $value : array() as $catId => $mode) {
            if ((int)$catId <= 0 || !is_string($mode) || !preg_match(self::MODE, $mode)) {
                continue;
            }
            if ($allowed !== null && $mode !== self::OFF && !isset($allowed[$mode])) {
                continue;
            }
            $modes[(int)$catId] = $mode;
        }
        ksort($modes);

        return $modes;
    }

    /**
     * The mode $categoryId takes: its own, or the nearest parent's. '' when it is off or
     * nothing above it is set.
     *
     * @param mixed $value the stored JSON or an id => mode array
     */
    public static function modeFor($value, int $categoryId): string
    {
        $modes = self::parse($value);
        if ($modes === array() || $categoryId <= 0) {
            return '';
        }
        // hierarchy() runs from the category up to the root, so the nearest mode is met first.
        $chain = array($categoryId);
        foreach ((array)\Category::getInstance()->hierarchy($categoryId) as $row) {
            $chain[] = (int)($row['pk_i_id'] ?? 0);
        }
        foreach ($chain as $id) {
            if (isset($modes[$id])) {
                return $modes[$id] === self::OFF ? '' : $modes[$id];
            }
        }

        return '';
    }
}

/* file end: ./oc-includes/osclass/classes/category/CategoryModes.php */
