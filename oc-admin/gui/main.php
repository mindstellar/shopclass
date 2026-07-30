<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}
?>
<html dir="ltr" lang="en-US">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <meta name="robots" content="noindex, nofollow, noarchive"/>
    <meta name="googlebot" content="noindex, nofollow, noarchive"/>
    <title><?php echo osc_page_title(); ?> &raquo; <?php _e('Log in'); ?></title>
    <link type="text/css" media="screen" rel="stylesheet"
          href="<?php echo osc_current_admin_theme_styles_url('main.css?v=' . OSCLASS_VERSION); ?>"/>
    <link type="text/css" media="screen" rel="stylesheet" href="style/backoffice_login.css"/>
    <link type="text/css" media="screen" rel="stylesheet"
          href="<?php echo osc_assets_url('bootstrap-icons/bootstrap-icons.css'); ?>"/>
    <?php osc_run_hook('admin_login_header'); ?>
</head>
<body class="container">
<div class="row">
    <div class="col-md-12 text-center">
        <div class="form-signin">
            <h1 class="mb-3">
                <a href="<?php echo View::newInstance()->_get('login_admin_url'); ?>"
                   title="<?php echo View::newInstance()->_get('login_admin_title'); ?>">
                    <img class="img-fluid" src="<?php echo View::newInstance()->_get('login_admin_image'); ?>"
                         title="<?php echo
                            View::newInstance()->_get('login_admin_title'); ?>"
                         alt="<?php echo View::newInstance()->_get('login_admin_title'); ?>"/>
                </a>
            </h1>
            <div class="mb-3">
                <?php osc_show_flash_message('admin', 'alert'); ?>
            </div>
            <?php require_once osc_admin_base_path() . View::newInstance()->_get('login_admin_form'); ?>
        </div>
        <script type="text/javascript" src="<?php echo osc_assets_url('bootstrap/bootstrap.min.js'); ?>"></script>
        <script type="text/javascript">
            // The login shell renders its flash message as `.alert` (see the
            // osc_show_flash_message call above), not `.flashmessage`, so the admin theme's
            // own dismissal handler does not cover it — and the theme's JS is not loaded
            // here anyway. Delegated from the document so a plugin rendering its own
            // dismissible box into the login page gets the behaviour for free.
            document.addEventListener('click', function (e) {
                var close = e.target.closest ? e.target.closest('.ico-close') : null;
                if (!close) {
                    return;
                }
                e.preventDefault();
                var box = close.closest('.alert') || close.parentElement;
                if (box) {
                    box.style.display = 'none';
                }
            });
        </script>
        <?php osc_run_hook('admin_login_footer'); ?>
    </div>
</div>
</body>
</html>
