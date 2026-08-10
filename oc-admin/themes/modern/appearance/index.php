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

//getting variables for this view
$themes = __get('themes');
$info   = WebThemes::newInstance()->loadThemeInfo(osc_theme());

osc_admin_page(array(
    'section' => __('Appearance'),
    'title'   => __('Appearance'),
    'help'    => __("Change your site's look and feel by activating a theme among those available. "
                    . '<strong>Be careful</strong>: if your theme has been customized, '
                    . "you'll lose all changes if you change to a new theme."),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=appearance&amp;action=add',
            'title' => __('Add theme'),
        ),
    ),
));

/**
 * Hue (0-359) derived from a hash of the slug, so a grid of unillustrated
 * themes still reads as visually distinct tiles.
 *
 * @param string $slug
 *
 * @return int
 */
function appearanceThumbHue($slug)
{
    return crc32($slug) % 360;
}

osc_current_admin_theme_path('parts/market.php');

$aMarketBrowse  = __get('aMarketBrowse');
$aMarketUpdates = __get('aMarketUpdates');
$aMarketMeta    = __get('aMarketMeta');
if (!is_array($aMarketBrowse)) {
    $aMarketBrowse = array();
}
if (!is_array($aMarketUpdates)) {
    $aMarketUpdates = array();
}
if (!is_array($aMarketMeta)) {
    $aMarketMeta = array(
        'last_checked' => 0, 'error' => null, 'writable' => true,
        'disabled' => false, 'categories' => array(), 'catalog_available' => false,
    );
}

osc_register_script('admin-market', osc_asset_url_versioned(osc_current_admin_theme_js_url('market.js')), array('admin-osc', 'admin-ui-osc'));
osc_enqueue_script('admin-market');

$marketCsrf       = osc_csrf_token_url();
$marketInstallUrl = osc_admin_base_url(true) . '?page=ajax&action=market_install&type=theme&' . $marketCsrf;
$marketUpdateUrl  = osc_admin_base_url(true) . '?page=ajax&action=market_update&type=theme&' . $marketCsrf;
$marketRefreshUrl = osc_admin_base_url(true) . '?page=ajax&action=market_refresh&type=theme&' . $marketCsrf;

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="appearance-page">
    <div class="market-app" data-type="theme"
         data-install-url="<?php echo osc_esc_html($marketInstallUrl); ?>"
         data-update-url="<?php echo osc_esc_html($marketUpdateUrl); ?>"
         data-refresh-url="<?php echo osc_esc_html($marketRefreshUrl); ?>"
         data-i18n='<?php echo osc_esc_html(json_encode(osc_market_i18n('theme'))); ?>'>
        <div class="osc-tab">
            <ul>
                <li><a href="#market-tab-installed"><?php _e('Themes'); ?></a></li>
                <li><a href="#market-tab-browse"><?php _e('Browse'); ?></a></li>
                <li><a href="#market-tab-updates"><?php _e('Updates'); ?>
                        <span class="market-tab-count" id="market-updates-count">(<?php echo (int) count($aMarketUpdates); ?>)</span>
                    </a></li>
            </ul>
        </div>

        <div id="market-tab-installed">
    <!-- themes list -->
    <div class="appearance">
        <div id="tabs">
            <div id="available-themes">
                <?php osc_admin_page_head(__('Current theme')); ?>
                <div class="current-theme">
                    <div class="card mb-3 col-sm-12 col-md-8 col-lg-6">
                        <div class="row no-gutters">
                            <div class="col">
                                <?php $currentHasScreenshot = osc_theme_has_screenshot(); ?>
                                <div class="osc-thumb<?php echo $currentHasScreenshot ? '' : ' osc-thumb--fallback'; ?>"
                                     <?php if (!$currentHasScreenshot) : ?>style="--osc-thumb-hue: <?php echo appearanceThumbHue(osc_theme()); ?>"<?php endif; ?>>
                                    <img src="<?php echo osc_esc_html(osc_theme_screenshot_url()); ?>"
                                         class="card-img" alt="<?php echo osc_esc_html(sprintf(__('Screenshot of the %s theme'), $info['name'])); ?>"
                                         width="400" height="300" loading="lazy">
                                    <?php if (!$currentHasScreenshot) : ?>
                                        <span class="osc-thumb-letter" aria-hidden="true"><?php echo osc_esc_html(mb_strtoupper(mb_substr($info['name'], 0, 1))); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo $info['name']; ?></h5>
                                    <p><?php _e('Description') ?> : <?php echo $info['description']; ?></p>
                                    <p><?php _e('Version') ?> : <?php echo $info['version']; ?></p>
                                    <p><?php _e('Author') ?> : <a href="<?php echo $info['author_url']; ?>"
                                                                  target="_blank"><?php echo $info['author_name']; ?></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php osc_admin_page_head(__('Available themes'), array(), array('class' => 'separate-top')); ?>
                <div class="available-theme row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-4">
                    <?php
                    $aThemesToUpdate = json_decode(osc_get_preference('themes_to_update'), true);
