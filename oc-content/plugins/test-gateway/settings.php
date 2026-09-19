<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The settings page declaration. Core renders the form, checks CSRF and the admin's
 * capability, validates each field and stores it under the "test-gateway" section.
 */

return array(
    'title'  => __('Test payments', 'test-gateway'),
    'menu'   => 'plugins',
    'intro'  => __('A payment method that moves no money, for testing credits, upgrades and refunds. Never turn it on for a live site.', 'test-gateway'),
    'groups' => array(
        array(
            'title'  => __('Test mode', 'test-gateway'),
            'fields' => array(
                array(
                    'type'      => 'checkbox',
                    'name'      => 'enabled',
                    'label'     => __('Offer test payments at checkout', 'test-gateway'),
                    'row_label' => __('Test mode', 'test-gateway'),
                    'help'      => __('Buyers get credits without paying. An admin notice shows while this is on.', 'test-gateway'),
                ),
                array(
                    'type'      => 'text',
                    'name'      => 'name',
                    'label'     => __('Name at checkout', 'test-gateway'),
                    'help'      => __('Always shown with "(Test)" after it.', 'test-gateway'),
                    'default'   => __('Test payment', 'test-gateway'),
                    'maxlength' => 60,
                    'depends'   => 'enabled',
                    'required'  => true,
                ),
            ),
        ),
        array(
            'title'  => __('Behaviour', 'test-gateway'),
            'fields' => array(
                array(
                    'type'    => 'select',
                    'name'    => 'mode',
                    'label'   => __('Checkout', 'test-gateway'),
                    'help'    => __('Instant applies one outcome as soon as the buyer continues.', 'test-gateway'),
                    'options' => array(
                        'choose' => __('Test checkout page', 'test-gateway'),
                        'auto'   => __('Instant, no page', 'test-gateway'),
                    ),
                    'default' => 'choose',
                ),
                array(
                    'type'          => 'radio',
                    'name'          => 'auto_outcome',
                    'label'         => __('Outcome', 'test-gateway'),
                    'options'       => array(
                        'paid'     => __('Paid', 'test-gateway'),
                        'declined' => __('Declined', 'test-gateway'),
                        'pending'  => __('Left pending', 'test-gateway'),
                    ),
                    'default'       => 'paid',
                    'depends'       => 'mode',
                    'depends_value' => 'auto',
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'window',
                    'label'    => __('Accept callbacks signed within', 'test-gateway'),
                    'suffix'   => __('minutes', 'test-gateway'),
                    'help'     => __('An older callback is refused, even with a valid signature.', 'test-gateway'),
                    'min'      => 1,
                    'max'      => 1440,
                    'default'  => 30,
                    'required' => true,
                ),
                array(
                    'type'        => 'text',
                    'name'        => 'currencies',
                    'label'       => __('Currencies', 'test-gateway'),
                    'placeholder' => 'USD, EUR',
                    'help'        => __('Comma-separated codes. Leave empty to use the billing currency.', 'test-gateway'),
                    'sanitize'    => static function ($value) {
                        $codes = array_filter(array_map('trim', explode(',', strtoupper((string) $value))));

                        return implode(', ', array_unique($codes));
                    },
                    'validate'    => static function ($value) {
                        foreach (explode(',', (string) $value) as $code) {
                            if (!preg_match('/^[A-Z]{3}$/', trim($code))) {
                                return sprintf(__('"%s" is not a three-letter currency code', 'test-gateway'), trim($code));
                            }
                        }

                        return null;
                    },
                ),
                array(
                    'type'      => 'checkbox',
                    'name'      => 'show_payload',
                    'label'     => __('Show the signed callback on the checkout page', 'test-gateway'),
                    'row_label' => __('Developer', 'test-gateway'),
                    'help'      => __('Adds a curl command that posts it to the real callback URL, for replay tests.', 'test-gateway'),
                ),
            ),
        ),
    ),
);
