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

namespace mindstellar\base;

use mindstellar\admin\form\CoreSettings;

/**
 * Base for a core settings screen that is one declared page. A child sets PAGE_ID, ACTION and
 * FORM_NAME and declares its fields in declareFields(); a child with a different formVars()
 * overrides it.
 */
abstract class SettingsScreen
{
    public const PAGE_ID = '';

    /** The settings-controller action the form posts to. */
    protected const ACTION = '';

    /** The form's name attribute. */
    protected const FORM_NAME = '';

    /**
     * Declare the form, once per request.
     *
     * @return string the page id
     */
    public static function register(): string
    {
        if (osc_settings_page(static::PAGE_ID) === null) {
            static::declareFields();
        }

        return static::PAGE_ID;
    }

    /**
     * What the view needs to draw the form.
     *
     * @param array<string,mixed>|null $values values a rejected save is handing back, or null
     *                                         for the stored ones
     *
     * @return array<string,mixed> view variables for osc_admin_settings_form()
     */
    public static function formVars(?array $values = null): array
    {
        return CoreSettings::vars(
            static::register(),
            static::ACTION,
            $values,
            array('name' => static::FORM_NAME)
        );
    }

    /**
     * Declare the page's fields.
     */
    abstract protected static function declareFields(): void;
}
