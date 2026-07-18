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


$customPageHeader = static function () { ?>
    <h1><?php printf(__('Shopclass %s'), OSCLASS_VERSION); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse" href="#help-box"></a>
    </h1>
    <?php
};
osc_add_hook('admin_page_header', $customPageHeader);

$customPageTitle = static function ($string) {
    return sprintf(__('Shopclass %s &raquo; %s'), OSCLASS_VERSION, $string);
};
osc_add_filter('admin_title', $customPageTitle);

unset($customPageTitle, $customPageHeader);

osc_current_admin_theme_path('parts/header.php');
?>
<div class="widget-box">
    <div class="widget-box-title">
        <h3>Shopclass <?php echo OSCLASS_VERSION; ?></h3>
    </div>
    <div class="widget-box-content">

        <?php
        $changelog = file_get_contents(ABS_PATH . '/CHANGELOG.md');

        $changelog = preg_replace('/\r\n{2,}/', "\n", $changelog);
        $changelog = preg_replace('/^(#+)(.*)$/m', '<h3>$2</h3>', $changelog);
        $changelog = preg_replace('/^(##+)(.*)$/m', '<h4>$2</h4>', $changelog);
        $changelog = preg_replace('/^(###+)(.*)$/m', '<h5>$2</h5>', $changelog);
        $changelog = preg_replace('/^\*(.*)$/m', '<li>$1</li>', $changelog);
        echo $changelog;
        ?>
    </div>
</div>
<?php
osc_current_admin_theme_path('parts/footer.php'); ?>