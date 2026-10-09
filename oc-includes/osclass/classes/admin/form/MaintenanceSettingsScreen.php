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

namespace mindstellar\admin\form;

/**
 * Tools -> Maintenance: whether the public site is blocked, and the message visitors see.
 */
final class MaintenanceSettingsScreen extends ToolsSettingsScreen
{
    public const PAGE_ID = 'core.tools_maintenance';
    protected const ACTION       = 'maintenance';
    protected const FORM_NAME    = 'maintenance_form';
    protected const ROUTE_FIELDS = array('mode' => 'save');

    protected static function declareFields(): void
    {
        CoreSettings::page(self::PAGE_ID, __('Maintenance'), OSC_MAINTENANCE_PREF_SECTION)
            ->checkbox(
                OSC_MAINTENANCE_PREF_LOCKOUT,
                __('Block the public site (HTTP 503)'),
                __('Unchecked, visitors keep using the site and see the message below as a banner. The choice is kept when maintenance mode is turned off.')
            )
                ->rowLabel(__('Public site'))
                ->set('id', 'maintenance_lockout')
                // Never saved means blocked, as osc_maintenance_lockout_enabled() reads it.
                ->default(true)
            ->textarea(
                OSC_MAINTENANCE_PREF_MESSAGE,
                __('Message'),
                __('Shown on the banner, and on the 503 page. Plain text only. Leave blank for the default message.')
            )
                ->set('id', 'maintenance_message')
                ->set('rows', 4)
                ->set('maxlength', OSC_MAINTENANCE_MESSAGE_MAX)
                ->purify(false)
                ->sanitize(static fn ($value): string => osc_sanitize_maintenance_message((string) $value))
            ->register();
    }
}
