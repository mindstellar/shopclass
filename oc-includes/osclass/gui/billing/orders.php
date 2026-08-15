<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Core's fallback orders page -- rendered only when the active theme has
 * neither user-billing-orders.php nor user-custom.php of its own. Wraps
 * orders-content.php (the markup) in a self-contained page. See
 * CWebBilling::doView().
 */

$themePath = WebThemes::newInstance()->getCurrentThemePath();
$hasChrome = file_exists($themePath . 'header.php') && file_exists($themePath . 'footer.php');

if ($hasChrome) {
    osc_current_web_theme_path('header.php');
} else {
    ?><!doctype html>
<html lang="<?php echo osc_esc_html(str_replace('_', '-', osc_current_user_locale())); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo osc_esc_html(_m('Your orders')); ?></title>
<style>html,body{margin:0;padding:0;background:#f7f9fb;}</style>
</head>
<body>
    <?php
}

require __DIR__ . '/orders-content.php';

if ($hasChrome) {
    osc_current_web_theme_path('footer.php');
} else {
    ?>
</body>
</html>
<?php
}
