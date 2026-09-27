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
 * Another member's public page -- markup only.
 *
 * Reads the `user` and `items` view variables the controller exported. Nothing
 * here is the visitor's own account, so no account nav and no owner actions.
 */

$publicInfo  = osc_user_info();
$publicUser  = osc_user();
$publicId    = (int) osc_user_id();
$publicOwn   = osc_is_web_user_logged_in() && (int) osc_logged_user_id() === $publicId;
$contactOpen = !$publicOwn && (!osc_reg_user_can_contact() || osc_is_web_user_logged_in());
?>
<?php osc_show_flash_message(); ?>

<div class="oe-profile-head">
    <img class="oe-avatar" src="<?php echo osc_esc_html(osc_user_avatar_url($publicId)); ?>" alt=""
         width="96" height="96" loading="lazy" decoding="async">
    <p class="oe-meta">
        <?php if (osc_user_is_company()) { ?>
            <span class="oe-badge refunded"><?php echo osc_esc_html(_m('Business')); ?></span>
        <?php } ?>
        <span><?php printf(
            osc_esc_html(_m('Member since %s')),
            osc_esc_html(osc_format_date(osc_user_regdate()))
        ); ?></span>
        <?php $publicPlace = implode(', ', array_filter(array(
            (string) osc_user_city(), (string) osc_user_region(), (string) osc_user_country(),
        ), 'strlen'));
        if ($publicPlace !== '') { ?>
            <span><?php echo osc_esc_html($publicPlace); ?></span>
        <?php }
        if (osc_user_website() !== '') { ?>
            <a href="<?php echo osc_esc_html(osc_user_website()); ?>" rel="nofollow noopener ugc"><?php
                echo osc_esc_html(osc_user_website()); ?></a>
        <?php } ?>
    </p>
    <?php if ($publicOwn) { ?>
        <div class="oe-actions">
            <a class="oe-btn oe-secondary" href="<?php echo osc_esc_html(osc_user_profile_url()); ?>"><?php
                echo osc_esc_html(_m('Edit your profile')); ?></a>
        </div>
    <?php } ?>
</div>

<?php if ($publicInfo !== '') { ?>
    <?php
    // "About you" is stored exactly as the account holder typed it, so it is
    // escaped here and its line breaks put back.
    ?>
    <div class="oe-panel"><?php echo nl2br(osc_esc_html($publicInfo)); ?></div>
<?php } ?>

<h2><?php echo osc_esc_html(_m('Listings')); ?></h2>
<?php if (osc_count_items() === 0) { ?>
    <p class="oe-empty"><?php echo osc_esc_html(_m('Nothing published.')); ?></p>
<?php } else { ?>
    <?php osc_gui_listing_list('public_profile', false); ?>
    <?php $publicPager = osc_pagination_items();
    // A lone "1" leads nowhere, so the pager shows only when it has a link.
    if (strpos($publicPager, '<a') !== false) { ?>
        <nav class="oe-pager" aria-label="<?php echo osc_esc_html(_m('Pages')); ?>"><?php
            echo $publicPager; ?></nav>
    <?php } ?>
<?php } ?>

<?php if ($contactOpen) { ?>
    <section class="oe-panel" id="contact">
        <h2><?php printf(osc_esc_html(_m('Write to %s')), osc_esc_html(osc_user_name())); ?></h2>
        <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
            <input type="hidden" name="page" value="user" />
            <input type="hidden" name="action" value="contact_post" />
            <input type="hidden" name="id" value="<?php echo $publicId; ?>" />

            <div class="oe-field">
                <label class="oe-label" for="oe-your-name"><?php echo osc_esc_html(_m('Your name')); ?></label>
                <input class="oe-input" id="oe-your-name" type="text" name="yourName" autocomplete="name" required
                       value="<?php echo osc_esc_html(osc_gui_kept('yourName', osc_logged_user_name())); ?>" />
            </div>
            <div class="oe-field">
                <label class="oe-label" for="oe-your-email"><?php echo osc_esc_html(_m('Your email address')); ?></label>
                <input class="oe-input" id="oe-your-email" type="email" name="yourEmail" autocomplete="email" required
                       value="<?php echo osc_esc_html(osc_gui_kept('yourEmail', osc_logged_user_email())); ?>" />
            </div>
            <div class="oe-field">
                <label class="oe-label" for="oe-your-phone"><?php echo osc_esc_html(_m('Phone number')); ?></label>
                <input class="oe-input" id="oe-your-phone" type="tel" name="phoneNumber" autocomplete="tel"
                       value="<?php echo osc_esc_html(osc_gui_kept('phoneNumber')); ?>" />
            </div>
            <div class="oe-field">
                <label class="oe-label" for="oe-message"><?php echo osc_esc_html(_m('Message')); ?></label>
                <textarea class="oe-input" id="oe-message" name="message" rows="6" required><?php
                    echo osc_esc_html(osc_gui_kept('message_body')); ?></textarea>
            </div>

            <?php if (osc_captcha_enabled()) { ?>
                <div class="oe-field"><?php osc_show_captcha('contact_user'); ?></div>
            <?php } ?>
            <?php osc_run_hook('user_contact_form', $publicUser); ?>

            <div class="oe-actions">
                <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Send message')); ?></button>
            </div>
        </form>
        <?php osc_run_hook('user_contact_form_after', $publicUser); ?>
    </section>
<?php } elseif (!$publicOwn) { ?>
    <p class="oe-muted"><a href="<?php echo osc_esc_html(osc_user_login_url()); ?>"><?php
        echo osc_esc_html(_m('Sign in to write to this member.')); ?></a></p>
<?php } ?>
