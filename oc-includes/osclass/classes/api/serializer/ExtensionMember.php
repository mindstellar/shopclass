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
 * One field a plugin declared with osc_api_register_field(): sent as
 * `ext.<slug>.<name>` on a listing, user or category, in the views it names.
 */
final class ExtensionMember
{
    /**
     * @param string              $object ExtensionMembers::OBJECTS
     * @param array<string,mixed> $schema
     * @param string[]            $views  ViewContext views it is sent in
     */
    public function __construct(
        private string $object,
        private string $slug,
        private string $name,
        private array $schema,
        private array $views
    ) {
        $this->views = array_values($views);
    }

    public function object(): string
    {
        return $this->object;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string,mixed>
     */
    public function schema(): array
    {
        return $this->schema;
    }

    /**
     * @return string[]
     */
    public function views(): array
    {
        return $this->views;
    }

    /**
     * Whether the field is sent in a view. Views widen in order: what the public sees, the
     * owner and admins see too.
     */
    public function visibleIn(string $view): bool
    {
        $rank = array_search($view, ViewContext::VIEWS, true);
        foreach ($this->views as $declared) {
            $needed = array_search($declared, ViewContext::VIEWS, true);
            if ($rank !== false && $needed !== false && $needed <= $rank) {
                return true;
            }
        }

        return false;
    }
}
