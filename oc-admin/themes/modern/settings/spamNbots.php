<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
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

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}

function addHelp()
{
    echo '<p>'
         . __('Keep spammers from publishing on your site by configuring reCAPTCHA and Akismet. '
              . 'Be careful: in order to use these services, you must register on their sites first and follow their instructions.')
         . '</p>';
}

osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Spam and bots &raquo; %s'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="spam-setting">
    <h2 class="render-title"><?php _e('Spam and bots'); ?></h2>
    <div id="akismet-settings">
        <h3 class="render-title"><?php _e('Akismet'); ?></h3>
        <p><?php _e("Akismet is a hosted web service that saves you time by automatically detecting comment and trackback spam. "
                    . "It's hosted on our servers, but we give you access to it through plugins and our API."); ?></p>
        <form name="settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="akismet_post"/>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="form-label"><?php _e('Akismet API Key'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-large" name="akismetKey"
                                   value="<?php echo(osc_akismet_key() ? osc_esc_html(osc_akismet_key()) : ''); ?>"/>
                            <?php
                            $akismet_status = View::newInstance()->_get('akismet_status');
$alert_msg      = '';
$alert_type     = 'error';
switch ($akismet_status) {
    case 1:
        $alert_type = 'ok';
        $alert_msg  = __('This key is valid');
        break;
    case 2:
        $alert_type = 'error';
        $alert_msg  = __('The key you entered is invalid. Please double-check it');
        break;
    case 3:
        $alert_type = 'warning';
        $alert_msg  =
            sprintf(
                __('Akismet is disabled, please enter an API key. <a href="%s" target="_blank">(Get your key)</a>'),
                'http://akismet.com/get/'
            );
        break;
}
?>
                            <div class="callout-<?php echo $alert_type; ?> separate-top-medium">
                                <p><?php echo $alert_msg; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <input type="submit" id="submit_akismet" value="<?php echo osc_esc_html(__('Save changes')); ?>"
                               class="btn btn-submit"/>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
    <div id="recaptcha-settings" class="separate-top">
        <h3 class="render-title"><?php _e('Captcha'); ?></h3>
        <p><?php printf(
            __('Protect your site from automated abuse with Google reCAPTCHA or Cloudflare Turnstile. '
                           . '<a href="%1$s" target="_blank">Get a reCAPTCHA key</a> or '
                           . '<a href="%2$s" target="_blank">get a Turnstile key</a>.'),
            'https://www.google.com/recaptcha/admin#whyrecaptcha',
            'https://dash.cloudflare.com/?to=/:account/turnstile'
        ); ?></p>
        <form name="settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="recaptcha_post"/>
            <input type="hidden" id="recaptchaVersion" name="recaptchaVersion" value="2"/>
            <fieldset class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Captcha provider'); ?></div>
                    <div class="form-controls">
                        <?php $captcha_provider_pref = osc_captcha_provider_pref(); ?>
                        <select class="form-select form-select-sm" name="captchaProvider">
                            <option value="auto" <?php echo ($captcha_provider_pref === 'auto')
            ? 'selected="true"' : ''; ?>><?php _e('Automatic'); ?></option>
                            <option value="turnstile" <?php echo ($captcha_provider_pref === 'turnstile')
            ? 'selected="true"' : ''; ?>><?php _e('Cloudflare Turnstile'); ?></option>
                            <option value="recaptcha" <?php echo ($captcha_provider_pref === 'recaptcha')
            ? 'selected="true"' : ''; ?>><?php _e('Google reCAPTCHA'); ?></option>
                            <option value="none" <?php echo ($captcha_provider_pref === 'none')
            ? 'selected="true"' : ''; ?>><?php _e('None'); ?></option>
                        </select>
                        <div class="help-box">
                            <?php _e('Automatic prefers reCAPTCHA whenever its site and secret keys below are set; clear '
                 . 'both to let Turnstile take over instead. Pick a provider to force it, or None to '
                 . 'turn captchas off.'); ?>
                        </div>
                        <?php
                        // A forced provider (Turnstile/reCAPTCHA) whose keys are blank
                        // resolves to 'none', silently disabling captcha site-wide.
                        // Warn the admin instead of leaving only the help text.
                        if (osc_captcha_provider() === 'none'
        && ($captcha_provider_pref === 'turnstile' || $captcha_provider_pref === 'recaptcha')
                        ) {
                            $captcha_missing_keys = ($captcha_provider_pref === 'turnstile')
                                ? __('Turnstile site key and Turnstile secret key')
                                : __('reCAPTCHA site key and reCAPTCHA secret key');
                            ?>
                            <div class="callout-warning separate-top-medium">
                                <p><?php echo osc_esc_html(sprintf(
                                    __('Captcha is currently off: you forced a provider but its keys are empty. '
                                           . 'Enter the %s below to turn captcha on.'),
                                    $captcha_missing_keys
                                )); ?></p>
                            </div>
                        <?php } ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('reCAPTCHA site key'); ?></div>
                    <div class="form-controls">
                        <input type="text" class="input-large" name="recaptchaPubKey"
                               value="<?php echo(osc_recaptcha_public_key() ? osc_esc_html(osc_recaptcha_public_key())
                                       : ''); ?>"/>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('reCAPTCHA secret key'); ?></div>
                    <div class="form-controls">
                        <input type="text" class="input-large" name="recaptchaPrivKey"
                               value="<?php echo(osc_recaptcha_private_key() ? osc_esc_html(osc_recaptcha_private_key())
                                       : ''); ?>"/>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Turnstile site key'); ?></div>
                    <div class="form-controls">
                        <input type="text" class="input-large" name="turnstileSiteKey"
                               value="<?php echo(osc_turnstile_site_key() ? osc_esc_html(osc_turnstile_site_key())
                                       : ''); ?>"/>
                        <div class="help-box">
                            <?php _e('From the Cloudflare dashboard &raquo; Turnstile.'); ?>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Turnstile secret key'); ?></div>
                    <div class="form-controls">
                        <input type="text" class="input-large" name="turnstileSecretKey"
                               value="<?php echo(osc_turnstile_secret_key() ? osc_esc_html(osc_turnstile_secret_key())
                                       : ''); ?>"/>
                        <div class="help-box">
                            <?php _e('From the Cloudflare dashboard &raquo; Turnstile.'); ?>
                        </div>
                    </div>
                </div>
                <?php if (osc_captcha_enabled()) { ?>
                    <div class="form-row">
                        <div class="form-label">
                            <?php _e('If you see a captcha widget below, the active provider is configured correctly'); ?>
                        </div>
                        <div class="form-controls">
                            <?php osc_show_captcha(); ?>
                        </div>
                    </div>
                <?php } ?>
                <div class="form-actions">
                    <input type="submit" id="submit_recaptcha" value="<?php echo osc_esc_html(__('Save changes')); ?>"
                           class="btn btn-submit"/>
                </div>
            </fieldset>
        </form>
    </div>
    <div id="alerts-settings" class="separate-top">
        <h3 class="render-title"><?php _e('Search alerts'); ?></h3>
        <p><?php _e('Search alerts email visitors when new listings match a saved search. Requiring login before '
                    . 'subscribing prevents anonymous email harvesting and confirmation-email abuse through the alert endpoint.'); ?></p>
        <form name="settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="alerts_post"/>
            <fieldset class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Require login for alerts'); ?></div>
                    <div class="form-controls">
                        <label>
                            <input type="checkbox" name="alerts_require_login" value="1"
                                <?php echo osc_get_preference('alerts_require_login') ? 'checked="checked"' : ''; ?>/>
                            <?php _e('Only logged-in users can subscribe to search alerts'); ?>
                        </label>
                    </div>
                </div>
                <div class="form-actions">
                    <input type="submit" id="submit_alerts" value="<?php echo osc_esc_html(__('Save changes')); ?>"
                           class="btn btn-submit"/>
                </div>
            </fieldset>
        </form>
    </div>
    <div id="login-throttle-settings" class="separate-top">
        <h3 class="render-title"><?php _e('Sign-in protection'); ?></h3>
        <p><?php _e('Failed sign-ins and password-reset requests are counted per visitor address and per account '
                    . 'name. Passing a limit refuses further attempts until the older ones age out of the window, '
                    . 'which is what stops a stolen password list being tried one guess at a time.'); ?></p>
        <p><?php _e('The account limit is skipped while a captcha provider is configured above, because every '
                    . 'attempt already has to solve one. Without that, an attacker could hold someone else\'s '
                    . 'account shut simply by failing against it.'); ?></p>
        <form name="settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="login_throttle_post"/>
            <fieldset class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Limit sign-in attempts'); ?></div>
                    <div class="form-controls">
                        <label>
                            <input type="checkbox" name="login_throttle_enabled" value="1"
                                <?php echo osc_login_throttle_enabled() ? 'checked="checked"' : ''; ?>/>
                            <?php _e('Count failed attempts and refuse further ones past the limits below'); ?>
                        </label>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Window'); ?></div>
                    <div class="form-controls">
                        <input type="number" min="1" class="input-small" name="login_throttle_window"
                               value="<?php echo osc_esc_html(osc_login_throttle_window()); ?>"/>
                        <span class="help-inline"><?php _e('minutes. How far back failures are counted, and so how '
                                                              . 'long a refusal lasts.'); ?></span>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Attempts per address'); ?></div>
                    <div class="form-controls">
                        <input type="number" min="1" class="input-small" name="login_throttle_max_ip"
                               value="<?php echo osc_esc_html(osc_login_throttle_max_ip()); ?>"/>
                        <span class="help-inline"><?php _e('Failures from one visitor address, across every account '
                                                              . 'it tried. Keep this generous: an office or mobile '
                                                              . 'network is many people behind one address.'); ?></span>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Attempts per account'); ?></div>
                    <div class="form-controls">
                        <input type="number" min="1" class="input-small" name="login_throttle_max_account"
                               value="<?php echo osc_esc_html(osc_login_throttle_max_account()); ?>"/>
                        <span class="help-inline"><?php _e('Failures against one account name, from anywhere. This '
                                                              . 'is what catches guessing spread across many '
                                                              . 'addresses.'); ?></span>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Keep records for'); ?></div>
                    <div class="form-controls">
                        <input type="number" min="0" class="input-small" name="login_attempt_retention_days"
                               value="<?php echo osc_esc_html(osc_login_attempt_retention_days()); ?>"/>
                        <span class="help-inline"><?php _e('days, pruned by the daily cron. Only the window above '
                                                              . 'affects the limits; the rest is history. 0 keeps '
                                                              . 'everything.'); ?></span>
                    </div>
                </div>
                <div class="form-actions">
                    <input type="submit" id="submit_login_throttle"
                           value="<?php echo osc_esc_html(__('Save changes')); ?>" class="btn btn-submit"/>
                </div>
            </fieldset>
        </form>
        <form name="settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="login_throttle_reset"/>
            <fieldset class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Clear recorded attempts'); ?></div>
                    <div class="form-controls">
                        <input type="submit" id="submit_login_throttle_reset"
                               value="<?php echo osc_esc_html(__('Clear now')); ?>" class="btn"/>
                        <span class="help-inline"><?php _e('Lets anyone currently refused try again straight away, '
                                                              . 'including you.'); ?></span>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
