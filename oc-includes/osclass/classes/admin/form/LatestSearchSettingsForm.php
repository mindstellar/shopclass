<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

/**
 * The latest-searches settings screen: whether visitors' queries are kept, and for how long.
 *
 * How long is one preference written from two controls -- a list of presets and a free
 * number -- so the page's own script writes the answer into a hidden field and that field
 * is what is stored. The presets are declared as a control that goes nowhere; the hidden
 * field carries the value, under the preference key every reader already uses.
 *
 * @package mindstellar\admin\form
 */
final class LatestSearchSettingsForm
{
    public const PAGE_ID = 'core.settings_latest_searches';

    /** The retention answers offered as presets; anything else is the free number. */
    public const PRESETS = array('hour', 'day', 'week', 'forever', '1000');

    /**
     * Declare the form, once per request.
     *
     * @return string the page id
     */
    public static function register(): string
    {
        if (osc_settings_page(self::PAGE_ID) !== null) {
            return self::PAGE_ID;
        }

        $stored   = osc_purge_latest_searches();
        $isCustom = !in_array($stored, self::PRESETS, true);

        CoreSettings::page(self::PAGE_ID, __('Latest searches Settings'))
            ->checkbox(
                'save_latest_searches',
                __('Save the latest user searches'),
                __('It may be useful to know what queries users make.')
            )
                ->rowLabel(__('Latest searches'))
            ->radio(
                'purge_searches',
                __('How long queries are stored'),
                array(
                    'hour'    => __('One hour'),
                    'day'     => __('One day'),
                    'week'    => __('One week'),
                    'forever' => __('Forever'),
                    '1000'    => __('Store 1000 queries'),
                    'custom'  => array(
                        'label'       => __('Store'),
                        'custom_html' => '<input type="number" min="0" name="custom_queries" id="custom_queries"'
                            . ' class="input-text field-inline-text"'
                            . ' value="' . ($isCustom ? osc_esc_html($stored) : '') . '" />'
                            . '<span class="field-suffix">' . osc_esc_html(__('queries')) . '</span>',
                    ),
                ),
                __("This feature can generate a lot of data. It's recommended to purge this data periodically.")
            )
                // The presets pick the answer; the hidden field below carries it.
                ->persist(false)
                ->default($isCustom ? 'custom' : (string)$stored)
            ->hidden('customPurge', __('Custom number'))
                // The id this page's own script writes the chosen answer into. The derived
                // one would be field-customPurge, and the lookup would find nothing.
                ->set('id', 'customPurge')
                ->column('purge_latest_searches')
                ->required()
            ->register();

        return self::PAGE_ID;
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
            self::register(),
            'latestsearches_post',
            $values,
            array('name' => 'searches_form')
        );
    }
}
