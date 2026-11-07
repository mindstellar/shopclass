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

// $install_nonce is a true global set by install.php at the top of the request;
// this partial is reached through display_target(), a function call, so it
// needs pulling in explicitly rather than inheriting the caller's scope.
global $install_nonce;

require_once __DIR__ . '/fields.php';

$internet_error = false;
require_once LIB_PATH . 'osclass/helpers/hUtils.php';

// Read through the catalog rather than decoding the configured URL here: that URL names
// the current release rather than listing countries, so parsing it directly finds no
// countries and reports the catalog unreachable.
try {
    $country_list = (new \mindstellar\location\LocationCatalog())->status();
} catch (\Throwable $e) {
    $country_list = array();
}

$country_ip = '';
if (preg_match(
    '|([a-z]{2})-([A-Z]{2})|',
    Params::getServerParam('HTTP_ACCEPT_LANGUAGE'),
    $match
)) {
    $country_ip = $match[2];
}

if ($country_list === array()) {
    $internet_error = true;
}

$countries = array('skip' => __('Skip for now'));
$selected  = 'skip';
foreach ($country_list as $c) {
    $countries[(string) $c['code']] = (string) $c['name'];
    if ($country_ip && strcasecmp((string) $c['code'], $country_ip) === 0) {
        $selected = (string) $c['code'];
    }
}

// Everything the installer runs after the form is sent, one request per unit.
$ins_choice = install_site_choice();
$ins_type   = (string) $ins_choice['type'];
$ins_sample = !empty($ins_choice['sample']);
$ins_plan   = mindstellar\install\InstallPlan::build(
    $ins_type,
    $ins_sample,
    $ins_sample ? (new mindstellar\install\SampleData())->batches($ins_type) : 0,
    $ins_type === 'shop' ? mindstellar\install\ShopSetup::fromCache()->steps((array) $ins_choice['plugins']) : array(),
    !is_dir(CONTENT_PATH . 'themes/storefront')
);
?>
<div id="ins-target-wrap" class="ins-target-wrap">
    <form id="ins-target-form" class="ins-stage" action="#" method="post" novalidate data-install-plan="<?php echo osc_esc_html((string) json_encode($ins_plan)); ?>">
        <input type="hidden" name="install_nonce" value="<?php echo osc_esc_html($install_nonce); ?>" />

        <div class="ins-stage-body">
            <h1 class="ins-headline"><?php _e('Name your site and your login'); ?></h1>
            <p class="ins-body"><?php _e('You can change all of this later in Settings.'); ?></p>

            <noscript>
                <?php install_panel('warning', '', __('JavaScript is required to complete this step.')); ?>
            </noscript>

            <?php
            install_panel(
                'danger',
                __("We couldn't create your admin account"),
                __("The server didn't answer. Your details are still here. Press Install Shopclass to try again."),
                array('id' => 'ins-target-error', 'hidden' => true)
            );
            ?>

            <div class="ins-grid2">
                <?php
                install_field(array(
                    'id'    => 'webtitle',
                    'label' => __('Site name'),
                    'error' => __('Give your site a name.'),
                    'attrs' => array('autocomplete' => 'off', 'required' => 'required'),
                ));
                ?>
                <?php
                install_field(array(
                    'id'    => 'email',
                    'label' => __('Contact email'),
                    'type'  => 'email',
                    'error' => __('Enter a valid email address.'),
                    'attrs' => array('autocomplete' => 'off', 'spellcheck' => 'false', 'dir' => 'ltr'),
                ));
                ?>
                <?php
                if (!$internet_error) {
                    install_field(array(
                        'id'      => 'location-json',
                        'label'   => __('Country'),
                        'opt'     => __('(for locations)'),
                        'type'    => 'select',
                        'options' => $countries,
                        'value'   => $selected,
                        'help'    => __('We add its regions and cities so people can pick where they are.'),
                        'class'   => 'ins-field-wide',
                    ));
                }
                ?>
            </div>
            <?php if (!$internet_error) { ?>
                <input type="hidden" id="skip-location-input" name="skip-location-input" value="<?php echo $country_ip ? 0 : 1; ?>" />
            <?php } else { ?>
                <p class="ins-help"><?php _e('No internet connection. You can continue and add your location later from the admin panel.'); ?></p>
                <input type="hidden" id="skip-location-input" name="skip-location-input" value="1" />
            <?php } ?>

            <h2 class="ins-group"><?php _e('Admin account'); ?></h2>
            <div class="ins-grid2">
                <?php
                install_field(array(
                    'id'    => 'admin_user',
                    'name'  => 's_name',
                    'label' => __('Username'),
                    'value' => 'admin',
                    'help'  => __('Usernames can only contain letters and numbers.'),
                    'error' => __('Remove spaces and symbols.'),
                    'attrs' => array('autocomplete' => 'off', 'spellcheck' => 'false'),
                ));
                ?>
                <?php
                install_field(array(
                    'id'       => 's_passwd',
                    'label'    => __('Password'),
                    'type'     => 'password',
                    'reveal'   => true,
                    'generate' => true,
                    'help'     => __("Leave it empty and we make one. You'll see it at the end."),
                    'attrs'    => array('autocomplete' => 'new-password', 'placeholder' => __("We'll make a strong one")),
                ));
                ?>
            </div>
        </div>

        <div class="ins-actions">
            <a class="ins-btn ins-btn-ghost ins-back" href="install.php?step=3"><?php install_btn_icon('back'); ?><?php _e('Back'); ?></a>
            <button type="submit" class="ins-btn ins-btn-primary"><?php _e('Install Shopclass'); ?></button>
        </div>
    </form>

    <section id="ins-progress" class="ins-stage" hidden aria-labelledby="ins-progress-title">
        <div class="ins-stage-body">
            <h1 id="ins-progress-title" class="ins-headline" tabindex="-1" data-template="<?php echo osc_esc_html(__('Setting up %s')); ?>"></h1>
            <p class="ins-body"><?php _e('This takes a minute or two. Keep this tab open.'); ?></p>
            <div class="ins-meter">
                <div class="ins-meter-row">
                    <span id="ins-meter-now" class="ins-meter-now"></span>
                    <span id="ins-meter-count" class="ins-meter-count"></span>
                </div>
                <div id="ins-track" class="ins-track" role="progressbar" aria-label="<?php echo osc_esc_html(__('Install progress')); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div id="ins-fill" class="ins-fill"></div></div>
                <p id="ins-slow" class="ins-slow"><?php _e('Still working. A slow host can take a minute on this one.'); ?></p>
            </div>
            <?php install_panel('danger', __('Setup stopped'), '', array('id' => 'ins-progress-error', 'hidden' => true)); ?>
            <div id="ins-progress-announcer" class="ins-visually-hidden" role="status" aria-live="polite"></div>
            <ul id="ins-tasks" class="ins-tasks"></ul>
        </div>
        <div class="ins-actions">
            <a id="ins-progress-reload" class="ins-btn ins-btn-primary" href="install.php?step=4" hidden><?php _e('Reload'); ?></a>
            <span id="ins-eta" class="ins-hint ins-hint-progress">
                <svg class="ins-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6.5"/><path d="M8 4.5V8l2.5 1.5"/></svg>
                <span><?php _e('Keep this tab open until it finishes.'); ?></span>
            </span>
        </div>
    </section>
</div>
