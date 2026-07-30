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

// $install_nonce is a true global set by install.php at the top of the request;
// this partial is reached through display_target(), a function call, so it
// needs pulling in explicitly rather than inheriting the caller's scope.
global $install_nonce;

$internet_error = false;
require_once LIB_PATH . 'osclass/helpers/hUtils.php';
$country_list = osc_file_get_contents(osc_get_locations_json_url());
$country_list = json_decode($country_list, false);
$country_list = $country_list->locations;

$country_ip = '';
if (preg_match(
    '|([a-z]{2})-([A-Z]{2})|',
    Params::getServerParam('HTTP_ACCEPT_LANGUAGE'),
    $match
)) {
    $country_ip = $match[2];
}

if (!isset($country_list[0]->s_country_name)) {
    $internet_error = true;
}
?>
<h1 class="ins-headline"><?php _e('Set up your site'); ?></h1>

<noscript>
    <div class="ins-panel ins-panel-warning">
        <div class="ins-panel-body"><?php _e('JavaScript is required to complete this step.'); ?></div>
    </div>
</noscript>

<div id="ins-target-wrap" class="ins-target-wrap">
    <form id="ins-target-form" action="#" method="post">
        <input type="hidden" name="install_nonce" value="<?php echo osc_esc_html($install_nonce); ?>" />

        <div id="ins-target-error" class="ins-panel ins-panel-danger" role="alert" hidden></div>

        <h2 class="ins-title"><?php _e('Admin account'); ?></h2>
        <div class="ins-field">
            <label for="admin_user"><?php _e('Username'); ?></label>
            <input class="ins-input" id="admin_user" name="s_name" type="text" value="admin" autocomplete="off" />
            <span id="admin-user-error" class="ins-field-error" hidden><?php _e('Usernames can only contain letters and numbers.'); ?></span>
        </div>
        <div class="ins-field">
            <label for="s_passwd"><?php _e('Password'); ?></label>
            <div class="ins-input-wrap">
                <input class="ins-input" name="s_passwd" id="s_passwd" type="password" value="" autocomplete="off" />
                <button type="button" class="ins-input-btn" data-reveal-target="s_passwd" aria-pressed="false" aria-label="<?php echo osc_esc_html(__('Show password')); ?>">
                    <span class="ins-icon-show" aria-hidden="true"><svg viewBox="0 0 20 20" width="16" height="16" focusable="false"><path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z" fill="none" stroke="currentColor" stroke-width="1.5" /><circle cx="10" cy="10" r="2.5" fill="none" stroke="currentColor" stroke-width="1.5" /></svg></span>
                    <span class="ins-icon-hide" aria-hidden="true"><svg viewBox="0 0 20 20" width="16" height="16" focusable="false"><path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z" fill="none" stroke="currentColor" stroke-width="1.5" /><circle cx="10" cy="10" r="2.5" fill="none" stroke="currentColor" stroke-width="1.5" /><path d="M2 2l16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" /></svg></span>
                </button>
            </div>
            <div class="ins-help"><?php _e("Leave blank and we'll generate a strong one for you."); ?></div>
        </div>

        <h2 class="ins-title"><?php _e('Site details'); ?></h2>
        <div class="ins-field">
            <label for="webtitle"><?php _e('Web title'); ?></label>
            <input class="ins-input" type="text" id="webtitle" name="webtitle" autocomplete="off" />
        </div>
        <div class="ins-field">
            <label for="email"><?php _e('Contact email'); ?></label>
            <input class="ins-input" type="text" id="email" name="email" autocomplete="off" />
            <span id="email-error" class="ins-field-error" hidden><?php _e('Enter a valid email address.'); ?></span>
        </div>

        <h2 class="ins-title"><?php _e('Location'); ?></h2>
        <?php if (!$internet_error) { ?>
            <p class="ins-body"><?php _e('Choose the country your visitors are mostly in. You can change this later.'); ?></p>
            <input type="hidden" id="skip-location-input" name="skip-location-input" value="<?php echo $country_ip ? 0 : 1; ?>" />
            <div class="ins-field">
                <label for="location-json"><?php _e('Country'); ?></label>
                <select class="ins-input" name="location-json" id="location-json">
                    <option value="skip"><?php _e('Skip for now'); ?></option>
                    <?php foreach ($country_list as $c) : ?>
                        <option value="<?php echo osc_esc_html($c->s_file_name); ?>" <?php echo ($country_ip && strpos($c->s_file_name, $country_ip) === 0) ? 'selected="selected"' : ''; ?>>
                            <?php echo osc_esc_html($c->s_country_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php } else { ?>
            <div class="ins-panel ins-panel-info">
                <div class="ins-panel-body"><?php _e('No internet connection. You can continue and add your location later from the admin panel.'); ?></div>
            </div>
            <input type="hidden" id="skip-location-input" name="skip-location-input" value="1" />
        <?php } ?>

        <div class="ins-actions">
            <button type="submit" class="ins-btn ins-btn-primary"><?php _e('Set up my site'); ?></button>
        </div>
    </form>

    <div id="ins-setup-overlay" class="ins-overlay" role="status" aria-live="polite" hidden>
        <span class="ins-overlay-spinner" aria-hidden="true"></span>
        <p><?php _e("Setting up your site… this can take a minute on shared hosting — don't close this tab."); ?></p>
    </div>
</div>
