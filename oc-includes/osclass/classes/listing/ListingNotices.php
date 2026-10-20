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

namespace mindstellar\listing;

/**
 * Shows what went wrong with a listing's photos, on the admin or the public message channel.
 */
final class ListingNotices
{
    /**
     * @param string[] $notices
     */
    public static function flash(array $notices, bool $admin): void
    {
        foreach ($notices as $notice) {
            osc_add_flash_error_message($notice, $admin ? 'admin' : 'pubMessages');
        }
    }
}
