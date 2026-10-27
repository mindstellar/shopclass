<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
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


osc_admin_page(array(
    'section' => static fn () => sprintf(__('Shopclass %s'), OSCLASS_VERSION),
));

$customPageTitle = static function ($string) {
    return sprintf(__('Shopclass %s &raquo; %s'), OSCLASS_VERSION, $string);
};
osc_add_filter('admin_title', $customPageTitle);

unset($customPageTitle);

osc_current_admin_theme_path('parts/header.php');
?>
<div class="widget-box">
    <div class="widget-box-content">

        <?php
        $changelog = file_get_contents(ABS_PATH . '/CHANGELOG.md');

        // Entries are hard-wrapped; join each continuation line back onto its bullet.
        $changelog = preg_replace('/\R[ \t]+(\S)/', ' $1', $changelog);
        $lines     = array();
        foreach (preg_split('/\R/', $changelog) as $line) {
            // Most specific heading first, so a broader pattern does not take them all.
            if (preg_match('/^(#{1,3})\s*(.*)$/', $line, $m)) {
                $tag     = array(1 => 'h3', 2 => 'h4', 3 => 'h5')[strlen($m[1])];
                $lines[] = "<$tag>" . \mindstellar\admin\InlineMarkdown::render($m[2]) . "</$tag>";
            } elseif (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
                $lines[] = '<li>' . \mindstellar\admin\InlineMarkdown::render($m[1]) . '</li>';
            } else {
                $lines[] = \mindstellar\admin\InlineMarkdown::render($line, osc_base_url());
            }
        }
        $changelog = implode("\n", $lines);
        echo $changelog;
        ?>
    </div>
</div>
<?php
osc_current_admin_theme_path('parts/footer.php'); ?>