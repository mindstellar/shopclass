<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\market\PluginDependencies;

$theme         = (string) __get('theme');
$themeName     = (string) __get('themeName');
$isActiveTheme = (bool) __get('isActiveTheme');
$deps          = __get('dependencies');
$deps          = is_array($deps) ? $deps : array('required' => array(), 'recommended' => array());
$blocked       = osc_market_changes_blocked();

osc_admin_page(array(
    'section' => __('Appearance'),
    'title'   => __('Plugins for this theme'),
));

osc_current_admin_theme_path('parts/market.php');
osc_current_admin_theme_path('parts/package-ui.php');

osc_register_script('admin-dependencies', osc_asset_url_versioned(osc_current_admin_theme_js_url('dependencies.js')), array('admin-osc'));
osc_enqueue_script('admin-dependencies');

$csrf        = osc_csrf_token_url();
$ajaxUrl     = osc_admin_base_url(true) . '?page=ajax&action=market_install_dependency&theme=' . urlencode($theme) . '&' . $csrf;
$activateUrl = osc_admin_base_url(true) . '?page=appearance&action=activate&plugins_seen=1&theme=' . urlencode($theme) . '&' . $csrf;
$backUrl     = osc_admin_base_url(true) . '?page=appearance';
$addPlugin   = osc_admin_base_url(true) . '?page=plugins&action=add';

$states = array(
    PluginDependencies::ACTIVE         => array('active', __('Active')),
    PluginDependencies::INACTIVE       => array('disabled', __('Off')),
    PluginDependencies::MISSING        => array('uninstalled', __('Not installed')),
    PluginDependencies::TOO_OLD        => array('update', __('Too old')),
    PluginDependencies::INCOMPATIBLE   => array('incompatible', __('Not available')),
    PluginDependencies::NOT_IN_CATALOG => array('incompatible', __('Not in the market')),
);

$requiredOpen = 0;
$open         = 0;
$actionable   = 0;
foreach (array_merge($deps['required'], $deps['recommended']) as $row) {
    if ($row['status'] !== PluginDependencies::ACTIVE) {
        $open++;
        $requiredOpen += $row['required'] ? 1 : 0;
    }
    if ($row['action'] !== 'none') {
        $actionable++;
    }
}

