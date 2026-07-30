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

// Which rail stop to render as current. $step tracks the backend's own state
// machine, which folds two different screens into step 3 (the target form on
// success, the database form again on error) — the rail needs to show the
// screen the visitor is actually looking at, not the raw step number.
$ins_showing_db_retry = $step === 3 && isset($error['error']);
if ($step === 1) {
    $ins_rail_step = 1;
} elseif ($step === 2 || $ins_showing_db_retry) {
    $ins_rail_step = 2;
} elseif ($step === 3) {
    $ins_rail_step = 3;
} else {
    $ins_rail_step = 4;
}

$ins_step_labels = array(
    1 => __('Check server'),
    2 => __('Connect database'),
    3 => __('Your site'),
    4 => __('Done'),
);

$ins_i18n = array(
    'testing'      => __('Testing…'),
    'copied'       => __('Copied'),
    'copyFailed'   => __("Couldn't copy. Select the text and copy it manually."),
    'showPassword' => __('Show password'),
    'hidePassword' => __('Hide password'),
    'networkError' => __('Something went wrong. Check your connection and try again.'),
);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php _e('Shopclass Installation'); ?></title>
    <link rel="stylesheet" type="text/css" media="all" href="<?php echo get_absolute_url(); ?>oc-includes/osclass/installer/install.css" />
    <script type="application/json" id="ins-i18n"><?php echo json_encode($ins_i18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
    <script src="<?php echo get_absolute_url(); ?>oc-includes/osclass/installer/install.js" type="text/javascript" defer></script>
</head>

<body>
    <div class="ins-page">
        <div class="ins-logo">
            <img src="<?php echo get_absolute_url(); ?>oc-includes/images/shopclass-logo.svg" alt="Shopclass" />
        </div>

        <?php if ($already_installed) { ?>
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
                                <li class="ins-step <?php echo $ins_state; ?>" <?php echo $ins_state === 'is-current' ? 'aria-current="step"' : ''; ?>>
                                    <span class="ins-step-dot" aria-hidden="true">
                                        <?php if ($ins_state === 'is-done') { ?>
                                            <svg viewBox="0 0 16 16" width="12" height="12" focusable="false"><path d="M3 8.5l3 3 7-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        <?php } else {
                                            echo (int)$ins_n;
                                        } ?>
                                    </span>
                                    <span>
                                        <span class="ins-step-label"><?php echo osc_esc_html($ins_label); ?></span>
                                        <?php if ($ins_state === 'is-done') { ?>
                                            <span class="ins-step-status"><?php _e('Done'); ?></span>
                                        <?php } ?>
                                    </span>
                                </li>
                            <?php } ?>
                        </ol>
                    </nav>

                    <div class="ins-content">
                        <?php if ($step === 1) { ?>
                            <h1 class="ins-headline"><?php _e('Welcome to Shopclass'); ?></h1>
                            <p class="ins-body"><?php _e("Before we begin, let's make sure your server has everything it needs."); ?></p>

                            <form action="install.php" method="post">
                                <input type="hidden" name="step" value="2" />

                                <?php if (count($jsonLocales) > 1) { ?>
                                    <div class="ins-field">
                                        <label for="install_locale"><?php _e('Language'); ?></label>
                                        <select class="ins-input" id="install_locale" name="install_locale">
                                            <?php foreach ($jsonLocales as $ins_locale_code => $ins_locale_label) { ?>
                                                <option value="<?php echo osc_esc_html($ins_locale_code); ?>" <?php echo $ins_locale_code === Session::newInstance()->_get('userLocale') ? 'selected="selected"' : ''; ?>><?php echo osc_esc_html($ins_locale_label); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                <?php } ?>

                                <?php if ($error) { ?>
                                    <div class="ins-panel ins-panel-danger" role="alert">
                                        <div class="ins-panel-body">
                                            <strong><?php _e('Your server is missing something Shopclass needs.'); ?></strong>
                                            <ul>
                                                <?php foreach ($requirements as $ins_req) { ?>
                                                    <?php if (!$ins_req['fn'] && $ins_req['solution'] !== '') { ?>
                                                        <li><?php echo $ins_req['solution']; ?></li>
                                                    <?php } ?>
                                                <?php } ?>
                                            </ul>
                                            <a href="https://github.com/mindstellar/shopclass/discussions" target="_blank" rel="noopener" hreflang="en"><?php _e('Need more help?'); ?></a>
                                        </div>
                                    </div>
                                <?php } else { ?>
                                    <div class="ins-panel ins-panel-success">
                                        <div class="ins-panel-body"><?php _e('Your server meets every requirement.'); ?></div>
                                    </div>
                                <?php } ?>

                                <h2 class="ins-title"><?php _e('Server checklist'); ?></h2>
                                <ul class="ins-checklist">
                                    <?php foreach ($requirements as $ins_req) { ?>
                                        <li>
                                            <span><?php echo $ins_req['requirement']; ?></span>
                                            <?php if ($ins_req['fn']) { ?>
                                                <span class="ins-check-status is-met">
                                                    <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false"><path d="M3 8.5l3 3 7-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                                    <?php _e('Met'); ?>
                                                </span>
                                            <?php } else { ?>
                                                <span class="ins-check-status is-missing">
                                                    <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
                                                    <?php _e('Missing'); ?>
                                                </span>
                                            <?php } ?>
                                        </li>
                                    <?php } ?>
                                </ul>

                                <div class="ins-actions">
                                    <?php if ($error) { ?>
                                        <button type="button" class="ins-btn ins-btn-secondary" onclick="document.location = 'install.php?step=1'"><?php _e('Check again'); ?></button>
                                    <?php } else { ?>
                                        <button type="submit" class="ins-btn ins-btn-primary"><?php _e('Continue'); ?></button>
                                    <?php } ?>
                                </div>
                            </form>
                        <?php } elseif ($step === 2) {
                            display_database_config();
                        } elseif ($step === 3) {
                            if (!isset($error['error'])) {
                                display_target();
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
                        } elseif ($step === 4 && install_nonce_check()) {
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

                            // Install bender theme for first time.
                            if (!is_dir(CONTENT_PATH . 'themes/bender')) {
                                $fileSystem      = new \mindstellar\utility\FileSystem();
                                $download_path   = CONTENT_PATH . 'downloads/';
                                if ($downloaded = $fileSystem->downloadFile(
                                    'https://github.com/mindstellar/theme-bender/releases/download/v3.2.3/bender_3.2.3.zip',
                                    $download_path . 'bender.zip'
                                )) {
                                    $zip = new \mindstellar\utility\Zip();
                                    $zip->unzipFile($downloaded, CONTENT_PATH . 'themes/');
                                    $fileSystem->remove($downloaded);
                                }
                            }
                        } elseif ($step === 4) {
                            // Missing/invalid installer nonce: never finalize the
                            // install (that writes the osclass_installed sentinel
                            // and would lock out the installer). Show a neutral
                            // message instead of silently completing.
                            ?>
                            <div class="ins-panel ins-panel-warning" role="alert">
                                <?php _e('Your session expired before setup finished. Please restart the installer to complete it.'); ?>
                            </div>
                            <p>
                                <a class="ins-btn ins-btn-primary" href="install.php"><?php _e('Restart the installer'); ?></a>
                            </p>
                            <?php
                        }
                        ?>
                    </div>
                </div>
                <div class="ins-card-footer">
                    <ul class="ins-footer-links">
                        <li><a href="https://docs.mindstellar.com/osclass-docs/" target="_blank" rel="noopener" hreflang="en"><?php _e('Documentation'); ?></a></li>
                        <li><a href="https://github.com/mindstellar/shopclass/" target="_blank" rel="noopener" hreflang="en"><?php _e('Feedback'); ?></a></li>
                        <li><a href="https://github.com/mindstellar/shopclass/discussions" target="_blank" rel="noopener" hreflang="en"><?php _e('Forums'); ?></a></li>
                    </ul>
                </div>
            </div>
        <?php } ?>
    </div>
</body>

</html>
