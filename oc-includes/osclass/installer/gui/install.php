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

require_once __DIR__ . '/fields.php';

/**
 * @var int                  $step
 * @var mixed                $error
 * @var array                $requirements
 * @var array<string,string> $jsonLocales
 * @var bool                 $already_installed
 * @var string               $password
 * @var array                $shop_offer
 * @var bool                 $site_choice_saved
 */

// Which rail stop to render as current. $step tracks the backend's own state
// machine, which folds two different screens into step 3 (the target form on
// success, the database form again on error) — the rail needs to show the
// screen the visitor is actually looking at, not the raw step number.
$ins_showing_db_retry = $step === 3 && isset($error['error']);
if ($step === 1) {
    $ins_rail_step = 1;
} elseif ($step === 2 || $ins_showing_db_retry) {
    $ins_rail_step = 2;
} elseif ($step === 5) {
    $ins_rail_step = 6;
} else {
    $ins_rail_step = min($step, 4);
}

$ins_step_labels = array(
    1 => __('Check server'),
    2 => __('Database'),
    3 => __('Site type'),
    4 => __('Your site'),
    5 => __('Install'),
    6 => __('Done'),
);

$ins_i18n = array(
    'testing'      => __('Testing…'),
    'checking'     => __('Checking…'),
    'creatingTables' => __('Creating tables…'),
    'copied'       => __('Copied'),
    'copyFailed'   => __("Couldn't copy. Select the text and copy it manually."),
    'showPassword' => __('Show password'),
    'hidePassword' => __('Hide password'),
    'networkError' => __('Something went wrong. Check your connection and try again.'),
    /* translators: 1: tasks finished, 2: all tasks */
    'doneCount'    => __('%1$s of %2$s done'),
    'allDone'      => __('All done'),
    'starting'     => __('Starting…'),
    /* translators: %s: the setup task that finished */
    'taskDone'     => __('%s done'),
    /* translators: 1: the setup task, 2: what could not be added and why */
    'taskSkipped'  => __('%1$s. %2$s'),
    'lost'         => __('The server did not answer in time, or the connection dropped.'),
    /* translators: 1: units added, 2: all units */
    'addedCount'   => __('%1$s of %2$s added'),
    /* translators: %s: what could not be added */
    'skippedSub'   => __("%s couldn't be added. You can add it later."),
    'installStep'  => __('Install'),
    /* translators: 1: units finished, 2: all units in the task */
    'unitCount'    => __('%1$s of %2$s'),
    /* translators: 1: current step number, 2: total steps */
    'stepCount'    => __('Step %1$s of %2$s'),
);

