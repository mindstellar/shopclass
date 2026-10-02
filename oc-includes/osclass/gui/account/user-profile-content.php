<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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
// The current language first; the others go behind a disclosure.
$profileLocales = array($profileLocale => '');
foreach (osc_get_locales() as $locale) {
    if ($locale['pk_c_code'] !== $profileLocale) {
        $profileLocales[$locale['pk_c_code']] = (string) $locale['s_name'];
    }
}
// UserForm names the field s_info[<locale>] and builds its id by dropping every
// character outside [_a-zA-Z0-9-], so each label's `for` is built the same way.
$profileInfoField = static function (string $code, string $label) use ($profileUser): void {
    $id = preg_replace('|([^_a-zA-Z0-9-]+)|', '', 's_info[' . $code . ']'); ?>
    <div class="oe-field">
        <label class="oe-label" for="<?php echo osc_esc_html($id); ?>"><?php echo osc_esc_html($label); ?></label>
        <?php UserForm::info_textarea('s_info', $code, (string) ($profileUser['locale'][$code]['s_info'] ?? '')); ?>
    </div>
<?php };
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_run_hook('account_page_before', 'user-profile'); ?>

        <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post"
              enctype="multipart/form-data">
            <input type="hidden" name="page" value="user" />
            <input type="hidden" name="action" value="profile_post" />

            <?php if (osc_get_preference('enabled_user_avatars')) {
                $hasAvatar = osc_has_user_avatar(osc_logged_user_id()); ?>
                <fieldset class="oe-group">
                    <legend><?php echo osc_esc_html(_m('Photo')); ?></legend>
                    <div class="oe-field oe-avatar-field">
                        <?php // 'normal', not the 64px thumbnail: displayed at 96 it would upscale. ?>
                        <img class="oe-avatar<?php echo $hasAvatar ? '' : ' oe-avatar-empty'; ?>"
                             src="<?php echo osc_esc_html(osc_user_avatar_url(null, 'normal')); ?>"
                             alt="<?php echo osc_esc_html($hasAvatar ? _m('Your current picture') : ''); ?>"
                             width="96" height="96" decoding="async" />
                        <div>
                            <label class="oe-label" for="oe-avatar"><?php
                                echo osc_esc_html($hasAvatar ? _m('Change picture') : _m('Upload a picture')); ?></label>
                            <input class="oe-input" id="oe-avatar" type="file" name="avatar" accept="image/*"
                                   aria-describedby="oe-avatar-hint" />
                            <span class="oe-hint" id="oe-avatar-hint"><?php printf(
                                osc_esc_html(_m('Up to %s KB.')),
                                osc_esc_html((string) osc_max_size_kb())
                            ); ?></span>
                            <?php if ($hasAvatar) { ?>
                                <label class="oe-check">
                                    <input type="checkbox" name="remove_avatar" value="1" />
                                    <span><?php echo osc_esc_html(_m('Remove the current picture')); ?></span>
                                </label>
                            <?php } ?>
                        </div>
                    </div>
                    <?php osc_run_hook('user_avatar_form', $profileUser); ?>
                </fieldset>
            <?php } ?>

            <fieldset class="oe-group">
                <legend><?php echo osc_esc_html(_m('Your details')); ?></legend>
                <div class="oe-grid">
                    <div class="oe-field">
                        <label class="oe-label" for="s_name"><?php echo osc_esc_html(_m('Name')); ?></label>
                        <?php UserForm::name_text($profileUser); ?>
                    </div>
                    <div class="oe-field">
                        <label class="oe-label" for="b_company"><?php echo osc_esc_html(_m('Account type')); ?></label>
                        <?php UserForm::is_company_select($profileUser, _m('Private seller'), _m('Business')); ?>
                    </div>
                </div>
            </fieldset>

            <fieldset class="oe-group">
                <legend><?php echo osc_esc_html(_m('Contact')); ?></legend>
                <div class="oe-grid">
                    <div class="oe-field">
                        <label class="oe-label" for="s_phone_mobile"><?php echo osc_esc_html(_m('Mobile')); ?></label>
                        <?php UserForm::mobile_text($profileUser); ?>
                    </div>
                    <div class="oe-field">
                        <label class="oe-label" for="s_phone_land"><?php echo osc_esc_html(_m('Telephone')); ?></label>
                        <?php UserForm::phone_land_text($profileUser); ?>
                    </div>
                </div>
                <div class="oe-field">
                    <label class="oe-label" for="s_website"><?php echo osc_esc_html(_m('Website')); ?></label>
                    <?php UserForm::website_text($profileUser); ?>
                </div>
            </fieldset>

            <fieldset class="oe-group" data-location-cascade>
                <legend><?php echo osc_esc_html(_m('Location')); ?></legend>
                <div class="oe-grid">
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
                        <label class="oe-label" for="<?php echo osc_user_field('fk_c_country_code') ? 'regionId' : 'region'; ?>"><?php echo osc_esc_html(_m('Region')); ?></label>
                        <?php // With no country or region chosen these helpers list every row in the table.
                        UserForm::region_select(osc_user_field('fk_c_country_code') ? osc_get_regions(osc_user_field('fk_c_country_code')) : array(), $profileUser); ?>
                    </div>
                    <div class="oe-field">
                        <label class="oe-label" for="<?php echo osc_user_field('fk_i_region_id') ? 'cityId' : 'city'; ?>"><?php echo osc_esc_html(_m('City')); ?></label>
                        <?php UserForm::city_select(osc_user_field('fk_i_region_id') ? osc_get_cities(osc_user_field('fk_i_region_id')) : array(), $profileUser); ?>
                    </div>
                    <div class="oe-field">
                        <label class="oe-label" for="cityArea"><?php echo osc_esc_html(_m('Neighbourhood')); ?></label>
                        <?php UserForm::city_area_text($profileUser); ?>
                    </div>
                    <div class="oe-field">
                        <label class="oe-label" for="address"><?php echo osc_esc_html(_m('Address')); ?></label>
                        <?php UserForm::address_text($profileUser); ?>
                    </div>
                    <div class="oe-field">
                        <label class="oe-label" for="zip"><?php echo osc_esc_html(_m('Postcode')); ?></label>
                        <?php UserForm::zip_text($profileUser); ?>
                    </div>
                </div>
            </fieldset>

            <fieldset class="oe-group">
                <legend><?php echo osc_esc_html(_m('About you')); ?></legend>
                <?php $profileInfoField($profileLocale, _m('Description'));
                if (count($profileLocales) > 1) { ?>
                    <details class="oe-field">
                        <summary><?php echo osc_esc_html(_m('About you in other languages')); ?></summary>
                        <?php foreach (array_slice($profileLocales, 1, null, true) as $code => $name) {
                            $profileInfoField((string) $code, sprintf(_m('About you (%s)'), $name));
                        } ?>
                    </details>
                <?php } ?>
            </fieldset>

            <?php osc_run_hook('user_profile_form', $profileUser); ?>
            <?php osc_run_hook('user_form', $profileUser); ?>

            <div class="oe-actions">
                <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Save changes')); ?></button>
            </div>
        </form>

        <?php
        // Progressive enhancement over the form above, which round-trips on its
        // own: saving a country re-renders this page with that country's regions.
        UserForm::location_javascript();
        ?>

        <?php $exportUrl = osc_user_export_url();
        if ($exportUrl !== '') { ?>
            <section class="oe-panel">
                <h2><?php echo osc_esc_html(_m('Your data')); ?></h2>
                <p class="oe-muted"><?php echo osc_esc_html(
                    _m('Download a copy of the data this site holds about you.')
                ); ?></p>
                <a class="oe-btn oe-secondary" href="<?php echo osc_esc_html($exportUrl); ?>"><?php
                    echo osc_esc_html(_m('Download your data')); ?></a>
            </section>
        <?php } ?>

        <?php
        // Account deletion sits at the foot of this page rather than in the account
        // nav: it is irreversible, and a nav entry makes it a peer of "Alerts".
        // Reaching it takes scrolling past everything routine, and it is still only
        // a link -- the page it opens is where the password is asked for.
        $deleteUrl = osc_user_delete_url();
        if ($deleteUrl !== '') { ?>
            <section class="oe-danger">
                <h2><?php echo osc_esc_html(_m('Delete your account')); ?></h2>
                <p class="oe-muted"><?php echo osc_esc_html(
                    _m('Your listings and messages are removed with it. This cannot be undone.')
                ); ?></p>
                <a class="oe-btn oe-btn-danger" href="<?php echo osc_esc_html($deleteUrl); ?>"><?php
                    echo osc_esc_html(_m('Delete your account')); ?></a>
            </section>
        <?php } ?>

        <?php osc_run_hook('account_page_after', 'user-profile'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
