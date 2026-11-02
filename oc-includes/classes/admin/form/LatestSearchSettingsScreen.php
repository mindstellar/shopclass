<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

use mindstellar\base\SettingsScreen;

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
final class LatestSearchSettingsScreen extends SettingsScreen
{
    public const PAGE_ID = 'core.settings_latest_searches';
    protected const ACTION    = 'latestsearches_post';
    protected const FORM_NAME = 'searches_form';

    /** The retention answers offered as presets; anything else is the free number. */
    public const PRESETS = array('hour', 'day', 'week', 'forever', '1000');

    protected static function declareFields(): void
    {
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
                // @phpstan-ignore argument.type (options may carry a label and id per choice)
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
    }
}
