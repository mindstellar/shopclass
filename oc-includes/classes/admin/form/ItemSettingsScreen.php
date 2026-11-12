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
        $photosMin = osc_item_photos_min();
        $level     = osc_item_required_location();
        $modes     = array('required' => __('Required'), 'optional' => __('Optional'), 'off' => __('Off'));
        // The old checkboxes posted '1' or nothing, which still mean Optional and Off.
        $posted  = static fn ($value): string => match ((string) $value) {
            '1'     => 'optional',
            '', '0' => 'off',
            default => (string) $value,
        };
        $enabled = static fn ($value): string => $value === 'off' ? '0' : '1';

        $form = CoreSettings::page(self::PAGE_ID, osc_mode_text(__('Listing Settings'), __('Product Settings')))
            // The two lengths are one rule with its own wording, checked together, and the
            // photo minimum cannot pass the photo limit.
            ->onValidate(static function (array $values) {
                $errors = \ItemActions::lengthSettingErrors(
                    $values['max_chars_per_title'] ?? '',
                    $values['max_chars_per_description'] ?? ''
                );

                $errors = array_values(array_filter(explode(PHP_EOL, $errors)));
                $max    = (int) ($values['numImages@items'] ?? 0);
                $min    = (int) ($values['photos_min'] ?? 0);
                if ($max > 0 && $min > $max) {
                    $errors[] = sprintf(osc_mode_text(__('At least %1$d photos is more than the %2$d images a listing can have.'), __('At least %1$d photos is more than the %2$d images a product can have.')), $min, $max);
                }

                return $errors;
            })
            ->group(osc_mode_text(__('Listing Settings'), __('Product Settings')));

        self::rowOpen($form, 'settings', __('Settings'));
        $form
            ->checkbox('reg_user_post', osc_mode_text(__('Only logged in users can post listings'), __('Only logged in users can post products')))
                ->set('row', false)
            ->checkbox('item_contact_name_required', osc_mode_text(__('Name is required when publishing a new listing'), __('Name is required when publishing a new product')))
                ->set('row', false)
            ->number('items_wait_time', __('Wait time'), __('0 means no wait.'))
                ->set('row', false)
                ->prefix(__('Wait'))
                ->suffix(osc_mode_text(__('seconds between two listings from one user'), __('seconds between two products from one user')))
                ->clampMin(0)
                ->default(0)
            ->checkbox('moderate_admin_post', osc_mode_text(__('Hold new listings for admin moderation'), __('Hold new products for admin moderation')))
                ->set('row', false)
            ->checkbox('moderate_admin_edit', osc_mode_text(__('Hold edited listings for admin moderation'), __('Hold edited products for admin moderation')))
                ->set('row', false)
            ->checkbox('moderate_items', osc_mode_text(__('Posters confirm new listings by e-mail'), __('Posters confirm new products by e-mail')))
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
                osc_mode_text(__('Number of moderated listings'), __('Number of moderated products')),
                osc_mode_text(__('0 means every listing needs validation.'), __('0 means every product needs validation.'))
            )
                ->set('row', false)
                ->prefix(__('After'))
                ->suffix(osc_mode_text(__("validated listings the user doesn't need to validate the listings any more"), __("validated products the user doesn't need to validate the products any more")))
                ->clampMin(0)
                // Not stored: it is the value the switch above stores.
                ->persist(false)
                ->default($moderated === self::MODERATION_OFF ? 0 : $moderated)
            ->checkbox('logged_user_item_validation', __('Signed-in posters skip the e-mail confirmation'))
                ->set('row', false)
            ->custom('moderated_close', static function () {
                echo '</div>';
            })
                ->set('row', false)
            ->checkbox('enabled_recaptcha_items', osc_mode_text(__('Show reCAPTCHA in add/edit listing form'), __('Show reCAPTCHA in add/edit product form')), __('Needs reCAPTCHA keys first.'))
                ->set('row', false);
        self::rowClose($form, 'settings');

        self::rowOpen($form, 'contact', __('Contact publisher'));
        $form
            ->checkbox('reg_user_can_contact', __('Only allow registered users to contact publisher'))
                ->set('row', false)
            ->checkbox('item_attachment', __('Allow attached files in contact publisher form'))
                ->set('row', false);
        self::rowClose($form, 'contact');

        self::rowOpen($form, 'share', osc_mode_text(__('Share listing'), __('Share product')));
        $form
            ->checkbox(
                'enable_send_friend',
                __('Enable the "send to a friend" form'),
                __('Off by default: it can be abused to send spam.')
            )
                ->set('row', false)
            ->checkbox('reg_user_can_send_friend', osc_mode_text(__('Only allow registered users to share listings'), __('Only allow registered users to share products')))
                ->set('row', false)
                // Never saved reads as on: only an explicit '0' turns it off.
                ->default(true);
        self::rowClose($form, 'share');

        self::rowOpen($form, 'notify', __('Notifications'));
        $form
            ->checkbox('notify_new_item', osc_mode_text(__('Notify admin when a new listing is added'), __('Notify admin when a new product is added')))
                ->set('row', false)
            ->checkbox('notify_contact_item', __('Send admin a copy of the "contact publisher" email'))
                ->set('row', false)
            ->checkbox('notify_contact_friends', osc_mode_text(__('Send admin a copy to "share listing" email'), __('Send admin a copy to "share product" email')))
                ->set('row', false);
        self::rowClose($form, 'notify');

        $form
            ->number(
                'warn_expiration',
                __('Warn about expiration'),
                osc_mode_text(__('Emails the seller this many days before the listing expires. 0 sends none.'), __('Emails the seller this many days before the product expires. 0 sends none.'))
            )
                ->suffix(__('days'))
                ->clampMin(0)
                ->default(0)
            ->number(
                'search_count_cap',
                __('Search count limit'),
                osc_mode_text(__('Above this number, search shows the limit with a + sign, which is faster on big sites. 0 counts every listing.'), __('Above this number, search shows the limit with a + sign, which is faster on big sites. 0 counts every product.'))
            )
                ->suffix(__('matches'))
                ->set('min', 0)
                ->set('max', \Search::COUNT_CAP_MAX)
                ->sanitize(static fn ($value): int => max(0, min(\Search::COUNT_CAP_MAX, (int)$value)))
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

        $form
            ->select('enableField#f_price@items', __('Price'), $modes, osc_mode_text(__('Required refuses a listing without a price. A price of 0 counts and shows as Free.'), __('Required refuses a product without a price. A price of 0 counts and shows as Free.')))
                ->sanitize($posted)
                ->persist($enabled)
                // Stored as on/off plus the flag below, so the control cannot read it back.
                ->writeOnly()
                ->default(!osc_price_enabled_at_items() ? 'off' : (osc_item_price_required() ? 'required' : 'optional'))
            ->hidden('item_price_required')
                ->set('collect', static fn (): string => '')
                ->persist(static fn ($value, array $values): string => ($values['enableField#f_price@items'] ?? '') === 'required' ? '1' : '0')
                ->writeOnly()
                ->default('');

        self::rowOpen($form, 'photos', __('Photos'));
        $form
            ->select('enableField#images@items', __('Photos'), $modes)
                ->set('row', false)
                ->attrs(array('aria-label' => __('Photos')))
                ->sanitize($posted)
                ->persist($enabled)
                ->writeOnly()
                ->default(!osc_images_enabled_at_items() ? 'off' : ($photosMin > 0 ? 'required' : 'optional'))
            ->custom('photos_min_open', static function () {
                echo '<div data-osc-depends="enableField#images@items" data-osc-depends-value=\'["required"]\'>';
            })
                ->set('row', false)
            ->number('photos_min', __('Minimum photos'))
                ->set('row', false)
                ->prefix(__('At least'))
                ->suffix(__('photos'))
                ->clampMin(1)
                ->dependsOn('enableField#images@items', 'required')
                // Not stored: item_photos_min below stores it, or 0 when photos are not required.
                ->persist(false)
                ->writeOnly()
                ->default(max(1, $photosMin))
            ->custom('photos_min_close', static function () {
                echo '</div>';
            })
                ->set('row', false)
            ->number(
                'numImages@items',
                osc_mode_text(__('Images per listing'), __('Images per product')),
                __('0 means no limit.')
            )
                ->set('row', false)
                ->prefix(__('Attach'))
                ->suffix(osc_mode_text(__('images per listing'), __('images per product')))
                ->clampMin(0)
                ->default(0)
            ->hidden('item_photos_min')
                ->set('collect', static fn (): string => '')
                ->persist(static fn ($value, array $values): string => ($values['enableField#images@items'] ?? '') === 'required'
                    ? (string) max(1, (int) ($values['photos_min'] ?? 1))
                    : '0')
                ->writeOnly()
                ->default('');
        self::rowClose($form, 'photos');

        self::rowOpen($form, 'location', __('Location'));
        $form
            ->select('item_required_location', __('Location'), array('required' => __('Required'), 'optional' => __('Optional')))
                ->set('row', false)
                ->attrs(array('aria-label' => __('Location')))
                // Stores the level chosen beside it, or none.
                ->persist(static fn ($value, array $values): string => $value === 'required'
                    ? ((string) ($values['item_location_level'] ?? '') ?: 'country')
                    : 'none')
                ->writeOnly()
                ->default($level === 'none' ? 'optional' : 'required')
            ->custom('location_level_open', static function () {
                echo '<div data-osc-depends="item_required_location" data-osc-depends-value=\'["required"]\'>';
            })
                ->set('row', false)
            ->select(
                'item_location_level',
                __('Required location'),
                array(
                    'country' => __('Country'),
                    'region'  => __('Country and region'),
                    'city'    => __('Country, region and city'),
                ),
                osc_mode_text(__('New and edited listings must give at least this much of where they are.'), __('New and edited products must give at least this much of where they are.'))
            )
                ->set('row', false)
                ->attrs(array('aria-label' => __('Required location')))
                ->dependsOn('item_required_location', 'required')
                ->persist(false)
                ->writeOnly()
                ->default($level === 'none' ? 'country' : $level)
            ->custom('location_level_close', static function () {
                echo '</div>';
            })
                ->set('row', false)
            ->checkbox('item_place_from_list', __('Only places from the list'), __('Refuse a place typed by hand. Posters must pick their town or city from the suggestions, which the admin form has and a theme shows once it prints the place field.'))
                ->set('row', false)
                // Only the box's own value turns it on.
                ->set('collect', static fn (): bool => \Params::getParamString('item_place_from_list') === '1');
        self::rowClose($form, 'location');

        $form->register();
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
