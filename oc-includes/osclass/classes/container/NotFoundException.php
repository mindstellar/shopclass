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

namespace mindstellar\container;

/**
 * Nothing is registered under the id, and it does not name a class that can be built with no arguments.
 */
final class NotFoundException extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('No service is registered as "%s".', $id));
    }
}
