<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
/**
 * The account profile form -- markup only, no heading and no chrome.
 *
 * Fields come from UserForm so the names stay core's own contract; the ids
 * UserForm gives them are the field name with everything outside [_a-zA-Z0-9-]
 * removed, which is what every `for` here is built from rather than assumed.
 *
 * CSRF is injected on shutdown for forms not marked nocsrf. The POST is
 * profile_post and is checked.
 */

$profileUser   = osc_user();
$profileLocale = osc_current_user_locale();
// UserForm::info_textarea() names the field s_info[<locale>]; Form strips every
// character outside [_a-zA-Z0-9-] to build the id, so the label's `for` has to
// be built the same way or it points at nothing.
$profileInfoId = preg_replace('|([^_a-zA-Z0-9-]+)|', '', 's_info[' . $profileLocale . ']');
$profileInfo   = isset($profileUser['locale'][$profileLocale]['s_info'])
    ? $profileUser['locale'][$profileLocale]['s_info']
    : '';
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>

        <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post"
              enctype="multipart/form-data">
            <input type="hidden" name="page" value="user" />
            <input type="hidden" name="action" value="profile_post" />

            <div class="oe-field">
                <label class="oe-label" for="s_name"><?php echo osc_esc_html(_m('Name')); ?></label>
                <?php UserForm::name_text($profileUser); ?>
            </div>
            <div class="oe-field">
                <label class="oe-label" for="s_website"><?php echo osc_esc_html(_m('Website')); ?></label>
                <?php UserForm::website_text($profileUser); ?>
            </div>
            <div class="oe-field">
                <label class="oe-label" for="<?php echo osc_esc_html($profileInfoId); ?>"><?php
                    echo osc_esc_html(_m('About you')); ?></label>
                <?php UserForm::info_textarea('s_info', $profileLocale, $profileInfo); ?>
            </div>
            <div class="oe-field">
                <label class="oe-label" for="s_phone_land"><?php echo osc_esc_html(_m('Telephone')); ?></label>
                <?php UserForm::phone_land_text($profileUser); ?>
            </div>
            <div class="oe-field">
                <label class="oe-label" for="s_phone_mobile"><?php echo osc_esc_html(_m('Mobile')); ?></label>
                <?php UserForm::mobile_text($profileUser); ?>
            </div>

            <div class="oe-field">
                <label class="oe-label" for="countryId"><?php echo osc_esc_html(_m('Country')); ?></label>
                <?php UserForm::country_select(osc_get_countries(), $profileUser); ?>
                <noscript>
                    <span class="oe-hint"><?php echo osc_esc_html(
                        _m('Choose a country and save; the regions for it load on the next screen.')
                    ); ?></span>
                </noscript>
            </div>
            <div class="oe-field">
                <label class="oe-label" for="regionId"><?php echo osc_esc_html(_m('Region')); ?></label>
                <?php UserForm::region_select(osc_get_regions(osc_user_field('fk_c_country_code')), $profileUser); ?>
            </div>
            <div class="oe-field">
                <label class="oe-label" for="cityId"><?php echo osc_esc_html(_m('City')); ?></label>
                <?php UserForm::city_select(osc_get_cities(osc_user_field('fk_i_region_id')), $profileUser); ?>
            </div>
            <div class="oe-field">
                <label class="oe-label" for="address"><?php echo osc_esc_html(_m('Address')); ?></label>
                <?php UserForm::address_text($profileUser); ?>
            </div>
            <div class="oe-field">
                <label class="oe-label" for="zip"><?php echo osc_esc_html(_m('Postcode')); ?></label>
                <?php UserForm::zip_text($profileUser); ?>
            </div>

            <?php if (osc_get_preference('enabled_user_avatars')) { ?>
                <div class="oe-field">
                    <label class="oe-label" for="oe-avatar"><?php echo osc_esc_html(_m('Picture')); ?></label>
                    <input class="oe-input" id="oe-avatar" type="file" name="avatar" accept="image/*"
                           aria-describedby="oe-avatar-hint" />
                    <span class="oe-hint" id="oe-avatar-hint"><?php printf(
                        osc_esc_html(_m('Up to %s KB.')),
                        osc_esc_html((string) osc_max_size_kb())
                    ); ?></span>
                </div>
                <?php if (osc_has_user_avatar(osc_logged_user_id())) { ?>
                    <label class="oe-check">
                        <input type="checkbox" name="remove_avatar" value="1" />
                        <span><?php echo osc_esc_html(_m('Remove the current picture')); ?></span>
                    </label>
                <?php } ?>
            <?php } ?>

            <?php osc_run_hook('user_profile_form', $profileUser); ?>

            <div class="oe-actions">
                <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Save changes')); ?></button>
            </div>
        </form>

        <?php
        // Progressive enhancement over the form above, which round-trips on its
        // own: saving a country re-renders this page with that country's regions.
        UserForm::location_javascript();
        ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
