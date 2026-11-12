<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

// $error is the mail failure message set by install.php. This partial renders
// only: display_finish() computes $data and $ins_later before including it.
global $error;

require_once __DIR__ . '/fields.php';

/** @var string $password */
/** @var array{s_email:string,admin_user:string,password:string} $data */
/** @var list<array{what:string,how:string}> $ins_later */

$ins_email_failed = !empty($error) || empty($data['s_email']);
$ins_site_name    = trim((string) osc_page_title());
$ins_site_url     = get_absolute_url();
$ins_admin_url    = $ins_site_url . 'oc-admin/';
$ins_admin_html   = implode('/<wbr>', array_map('osc_esc_html', explode('/', $ins_admin_url)));
$ins_rows         = array(
    array('id' => 'ins-cred-admin', 'label' => __('Admin address'), 'type' => 'text', 'value' => $ins_admin_url, 'html' => $ins_admin_html),
    array('id' => 'ins-cred-username', 'label' => __('Username'), 'type' => 'text', 'value' => (string) $data['admin_user']),
    array('id' => 'ins-cred-password', 'label' => __('Password'), 'type' => 'password', 'value' => (string) $data['password']),
);
?>
<div class="ins-stage">
    <div class="ins-stage-body">
        <div class="ins-done-head">
            <svg class="ins-done-seal" viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="28" cy="28" r="24" transform="rotate(-90 28 28)" /><path d="M18 28.5l7 7 13-14" />
            </svg>
            <div>
                <h1 class="ins-headline" id="ins-done-title" tabindex="-1"><?php
                    echo $ins_site_name !== ''
                        ? osc_esc_html(sprintf(__('%s is ready'), $ins_site_name))
                        : osc_esc_html(__('Your site is ready.'));
                ?></h1>
                <p class="ins-done-lede"><?php _e("Here is your admin login. Save the password now. We won't show it again."); ?></p>
            </div>
        </div>

        <div class="ins-creds">
            <?php foreach ($ins_rows as $ins_row) { ?>
                <div class="ins-cred">
                    <?php if ($ins_row['type'] === 'password') { ?>
                        <label class="ins-cred-k" for="<?php echo osc_esc_html($ins_row['id']); ?>"><?php echo osc_esc_html($ins_row['label']); ?></label>
                        <input class="ins-input ins-cred-v" id="<?php echo osc_esc_html($ins_row['id']); ?>" type="password" value="<?php echo osc_esc_html($ins_row['value']); ?>" readonly />
                    <?php } else { ?>
                        <span class="ins-cred-k"><?php echo osc_esc_html($ins_row['label']); ?></span>
                        <span class="ins-cred-v" id="<?php echo osc_esc_html($ins_row['id']); ?>"><?php echo $ins_row['html'] ?? osc_esc_html($ins_row['value']); ?></span>
                    <?php } ?>
                    <div class="ins-cred-a">
                        <?php if ($ins_row['type'] === 'password') {
                            install_reveal_button('ins-cred-password', 'ins-btn ins-btn-secondary ins-btn-icon');
                        } ?>
                        <button type="button" class="ins-btn ins-btn-secondary ins-copy-btn" data-copy-target="<?php echo osc_esc_html($ins_row['id']); ?>">
                            <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><rect x="6" y="6" width="11" height="11" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" /><rect x="3" y="3" width="11" height="11" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" /></svg>
                            <span class="ins-btn-label"><?php _e('Copy'); ?></span>
                        </button>
                    </div>
                </div>
            <?php } ?>
        </div>

        <p class="ins-cred-note">
            <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><rect x="2.5" y="4.5" width="15" height="11" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" /><path d="M3 5.5l7 5 7-5" fill="none" stroke="currentColor" stroke-width="1.5" /></svg>
            <span><?php
                echo $ins_email_failed
                    ? osc_esc_html(__("We couldn't email your password. Copy it now and store it somewhere safe."))
                    : sprintf(osc_esc_html(__('We also emailed these to %s.')), '<b>' . osc_esc_html($data['s_email']) . '</b>');
            ?></span>
        </p>
        <?php if (!empty($error) && is_string($error)) { ?>
            <p class="ins-cred-note"><span><?php echo osc_esc_html($error); ?></span></p>
        <?php } ?>

        <?php if (!empty($ins_later)) { ?>
            <div class="ins-later">
                <h2 class="ins-group"><?php _e('To finish later'); ?></h2>
                <ul>
                    <?php foreach ($ins_later as $ins_item) { ?>
                        <li>
                            <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><path d="M10 3l8 14H2L10 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" /><path d="M10 8v4M10 14.5v.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" /></svg>
                            <span><b><?php echo osc_esc_html($ins_item['what']); ?>.</b> <?php echo osc_esc_html($ins_item['how']); ?></span>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        <?php } ?>

        <span id="ins-copy-announcer" class="ins-visually-hidden" role="status" aria-live="polite"></span>
    </div>

    <div class="ins-actions">
        <a class="ins-btn ins-btn-secondary" href="<?php echo osc_esc_html($ins_site_url); ?>"><?php _e('View your site'); ?></a>
        <a class="ins-btn ins-btn-primary" href="<?php echo osc_esc_html($ins_admin_url); ?>index.php"><?php _e('Open your admin panel'); ?><?php install_btn_icon('next'); ?></a>
    </div>
</div>
