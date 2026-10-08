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

use mindstellar\admin\ui\FormSpec;
use mindstellar\base\SettingsScreen;

/**
 * The listing settings screen (Listings > Settings).
 *
 * 'moderate_items' holds -1 while moderation is off and otherwise the number of validated
 * listings a user needs, so the switch stores the number box beside it.
 */
final class ItemSettingsScreen extends SettingsScreen
{
    public const PAGE_ID = 'core.settings_items';
    protected const ACTION    = 'settings_post';
    protected const FORM_NAME = 'items_form';

    /** What 'moderate_items' holds while moderation is switched off. */
    public const MODERATION_OFF = -1;

    protected static function declareFields(): void
    {
        $moderated = osc_moderate_items();

        $form = CoreSettings::page(self::PAGE_ID, __('Listing Settings'))
            // The two lengths are one rule with its own wording, checked together.
            ->onValidate(static function (array $values) {
                $errors = \ItemActions::lengthSettingErrors(
                    $values['max_chars_per_title'] ?? '',
                    $values['max_chars_per_description'] ?? ''
                );

                return array_values(array_filter(explode(PHP_EOL, $errors)));
            })
            ->group(__('Listing Settings'));

        self::rowOpen($form, 'settings', __('Settings'));
        $form
            ->checkbox('reg_user_post', __('Only logged in users can post listings'))
                ->set('row', false)
            ->number('items_wait_time', __('Wait time'), __('If the value is set to zero, there is no wait period'))
                ->set('row', false)
                ->prefix(__('An user has to wait'))
                ->suffix(__('seconds between each listing added'))
                ->clampMin(0)
                ->default(0)
            ->checkbox('moderate_admin_post', __('Hold new listings for admin moderation'))
                ->set('row', false)
            ->checkbox('moderate_admin_edit', __('Hold edited listings for admin moderation'))
                ->set('row', false)
            ->checkbox('moderate_items', __('Users have to validate their listings'))
                ->set('row', false)
                ->persist(static function ($value, array $values) {
                    return $value ? (string)($values['num_moderate_items'] ?? 0) : (string)self::MODERATION_OFF;
                })
                // Stored as a count, not a tick, so the control cannot read it back.
                ->writeOnly()
                ->default($moderated !== self::MODERATION_OFF)
            ->custom('moderated_open', static function () {
                echo '<div class="num-moderated-items">';
            })
                ->set('row', false)
            ->number(
                'num_moderate_items',
                __('Number of moderated listings'),
                __('If the value is zero, it means that each listing must be validated')
            )
                ->set('row', false)
                ->prefix(__('After'))
                ->suffix(__("validated listings the user doesn't need to validate the listings any more"))
                ->clampMin(0)
                // Not stored: it is the value the switch above stores.
                ->persist(false)
                ->default($moderated === self::MODERATION_OFF ? 0 : $moderated)
            ->checkbox('logged_user_item_validation', __("Logged in users don't need to validate their listings"))
                ->set('row', false)
            ->custom('moderated_close', static function () {
                echo '</div>';
            })
                ->set('row', false)
            ->checkbox('enabled_recaptcha_items', __('Show reCAPTCHA in add/edit listing form'))
                ->set('row', false)
                ->set('help_html', __('<strong>Remember</strong> that you must configure reCAPTCHA first'));
        self::rowClose($form, 'settings');

        self::rowOpen($form, 'contact', __('Contact publisher'));
        $form
            ->checkbox('reg_user_can_contact', __('Only allow registered users to contact publisher'))
                ->set('row', false)
            ->checkbox('item_attachment', __('Allow attached files in contact publisher form'))
                ->set('row', false);
        self::rowClose($form, 'contact');

        self::rowOpen($form, 'share', __('Share listing'));
        $form
            ->checkbox(
                'enable_send_friend',
                __('Enable the "send to a friend" form'),
                __('This form emails a listing to a recipient the visitor types in. Off by default because it can be abused to relay mail; enable it only if you need it.')
            )
                ->set('row', false)
            ->checkbox('reg_user_can_send_friend', __('Only allow registered users to share listings'))
                ->set('row', false)
                // Never saved reads as on: only an explicit '0' turns it off.
                ->default(true);
        self::rowClose($form, 'share');

        self::rowOpen($form, 'notify', __('Notifications'));
        $form
            ->checkbox('notify_new_item', __('Notify admin when a new listing is added'))
                ->set('row', false)
            ->checkbox('notify_contact_item', __('Send admin a copy of the "contact publisher" email'))
                ->set('row', false)
            ->checkbox('notify_contact_friends', __('Send admin a copy to "share listing" email'))
                ->set('row', false);
        self::rowClose($form, 'notify');

        $form
            ->number(
                'warn_expiration',
                __('Warn about expiration'),
                __('This option will send an email X days before an ad expires to the author. 0 for no email.')
            )
                ->suffix(__('days'))
                ->clampMin(0)
                ->default(0)
            // The browser gets the range; the page rule above reports it in its own words.
            ->number('max_chars_per_title', __('Title length'), sprintf(__('Titles can be 1 to %d characters.'), \Item::TITLE_WIDTH))
                ->column('title_character_length')
                ->suffix(__('characters'))
                ->attrs(array('min' => '1', 'max' => (string)\Item::TITLE_WIDTH))
                ->writeOnly()
                ->default(osc_max_characters_per_title())
            ->number(
                'max_chars_per_description',
                __('Description length'),
                sprintf(__('Descriptions can be 1 to %d characters.'), \ItemActions::DESCRIPTION_MAX)
            )
                ->column('description_character_length')
                ->suffix(__('characters'))
                ->attrs(array('min' => '1', 'max' => (string)\ItemActions::DESCRIPTION_MAX))
                ->writeOnly()
                ->default(osc_max_characters_per_description())
            ->checkbox('tinymce', __('Enable TinyMCE on frontend'))
                ->rowLabel(__('Rich Edit'))
                ->column('tinymce_frontend');

        self::rowOpen($form, 'optional', __('Optional fields'));
        $form
            ->checkbox('enableField#f_price@items', __('Price'))
                ->set('row', false)
            ->checkbox('enableField#images@items', __('Attach images'))
                ->set('row', false)
            ->number(
                'numImages@items',
                __('Images per listing'),
                __('If the value is zero, it means an unlimited number of images is allowed')
            )
                ->set('row', false)
                ->prefix(__('Attach'))
                ->suffix(__('images per listing'))
                ->clampMin(0)
                ->default(0);
        self::rowClose($form, 'optional');

        $form
            ->select('map_type', __('Maps'), array(
                '0'          => __('None'),
                'google'     => __('Google Maps'),
                'openstreet' => __('OpenStreetMaps'),
            ), __('Set the API key in Settings -> General.'))
                ->default('0')
            ->register();
    }

    /**
     * What the view needs to draw the form. It posts to the listings controller.
     *
     * @param array<string,mixed>|null $values values a rejected save is handing back
     *
     * @return array<string,mixed>
     */
    public static function formVars(?array $values = null): array
    {
        $vars          = parent::formVars($values);
        $vars['route'] = array('page' => 'items', 'action' => self::ACTION);

        return $vars;
    }

    /**
     * Open one labelled row that several controls share.
     */
    private static function rowOpen(FormSpec $form, string $key, string $label): void
    {
        $form
            ->custom($key . '_row_open', static function () use ($label) {
                osc_admin_form_row_open($label);
            })
                ->set('row', false);
    }

    /**
     * Close the row rowOpen() opened.
     */
    private static function rowClose(FormSpec $form, string $key): void
    {
        $form
            ->custom($key . '_row_close', static function () {
                osc_admin_form_row_close();
            })
                ->set('row', false);
    }
}
