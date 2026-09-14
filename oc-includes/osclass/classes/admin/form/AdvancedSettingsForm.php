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
 * The advanced settings screen: which part of a URL becomes a subdomain, and the host the
 * subdomains hang off.
 *
 * Both controls keep the names the screen has always posted and are stored under the
 * preference keys every reader already uses, so nothing outside this file learns that the
 * screen was rewritten.
 *
 * Recalculating the location slugs is a real save-time effect -- change the subdomain type
 * and every country, region and city needs a slug of the shape the new type addresses them
 * by -- so it is declared rather than run by the controller, and it therefore no longer
 * runs after a submission that was refused.
 *
 * @package mindstellar\admin\form
 */
final class AdvancedSettingsForm
{
    public const PAGE_ID = 'core.settings_advanced';

    /** The parts of a URL a subdomain may stand for. Anything else is no subdomains at all. */
    public const TYPES = array('category', 'country', 'region', 'city', 'user');

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

        $host = parse_url(\Params::getServerParam('HTTP_HOST'), PHP_URL_HOST);
        if ($host === null) {
            $host = \Params::getServerParam('HTTP_HOST');
        }

        CoreSettings::page(self::PAGE_ID, __('Advanced Settings'))
            ->onAfterSave(static function () {
                osc_calculate_location_slug(osc_subdomain_type());
            })
            ->select('e_type', __('Subdomain type'), array(
                ''         => __('No subdomains'),
                'category' => __('Category based'),
                'country'  => __('Country based'),
                'region'   => __('Region based'),
                'city'     => __('City based'),
                'user'     => __('User based'),
            ))
                ->column('subdomain_type')
            ->text('s_host', __('Host'))
                ->column('subdomain_host')
                ->width('key')
                ->set(
                    'help_html',
                    osc_esc_html(__('Your host is required to know the subdomain.')) . ' '
                    . osc_esc_html(sprintf(__('Your current host is "%s". Add it without "www".'), $host)) . ' '
                    . osc_esc_html(__('Remember to enable cookies for the subdomains too.'))
                )
            // A float clear the screen has carried since it was hand-written; it is markup
            // this migration is not the place to decide about.
            ->custom('layout_clear', static function () {
                echo '<div class="clear"></div>';
            })
                ->set('row', false)
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
            'advanced_post',
            $values,
            array('name' => 'settings_form')
        );
    }
}
