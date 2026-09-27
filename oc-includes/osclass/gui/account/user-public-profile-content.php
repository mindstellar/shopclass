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
    <?php osc_gui_print_pager(); ?>
<?php } ?>

<?php if ($contactOpen) { ?>
    <section class="oe-panel" id="contact">
        <h2><?php printf(osc_esc_html(_m('Write to %s')), osc_esc_html(osc_user_name())); ?></h2>
        <?php
        $contactForm = array(
            'hidden'  => array('page' => 'user', 'action' => 'contact_post', 'id' => $publicId),
            'prefix'  => 'oe-your',
            'hint'    => _m('The member replies to this address.'),
            'phone'   => true,
            'captcha' => 'contact_user',
            'hooks'   => static function () use ($publicUser) {
                osc_run_hook('user_contact_form', $publicUser);
            },
        );
        require dirname(__DIR__) . '/parts/contact-form.php';
        ?>
        <?php osc_run_hook('user_contact_form_after', $publicUser); ?>
    </section>
<?php } elseif (!$publicOwn) { ?>
    <p class="oe-muted"><a href="<?php echo osc_esc_html(osc_user_login_url()); ?>"><?php
        echo osc_esc_html(_m('Sign in to write to this member.')); ?></a></p>
<?php } ?>
