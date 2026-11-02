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

namespace mindstellar\fields;

use Field;
use mindstellar\routing\ReservedSlugs;

/**
 * A custom field's slug: lower case letters, digits, `_` and `-`, and not another field's.
 */
final class FieldSlug
{
    private function __construct()
    {
    }

    /**
     * The slug for a name or a typed slug, with `_1`, `_2`... added while another field holds it
     * or it is reserved, as a field group's slug is.
     *
     * @param int $self the field being saved, which may keep its own slug; 0 for a new one
     */
    public static function unique(string $wanted, int $self = 0): string
    {
        $base = (string) preg_replace('|([-]+)|', '-', (string) preg_replace('|[^a-z0-9_-]|', '-', strtolower($wanted)));

        return ReservedSlugs::unique($base, static function (string $slug) use ($self): bool {
            $field = Field::getInstance()->findBySlug($slug);

            return $field && (int) $field['pk_i_id'] !== $self;
        });
    }
}
