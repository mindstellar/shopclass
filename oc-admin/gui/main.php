<?php
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
        <script type="text/javascript" src="<?php echo osc_assets_url('jquery/jquery.min.js'); ?>"></script>
        <script type="text/javascript" src="<?php echo osc_assets_url('bootstrap/bootstrap.min.js'); ?>"></script>
        <?php osc_run_hook('admin_login_footer'); ?>
    </div>
</div>
</body>
</html>