$bThemesToUpdate = is_array($aThemesToUpdate);
$csrf_token      = osc_csrf_token_url();
$hasOtherThemes  = false;
foreach ($themes as $theme) {
    if ($theme === osc_theme()) {
        continue;
    }
    $hasOtherThemes = true;
    $info = WebThemes::newInstance()->loadThemeInfo($theme);
    ?>
                        <div class="col">
                            <div class="card">
                                <?php $hasScreenshot = osc_theme_has_screenshot($theme); ?>
                                <div class="osc-thumb<?php echo $hasScreenshot ? '' : ' osc-thumb--fallback'; ?>"
                                     <?php if (!$hasScreenshot) : ?>style="--osc-thumb-hue: <?php echo appearanceThumbHue($theme); ?>"<?php endif; ?>>
                                    <img class="card-img-top"
                                         src="<?php echo osc_esc_html(osc_theme_screenshot_url($theme)); ?>"
                                         title="<?php echo osc_esc_html($info['name']); ?>"
                                         alt="<?php echo osc_esc_html(sprintf(__('Screenshot of the %s theme'), $info['name'])); ?>"
                                         width="400" height="300" loading="lazy"/>
                                    <?php if (!$hasScreenshot) : ?>
                                        <span class="osc-thumb-letter" aria-hidden="true"><?php echo osc_esc_html(mb_strtoupper(mb_substr($info['name'], 0, 1))); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <div class="theme-stage">
                                        <div class="">
                                            <a href="<?php echo osc_admin_base_url(true);
    ?>?page=appearance&amp;action=activate&amp;theme=<?php
    echo $theme; ?>&amp;<?php echo $csrf_token;
    ?>" class="btn btn-mini btn-primary"><?php _e('Activate'); ?></a>
                                            <a target="_blank"
                                               href="<?php echo osc_base_url(true); ?>?theme=<?php echo $theme; ?>"
                                               class="btn btn-mini btn-dim"><?php _e('Preview'); ?></a>
                                            <a onclick="return delete_dialog('<?php echo $theme; ?>');"
                                               href="<?php echo osc_admin_base_url(true);
    ?>?page=appearance&amp;action=delete&amp;webtheme=<?php
                                               echo $theme; ?>&amp;<?php echo $csrf_token; ?>"
                                               class="btn btn-sm btn-dim delete"><?php _e('Delete'); ?></a>
                                            <?php
                                            if ($bThemesToUpdate && in_array($theme, $aThemesToUpdate)) { ?>
                                                <a href='#<?php echo htmlentities(@$info['theme_update_uri']); ?>'
                                                   class="btn btn-mini btn-primary market-popup"><?php _e('Update'); ?></a>
                                            <?php } ?>
                                        </div>
                                        <h4>
                                            <?php echo ucfirst($info['name']); ?>
                                        </h4>
                                        <div class="theme-info">
                                            <div><?php echo __('Version') ?>: <?php echo $info['version']; ?></div>
                                            <div><?php echo __('Author') ?>: <a target="_blank"
                                                                                href="<?php echo $info['author_url']; ?>"><?php echo $info['author_name']; ?></a>
                                            </div>
                                            <div><?php echo __('Description') ?>: <?php echo $info['description']; ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                    <?php if (!$hasOtherThemes) { ?>
                        <div class="col-12">
                            <p class="text-muted mb-0"><?php _e('No other themes are installed. Upload a theme to change your site\'s look.'); ?></p>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
    <!-- /themes list -->
        </div>

        <div id="market-tab-browse" hidden>
            <?php osc_market_render_browse($aMarketBrowse, $aMarketMeta, 'theme'); ?>
        </div>

        <div id="market-tab-updates" hidden>
            <?php osc_market_render_updates($aMarketUpdates, $aMarketMeta, 'theme'); ?>
        </div>

        <?php osc_market_render_detail_dialog('theme'); ?>
    </div>
</div>
<?php osc_admin_confirm_dialog(array(
    'id'         => 'deleteModal',
    'method'     => 'get',
    'fields'     => array('page' => 'appearance', 'action' => 'delete', 'webtheme' => ''),
    'title'      => __('Delete theme'),
    'text'       => __("This permanently deletes the theme's files from the server, along with any customizations made to it."),
    'confirm'    => __('Uninstall'),
    'confirm_id' => 'deleteSubmit',
)); ?>
<script type="text/javascript">
    function delete_dialog(id) {
        var deleteModal = document.getElementById("deleteModal");
        var input = deleteModal.querySelector("input[name='webtheme']");
        if (input) { input.value = id; }
        deleteModal.showModal();
        return false;
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