$renderRow = static function (array $row) use ($states, $addPlugin) {
    [$state, $word] = $states[$row['status']] ?? $states[PluginDependencies::MISSING];
    $meta           = array($row['required'] ? __('Required') : __('Recommended'));
    if ($row['constraint'] !== '') {
        $meta[] = sprintf(__('Needs version %s'), $row['constraint']);
    }
    if ($row['installed_version'] !== '') {
        $meta[] = sprintf(__('Installed: %s'), $row['installed_version']);
    }
    if ($row['action'] === 'install' || $row['action'] === 'update') {
        $meta[] = sprintf(__('Will install %s'), (string) ($row['offer']['version'] ?? ''));
    }
    ?>
    <li class="osc-pkg osc-pkg--row" data-dep-slug="<?php echo osc_esc_html($row['slug']); ?>"
        data-dep-required="<?php echo $row['required'] ? '1' : '0'; ?>"
        data-dep-action="<?php echo osc_esc_html($row['action']); ?>"
        data-dep-status="<?php echo osc_esc_html($row['status']); ?>">
        <?php osc_package_art(array('src' => '', 'has' => false), $row['slug'], $row['name']); ?>
        <div class="osc-pkg-main">
            <div class="osc-pkg-head">
                <h3 class="osc-pkg-name" dir="auto"><?php echo osc_esc_html($row['name']); ?></h3>
                <?php osc_package_state($state, $word); ?>
            </div>
            <p class="osc-pkg-meta"><?php echo implode(' <span class="osc-pkg-sep">·</span> ', array_map('osc_esc_html', $meta)); ?></p>
            <?php if ($row['description'] !== '') : ?>
                <p class="osc-pkg-desc" dir="auto"><?php echo osc_esc_html($row['description']); ?></p>
            <?php endif; ?>
            <p class="osc-pkg-note osc-pkg-note--warning" data-dep-note<?php echo $row['message'] === '' ? ' hidden' : ''; ?>>
                <span data-dep-message><?php echo osc_esc_html($row['message']); ?></span>
                <a data-dep-manual href="<?php echo osc_esc_html($row['manual_url'] !== '' ? $row['manual_url'] : $addPlugin); ?>"
                   <?php echo $row['manual_url'] !== '' ? 'rel="noopener" target="_blank"' : ''; ?>
                   <?php echo $row['message'] === '' ? 'hidden' : ''; ?>><?php
                    echo osc_esc_html($row['manual_url'] !== '' ? __('Download the zip') : __('Add plugin')); ?></a>
            </p>
        </div>
        <?php if (!$row['required'] && $row['action'] !== 'none') : ?>
            <div class="osc-pkg-actions">
                <label class="form-check">
                    <input type="checkbox" class="form-check-input" data-dep-pick checked>
                    <?php _e('Install'); ?>
                </label>
            </div>
        <?php endif; ?>
    </li>
    <?php
};

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="theme-dependencies"
     data-install-url="<?php echo osc_esc_html($ajaxUrl); ?>"
     data-activate-url="<?php echo osc_esc_html($activateUrl); ?>"
     data-i18n='<?php echo osc_esc_html((string) json_encode(array(
         'working'  => __('Installing…'),
         'active'   => __('Active'),
         'failed'   => __('Failed'),
         'error'    => __('The request failed. Check your connection and try again.'),
         'stillOff' => __('The theme is not active yet: a required plugin is still missing.'),
         'done'     => __('All set. Activating the theme…'),
         'optionalFailed' => __('A recommended plugin could not be installed.'),
         'activateAnyway' => __('Activate the theme without it'),
     ))); ?>'>
    <?php osc_admin_page_head(($isActiveTheme && $requiredOpen > 0)
        ? sprintf(__('%s needs these plugins'), $themeName)
        : ($isActiveTheme ? $themeName : sprintf(__('Before %s goes live'), $themeName))); ?>

    <p>
        <?php if ($isActiveTheme && $requiredOpen > 0) {
            _e('Your active theme needs a plugin that is missing or turned off. The parts that use it stay hidden until it is back.');
        } elseif ($requiredOpen > 0) {
            _e('This theme needs the required plugins below. It goes live once they are installed and active. Recommended ones are optional.');
        } elseif ($open === 0) {
            _e('All the plugins this theme needs are active.');
        } else {
            _e('This theme works better with the plugins below. They are optional.');
        } ?>
    </p>

    <?php if ($blocked) : ?>
        <div class="callout-warning callout-block">
            <span><?php echo osc_esc_html(\mindstellar\security\Demo::active()
                ? \mindstellar\security\Demo::message()
                : __('Package installs are disabled on this deployment. Install the plugins by hand, then come back.')); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($deps['required'] !== array()) : ?>
        <?php osc_admin_page_head(__('Required')); ?>
        <?php osc_package_list_open('theme-deps-list'); ?>
        <?php foreach ($deps['required'] as $row) {
            $renderRow($row);
        } ?>
        <?php osc_package_list_close(); ?>
    <?php endif; ?>

    <?php if ($deps['recommended'] !== array()) : ?>
        <?php osc_admin_page_head(__('Recommended'), array(), array('class' => 'separate-top')); ?>
        <?php osc_package_list_open('theme-deps-list'); ?>
        <?php foreach ($deps['recommended'] as $row) {
            $renderRow($row);
        } ?>
        <?php osc_package_list_close(); ?>
    <?php endif; ?>

    <p class="theme-deps-status" role="status" aria-live="polite" data-dep-status-line></p>

    <?php
    $actions = array();
    if ($actionable > 0 && !$blocked) {
        $actions[] = array(
            'label'   => __('Install and activate all'),
            'variant' => 'primary',
            'type'    => 'button',
            'attrs'   => array('data-dep-run' => '1'),
        );
    }
    if ($requiredOpen === 0 && !$isActiveTheme) {
        $actions[] = array(
            'label'   => $actionable > 0 ? __('Skip and activate the theme') : __('Activate the theme'),
            'variant' => $actions === array() ? 'primary' : 'secondary',
            'url'     => $activateUrl,
        );
    }
    $actions[] = array('label' => __('Back to Appearance'), 'variant' => 'secondary', 'url' => $backUrl);
    osc_admin_form_actions($actions);
    ?>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
