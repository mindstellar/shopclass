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
 * Tools -> Activity log: whether activity is recorded, and how long it is kept.
 */
final class LogSettingsScreen extends ToolsSettingsScreen
{
    public const PAGE_ID = 'core.tools_logs';
    protected const ACTION    = 'logs_settings_post';
    protected const FORM_NAME = 'log_settings_form';

    /** Days kept when nothing is saved, as osc_admin_log_retention_days() answers. */
    public const DEFAULT_RETENTION = 90;

    protected static function declareFields(): void
    {
        CoreSettings::page(self::PAGE_ID, __('Activity log'))
            ->checkbox(
                'admin_log_enabled',
                __('Record admin and listing activity'),
                __('Turn logging off to stop recording new entries. Existing entries are kept until pruned.')
            )
                ->rowLabel(__('Record activity'))
                ->set('id', 'admin_log_enabled')
                ->default(true)
            ->number(
                'admin_log_retention_days',
                __('Keep entries for'),
                __('The daily task deletes entries older than this. Set to 0 to keep them forever.')
            )
                ->set('id', 'admin_log_retention_days')
                ->suffix(__('days'))
                ->default(self::DEFAULT_RETENTION)
                ->clampMin(0)
            ->register();
    }
}