$ins_locale_code = isset($current_locale) ? (string) $current_locale : 'en_US';
$ins_html_lang   = preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $ins_locale_code) ? str_replace('_', '-', $ins_locale_code) : 'en';
$ins_html_dir    = isset($locales[$ins_locale_code]['direction']) && $locales[$ins_locale_code]['direction'] === 'rtl' ? 'rtl' : 'ltr';
?>
<!doctype html>
<html lang="<?php echo osc_esc_html($ins_html_lang); ?>" dir="<?php echo $ins_html_dir; ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <script>
        window.insTheme = {
            get: function() {
                try {
                    var v = localStorage.getItem('ins-theme');
                    return v === 'light' || v === 'dark' ? v : null;
                } catch (e) {
                    return null;
                }
            },
            set: function(v) {
                try {
                    localStorage.setItem('ins-theme', v);
                } catch (e) {}
            },
            resolve: function() {
                return this.get() || (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            }
        };
        document.documentElement.setAttribute('data-bs-theme', window.insTheme.resolve());
    </script>
    <title><?php _e('Shopclass Installation'); ?></title>
    <link rel="stylesheet" type="text/css" media="all" href="<?php echo get_absolute_url(); ?>oc-includes/osclass/installer/install.css" />
    <script type="application/json" id="ins-i18n"><?php echo json_encode($ins_i18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
    <script src="<?php echo get_absolute_url(); ?>oc-includes/osclass/installer/install.js" type="text/javascript" defer></script>
</head>

<body>
    <div class="ins-page">
        <header class="ins-topbar">
            <span class="ins-logo"><?php readfile(__DIR__ . '/../logo.svg'); ?></span>
            <div class="ins-top-tools">
                <?php if ($step === 1 && empty($db_unreachable) && !$already_installed && count($jsonLocales) > 1) { ?>
                    <div class="ins-lang">
                        <svg class="ins-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6.5"/><path d="M1.5 8h13M8 1.5c-2 2-2.8 4-2.8 6.5S6 12.5 8 14.5c2-2 2.8-4 2.8-6.5S10 3.5 8 1.5z"/></svg>
                        <label class="ins-visually-hidden" for="install_locale"><?php _e('Language'); ?></label>
                        <select id="install_locale" name="install_locale" form="ins-requirements-form">
                            <?php foreach ($jsonLocales as $ins_locale_code => $ins_locale_label) { ?>
                                <option value="<?php echo osc_esc_html($ins_locale_code); ?>" <?php echo $ins_locale_code === Session::getInstance()->_get('userLocale') ? 'selected="selected"' : ''; ?>><?php echo osc_esc_html($ins_locale_label); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                <?php } ?>
                <button type="button" id="ins-theme-toggle" class="ins-btn ins-btn-ghost ins-btn-icon" aria-pressed="false" aria-label="<?php echo osc_esc_html(__('Dark mode')); ?>">
                    <svg class="ins-icon ins-icon-sun" viewBox="0 0 16 16" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="2.75" /><path d="M8 1.5v1.5M8 13v1.5M1.5 8H3M13 8h1.5M3.4 3.4l1 1M11.6 11.6l1 1M3.4 12.6l1-1M11.6 4.4l1-1" /></svg>
                    <svg class="ins-icon ins-icon-moon" viewBox="0 0 16 16" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 9.5A5.5 5.5 0 016.5 3a5.5 5.5 0 106.5 6.5z" /></svg>
                </button>
            </div>
        </header>

        <?php if (!empty($db_unreachable)) { ?>
            <div class="ins-card">
                <div class="ins-card-main">
                    <div class="ins-content ins-simple-card">
                        <h1 class="ins-headline"><?php _e("Shopclass can't reach its database."); ?></h1>
                        <p class="ins-body">
                            <?php echo defined('OSC_CONFIG_FROM_ENV') && OSC_CONFIG_FROM_ENV
                                ? osc_esc_html(__('Check that the database server is running and that the database settings in the environment are right, then reload this page.'))
                                : osc_esc_html(__('Check that the database server is running and that the settings in config.php are right, then reload this page. To install from scratch, delete config.php first.')); ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php } elseif ($already_installed) { ?>
            <div class="ins-card">
                <div class="ins-card-main">
                    <div class="ins-content ins-simple-card">
                        <h1 class="ins-headline"><?php _e('Shopclass is already installed here.'); ?></h1>
                        <p class="ins-body">
                            <?php _e('This installer only runs once, and it looks like this site is already set up. If you genuinely need to start over, remove the tables from your database first, then reload this page.'); ?>
                        </p>
                        <div class="ins-actions">
                            <a class="ins-btn ins-btn-primary" href="<?php echo osc_esc_html(get_absolute_url()); ?>oc-admin/index.php"><?php _e('Go to the admin panel'); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        <?php } else { ?>
            <div class="ins-card">
                <div class="ins-card-main">
                    <nav class="ins-rail" aria-label="<?php echo osc_esc_html(__('Installation progress')); ?>">
                        <ol class="ins-steps">
                            <?php foreach ($ins_step_labels as $ins_n => $ins_label) {
                                $ins_state = 'is-upcoming';
                                if ($ins_n < $ins_rail_step) {
                                    $ins_state = 'is-done';
                                } elseif ($ins_n === $ins_rail_step) {
                                    $ins_state = 'is-current';
                                }
                                ?>
                                <li class="ins-step <?php echo $ins_state; ?>" data-rail="<?php echo (int)$ins_n; ?>" <?php echo $ins_state === 'is-current' ? 'aria-current="step"' : ''; ?>>
                                    <span class="ins-step-dot" aria-hidden="true">
                                        <span class="ins-step-num"><?php echo (int)$ins_n; ?></span>
                                        <svg class="ins-step-check" viewBox="0 0 16 16" width="12" height="12" focusable="false"><path d="M3 8.5l3 3 7-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    </span>
                                    <span>
                                        <span class="ins-step-label"><?php echo osc_esc_html($ins_label); ?></span>
                                        <?php if ($ins_state === 'is-done') { ?>
                                            <span class="ins-step-status ins-visually-hidden"><?php _e('Done'); ?></span>
                                        <?php } ?>
                                    </span>
                                </li>
                            <?php } ?>
                        </ol>
                    </nav>

                    <div class="ins-content">
                        <div class="ins-mstep">
                            <div class="ins-mstep-row"><b id="ins-mstep-label"><?php echo osc_esc_html($ins_step_labels[$ins_rail_step]); ?></b><span id="ins-mstep-count" class="ins-mstep-count"><?php /* translators: 1: current step number, 2: total steps */ echo osc_esc_html(sprintf(__('Step %1$s of %2$s'), $ins_rail_step, count($ins_step_labels))); ?></span></div>
                            <div class="ins-segs" aria-hidden="true">
                                <?php foreach ($ins_step_labels as $ins_n => $ins_label) { ?>
                                    <span<?php echo $ins_n <= $ins_rail_step ? ' class="is-on"' : ''; ?>></span>
                                <?php } ?>
                            </div>
                        </div>
                        <?php if ($step === 1) { ?>
                            <form class="ins-stage" id="ins-requirements-form" action="install.php" method="post">
                                <input type="hidden" name="step" value="2" />
                                <div class="ins-stage-body">
                                    <h1 class="ins-headline"><?php _e('Let’s set up your site'); ?></h1>
                                    <p class="ins-body"><?php _e("Before we begin, let's make sure your server has everything it needs."); ?></p>
                                    <?php
                                    include __DIR__ . '/install-requirements.php';
                                    /** @var int $ins_fail_count Set by the partial. */
                                    ?>
                                </div>

                                <div class="ins-actions">
                                    <?php if ($ins_fail_count > 0) { ?>
                                        <span class="ins-hint">
                                            <svg class="ins-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.8l6.5 11.4h-13z"/><path d="M8 6.5v3M8 11.5v.1"/></svg>
                                            <span><?php echo osc_esc_html(sprintf(_n('Fix the %d item above to continue.', 'Fix the %d items above to continue.', $ins_fail_count), $ins_fail_count)); ?></span>
                                        </span>
                                        <button type="submit" class="ins-btn ins-btn-secondary" disabled><?php _e('Continue'); ?></button>
                                        <a class="ins-btn ins-btn-primary" id="ins-check-again" href="install.php?step=1"><?php _e('Check again'); ?></a>
                                    <?php } else { ?>
                                        <button type="submit" class="ins-btn ins-btn-primary"><?php _e('Continue'); ?><?php install_btn_icon('next'); ?></button>
                                    <?php } ?>
                                </div>
                            </form>
                        <?php } elseif ($step === 2) {
                            display_database_config();
                        } elseif ($step === 3) {
                            if (!isset($error['error']) && isset($shop_offer)) {
                                display_site_type($shop_offer, install_site_choice());
                            } elseif (!isset($error['error'])) {
                                install_session_expired_panel();
                            } else {
                                $form_data = array(
                                    'dbhost'         => Params::getParam('dbhost'),
                                    'username'       => Params::getParam('username'),
                                    'password'       => Params::getParam('password'),
                                    'dbname'         => Params::getParam('dbname'),
                                    'tableprefix'    => Params::getParam('tableprefix'),
                                    'createdb'       => Params::getParam('createdb'),
                                    'admin_username' => Params::getParam('admin_username'),
                                    'admin_password' => Params::getParam('admin_password'),
                                );
                                display_database_config($form_data, $error);
                            }
                        } elseif ($step === 4 && !empty($site_choice_saved)) {
                            display_target();
                        } elseif ($step === 4) {
                            install_session_expired_panel();
                        } elseif ($step === 5 && install_nonce_check() && Session::getInstance()->_get('install_db_done')) {
                            if (!headers_sent()) {
                                setcookie('osclass_save_stats', '', time() - 3600);
                                setcookie('osclass_ping_engines', '', time() - 3600);
                            }

                            // copy robots.txt
                            $source      = LIB_PATH . 'osclass/installer/robots.txt';
                            $destination = ABS_PATH . 'robots.txt';
                            if (function_exists('copy')) {
                                @copy($source, $destination);
                            } else {
                                $contentx   = @file_get_contents($source);
                                $openedfile = fopen($destination, 'wb');
                                fwrite($openedfile, $contentx);
                                fclose($openedfile);
                            }

                            display_finish($password);
                        } elseif ($step === 5) {
                            // A missing nonce must never finalize: that writes osclass_installed and locks the installer.
                            install_session_expired_panel();
                        }
            ?>
                    </div>
                </div>
            </div>
        <?php } ?>

        <footer class="ins-under">
            <ul class="ins-footer-links">
                <li><a href="<?php echo \mindstellar\utility\ProjectLinks::INSTALL; ?>" target="_blank" rel="noopener" hreflang="en"><?php _e('Documentation'); ?></a></li>
                <li><a href="<?php echo \mindstellar\utility\ProjectLinks::REPOSITORY; ?>" target="_blank" rel="noopener" hreflang="en"><?php _e('Feedback'); ?></a></li>
                <li><a href="<?php echo \mindstellar\utility\ProjectLinks::FORUM; ?>" target="_blank" rel="noopener" hreflang="en"><?php _e('Forums'); ?></a></li>
            </ul>
        </footer>
    </div>
</body>

</html>
