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

namespace mindstellar\admin\ajax;

use mindstellar\utility\AjaxResponse;
use Params;

/**
 * The signed-in admin's own display choices, and the date-format preview.
 */
final class PreferenceAjax extends AjaxHandler
{
    public function dateFormat(): void
    {
        AjaxResponse::json(array(
            'format'        => Params::getParam('format'),
            'str_formatted' => osc_format_date(date('Y-m-d H:i:s'), Params::getParam('format'))
        ));
    }

    /** Persist this admin's light/dark choice. */
    public function saveAdminTheme(): void
    {
        $theme = Params::getParam('theme');
        // Whitelisted: the value is echoed into the data-bs-theme attribute on every page load.
        if ($theme !== 'dark' && $theme !== 'light') {
            $theme = 'light';
        }
        self::save('admin_theme', $theme);
        AjaxResponse::json(array('done' => 1, 'theme' => $theme));
    }

    /** Persist this admin's collapsed/expanded sidebar choice. */
    public function saveSidebarState(): void
    {
        $state = Params::getParam('state');
        // Whitelisted: the value is echoed into the data-osc-sidebar attribute on every page load.
        if ($state !== 'collapsed' && $state !== 'expanded') {
            $state = 'expanded';
        }
        self::save('admin_sidebar', $state);
        AjaxResponse::json(array('done' => 1, 'state' => $state));
    }

    /** One t_preference row per admin, keyed by admin id under $section. */
    private static function save(string $section, string $value): void
    {
        osc_set_preference((string) osc_logged_admin_id(), $value, $section, 'STRING');
        osc_reset_preferences();
    }
}
