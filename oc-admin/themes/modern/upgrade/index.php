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

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Tools'); ?></h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return __('Upgrade');
}


osc_add_filter('admin_title', 'customPageTitle');

//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            <?php if (Params::getParam('confirm') === 'true') {?>
            var output = document.getElementById('output');
            if (output) { output.style.display = ''; }
            var tohide = document.getElementById('tohide');
            if (tohide) { tohide.style.display = 'none'; }

            fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=upgrade_db&skipdb=<?php echo Params::getParam('skipdb')?>&<?php echo osc_csrf_token_url(); ?>', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json();
            }).then(function (data) {
                var loading = document.getElementById('loading_image');
                if (loading) { loading.style.display = 'none'; }
                var result = document.getElementById('result');
                if (result) {
                    result.innerHTML = (data.error === 1)
                        ? 'Error: ' + data.message.replace(/\n/g, '<br />')
                        : 'Success: ' + data.message + '<br />';
                }
            });
            <?php } ?>
        });
    </script>
<?php }


osc_add_hook('admin_header', 'customHead', 10);

/**
 * Parse the newest release section of CHANGELOG.md into typed entries, ordered so
 * features and notable changes surface first. Returns an empty array when the
 * changelog is missing or unreadable.
 *
 * @return array<int,array{cat:string,text:string}>
 */
function upgradeReleaseHighlights()
{
    $file = ABS_PATH . 'CHANGELOG.md';
    if (!is_readable($file)) {
        return array();
    }

    $entries = array();
    $inFirst = false;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        if (strpos($line, '## ') === 0) {
            if ($inFirst) {
                break; // reached the previous release
            }
            $inFirst = true; // newest release heading
            continue;
        }
        if ($inFirst && preg_match('/^\*\s*([A-Za-z]+):\s*(.+)$/', $line, $m)) {
            $entries[] = array('cat' => $m[1], 'text' => trim(str_replace('`', '', $m[2])));
        }
    }

    // Surface features and breaking/security notes before routine fixes; PHP 8's
    // stable sort keeps each category in its authored changelog order.
    $priority = array('New' => 0, 'Breaking' => 1, 'Security' => 2, 'Changed' => 3, 'Performance' => 4, 'Fixed' => 5);
    usort($entries, static function ($a, $b) use ($priority) {
        return ($priority[$a['cat']] ?? 9) <=> ($priority[$b['cat']] ?? 9);
    });

    return $entries;
}


osc_current_admin_theme_path('parts/header.php'); ?>

<div id="backup-settings">
    <h2 class="render-title"><?php _e('Upgrade'); ?></h2>
    <div id="result">
        <div id="output" style="display:none">
            <span class="spinner-border text-secondary" style="width:1.2rem;height:1.2rem" role="status"></span>
            <?php _e('Upgrading your Shopclass installation (this could take a while): ', 'admin'); ?>
        </div>
        <div id="tohide">
            <p>
                <?php _e('You have uploaded a new version of Shopclass, you need to upgrade Shopclass for it to work correctly.'); ?>
            </p>
            <a class="btn btn-dim"
               href="<?php echo osc_admin_base_url(true); ?>?page=upgrade&confirm=true"><?php _e('Upgrade now'); ?></a>
        </div>
    </div>
</div>
<?php
$whatsNew = upgradeReleaseHighlights();
if (!empty($whatsNew)) {
    $shown     = array_slice($whatsNew, 0, 12);
    $remaining = count($whatsNew) - count($shown);
    ?>
    <div class="whatsnew card mb-3">
        <div class="card-body">
            <h2 class="render-title"><?php _e("What's new"); ?></h2>
            <ul class="whatsnew-list">
                <?php foreach ($shown as $entry) {
                    $slug = strtolower(preg_replace('/[^a-z]/i', '', $entry['cat'])); ?>
                    <li class="whatsnew-item">
                        <span class="whatsnew-tag whatsnew-tag-<?php echo osc_esc_html($slug); ?>">
                            <?php echo osc_esc_html($entry['cat']); ?>
                        </span>
                        <span class="whatsnew-text"><?php echo osc_esc_html($entry['text']); ?></span>
                    </li>
                <?php } ?>
            </ul>
            <?php if ($remaining > 0) { ?>
                <p class="whatsnew-more">
                    <?php printf(_n('and %d more change in this release',
                        'and %d more changes in this release', $remaining), $remaining); ?>
                </p>
            <?php } ?>
        </div>
    </div>
<?php } ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
