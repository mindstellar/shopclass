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
 * Parse the newest release section of CHANGELOG.md into a label plus typed entries,
 * the entries ordered so features and notable changes surface first. Falls back to
 * the running version and an empty list when the changelog is missing or unreadable.
 *
 * @return array{label:string,entries:array<int,array{cat:string,text:string}>}
 */
function upgradeReleaseNotes()
{
    $fallbackLabel = 'Shopclass ' . (defined('OSCLASS_VERSION') ? OSCLASS_VERSION : '');
    $file          = ABS_PATH . 'CHANGELOG.md';
    if (!is_readable($file)) {
        return array('label' => $fallbackLabel, 'entries' => array());
    }

    $label   = $fallbackLabel;
    $entries = array();
    $inFirst = false;
    $cat     = '';
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        // "## Shopclass 5.3.0" opens a release; the next one ends the newest section.
        if (preg_match('/^##\s+(?!#)(.+?)\s*$/', $line, $m)) {
            if ($inFirst) {
                break; // reached the previous release
            }
            $inFirst = true;
            $label   = trim($m[1]);
            continue;
        }
        if (!$inFirst) {
            continue;
        }
        // "### Security" names the category the following bullets belong to.
        if (preg_match('/^###\s+(.+?)\s*$/', $line, $m)) {
            $cat = trim($m[1]);
            continue;
        }
        if (preg_match('/^-\s+(.+)$/', $line, $m)) {
            $entries[] = array('cat' => $cat, 'text' => trim(str_replace('`', '', $m[1])));
            continue;
        }
        // Entries are hard-wrapped, so an indented line continues the previous bullet.
        if ($entries !== array() && preg_match('/^\s+(\S.*)$/', $line, $m)) {
            $last                     = count($entries) - 1;
            $entries[$last]['text'] .= ' ' . trim(str_replace('`', '', $m[1]));
        }
    }

    // Surface features and breaking/security notes before routine fixes; PHP 8's
    // stable sort keeps each category in its authored changelog order.
    $priority = array('New' => 0, 'Breaking' => 1, 'Security' => 2, 'Changed' => 3, 'Performance' => 4, 'Fixed' => 5);
    usort($entries, static function ($a, $b) use ($priority) {
        return ($priority[$a['cat']] ?? 9) <=> ($priority[$b['cat']] ?? 9);
    });

    return array('label' => $label, 'entries' => $entries);
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
$release  = upgradeReleaseNotes();
$whatsNew = $release['entries'];
// Link at the release tag for the current major.minor.patch, dropping any
// pre-release suffix (5.3.0.dev5 => v5.3.0) so it tracks the version; fall back
// to the releases listing if the version can't be parsed.
$version    = defined('OSCLASS_VERSION') ? OSCLASS_VERSION : '';
$releaseUrl = preg_match('/^\d+\.\d+\.\d+/', $version, $m)
    ? 'https://github.com/mindstellar/shopclass/releases/tag/v' . $m[0]
    : 'https://github.com/mindstellar/shopclass/releases';
if (!empty($whatsNew)) {
    $shown     = array_slice($whatsNew, 0, 10);
    $remaining = count($whatsNew) - count($shown);
    ?>
    <section class="whatsnew" aria-labelledby="whatsnew-title">
        <header class="whatsnew-head">
            <div>
                <h2 id="whatsnew-title" class="whatsnew-heading"><?php _e("What's new"); ?></h2>
                <p class="whatsnew-sub">
                    <?php printf(__('Highlights from %s'), osc_esc_html($release['label'])); ?>
                </p>
            </div>
            <span class="whatsnew-count">
                <?php printf(_n('%d change', '%d changes', count($whatsNew)), count($whatsNew)); ?>
            </span>
        </header>
        <ul class="whatsnew-list">
            <?php foreach ($shown as $entry) {
                $slug = strtolower(preg_replace('/[^a-z]/i', '', $entry['cat'])); ?>
                <li class="whatsnew-item">
                    <span class="whatsnew-tag whatsnew-tag-<?php echo osc_esc_html($slug); ?>"><?php
                        echo osc_esc_html($entry['cat']); ?></span>
                    <span class="whatsnew-text"><?php echo osc_esc_html($entry['text']); ?></span>
                </li>
            <?php } ?>
        </ul>
        <footer class="whatsnew-foot">
            <a class="whatsnew-more-link" href="<?php echo osc_esc_html($releaseUrl); ?>"
               target="_blank" rel="noopener noreferrer">
                <?php if ($remaining > 0) {
                    printf(_n('%d more change', '%d more changes', $remaining), $remaining);
                    echo ' · ';
                } ?>
                <?php _e('Read the full release notes on GitHub'); ?>
                <span aria-hidden="true">↗</span>
            </a>
        </footer>
    </section>
<?php } ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
