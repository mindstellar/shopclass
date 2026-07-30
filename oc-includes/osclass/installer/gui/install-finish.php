<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
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

// $error is a true global set by install.php (the 'result' param — an email
// send failure message, or unset on success). This partial is reached through
// display_finish(), a function call, so it needs pulling in explicitly rather
// than inheriting the caller's scope.
global $error;

$data = finish_installation($password);
$ins_email_failed = !empty($error) || empty($data['s_email']);
?>
<div class="ins-hero">
    <h1 class="ins-display"><?php _e('Your site is ready.'); ?></h1>
</div>

<?php if (Params::getParam('error_location') == 1) { ?>
    <div class="ins-panel ins-panel-warning" role="alert" data-dismiss-after="6000">
        <div class="ins-panel-body"><?php _e("We couldn't save your selected location. You can set it later from the admin panel."); ?></div>
        <button type="button" class="ins-panel-dismiss" aria-label="<?php echo osc_esc_html(__('Dismiss')); ?>">
            <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
        </button>
    </div>
<?php } ?>

<?php if (Session::newInstance()->_get('install_sample_warning')) { ?>
    <div class="ins-panel ins-panel-warning">
        <div class="ins-panel-body"><?php _e("Some sample content couldn't be added. Your site will still work fine."); ?></div>
    </div>
<?php } ?>

<?php if ($ins_email_failed) { ?>
    <div class="ins-panel ins-panel-warning">
        <div class="ins-panel-body"><?php _e("We couldn't email your password. Copy it now and store it somewhere safe."); ?></div>
    </div>
<?php } else { ?>
    <p class="ins-body">
        <?php echo sprintf(
            __("Save this password now — it won't be shown again. We also emailed it to %s."),
            osc_esc_html($data['s_email'])
        ); ?>
    </p>
<?php } ?>

<div class="ins-cred">
    <label for="ins-cred-username"><?php _e('Username'); ?></label>
    <div class="ins-cred-value">
        <input class="ins-input" id="ins-cred-username" type="text" value="<?php echo osc_esc_html($data['admin_user']); ?>" readonly />
        <div class="ins-cred-actions">
            <button type="button" class="ins-btn ins-btn-secondary ins-copy-btn" data-copy-target="ins-cred-username">
                <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><rect x="6" y="6" width="11" height="11" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" /><rect x="3" y="3" width="11" height="11" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" /></svg>
                <span class="ins-btn-label"><?php _e('Copy'); ?></span>
            </button>
        </div>
    </div>
</div>

<div class="ins-cred">
    <label for="ins-cred-password"><?php _e('Password'); ?></label>
    <div class="ins-cred-value">
        <input class="ins-input" id="ins-cred-password" type="password" value="<?php echo osc_esc_html($data['password']); ?>" readonly />
        <div class="ins-cred-actions">
            <button type="button" class="ins-btn ins-btn-secondary ins-btn-icon" data-reveal-target="ins-cred-password" aria-pressed="false" aria-label="<?php echo osc_esc_html(__('Show password')); ?>">
                <span class="ins-icon-show" aria-hidden="true"><svg viewBox="0 0 20 20" width="16" height="16" focusable="false"><path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z" fill="none" stroke="currentColor" stroke-width="1.5" /><circle cx="10" cy="10" r="2.5" fill="none" stroke="currentColor" stroke-width="1.5" /></svg></span>
                <span class="ins-icon-hide" aria-hidden="true"><svg viewBox="0 0 20 20" width="16" height="16" focusable="false"><path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z" fill="none" stroke="currentColor" stroke-width="1.5" /><circle cx="10" cy="10" r="2.5" fill="none" stroke="currentColor" stroke-width="1.5" /><path d="M2 2l16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" /></svg></span>
            </button>
            <button type="button" class="ins-btn ins-btn-secondary ins-copy-btn" data-copy-target="ins-cred-password">
                <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><rect x="6" y="6" width="11" height="11" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" /><rect x="3" y="3" width="11" height="11" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" /></svg>
                <span class="ins-btn-label"><?php _e('Copy'); ?></span>
            </button>
        </div>
    </div>
</div>

<span id="ins-copy-announcer" class="ins-visually-hidden" role="status" aria-live="polite"></span>

<div class="ins-actions">
    <a class="ins-btn ins-btn-primary" href="<?php echo osc_esc_html(get_absolute_url()); ?>oc-admin/index.php"><?php _e('Open your admin panel'); ?></a>
</div>
