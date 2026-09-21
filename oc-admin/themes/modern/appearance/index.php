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
osc_current_admin_theme_path('parts/package-ui.php');

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
        <?php
        $csrf_token      = osc_csrf_token_url();
        $aThemesToUpdate = json_decode(osc_get_preference('themes_to_update'), true);
        $bThemesToUpdate = is_array($aThemesToUpdate);
        $themeMeta       = static function ($info) {
            $meta = array(sprintf(__('Version %s'), osc_esc_html($info['version'])));
            $meta[] = !empty($info['author_url'])
                ? sprintf(__('by %s'), '<a target="_blank" rel="noopener" href="'
                    . osc_esc_html(osc_sanitize_url($info['author_url'])) . '">'
                    . osc_esc_html($info['author_name']) . '</a>')
                : sprintf(__('by %s'), osc_esc_html($info['author_name']));

            return $meta;
        };

        // The parent a theme declares, when it names one that is actually installed.
        $parentOf = static function ($slug) {
            $i = WebThemes::newInstance()->loadThemeInfo($slug);
            if (!is_array($i) || empty($i['template'])
                || !preg_match('/^[a-zA-Z0-9._-]+$/', (string) $i['template'])
                || $i['template'] === $slug
            ) {
                return null;
            }

            return (string) $i['template'];
        };

        /** A theme's display name, falling back to its directory name. */
        $nameOf = static function ($slug) {
            $i = WebThemes::newInstance()->loadThemeInfo($slug);

            return is_array($i) && $i['name'] !== '' ? ucfirst($i['name']) : $slug;
        };

        $activeParent  = $parentOf(osc_theme());
        $parentMissing = $activeParent !== null && !in_array($activeParent, $themes, true);
        ?>
        <?php osc_admin_page_head(__('Current theme')); ?>
        <?php osc_package_list_open('osc-pkg-list--themes'); ?>
        <?php osc_package_row(array(
            'art'         => array('src' => osc_theme_screenshot_url(), 'has' => osc_theme_has_screenshot()),
            'slug'        => osc_theme(),
            'name'        => ucfirst($info['name']),
            'state'       => 'live',
            'size'        => 'wide',
            'class'       => 'current-theme',
            'meta'        => array_merge(
                $themeMeta($info),
                $activeParent !== null && !$parentMissing
                    ? array(sprintf(__('extends %s'), osc_esc_html($nameOf($activeParent))))
                    : array()
            ),
            'description' => $info['description'],
            'note'        => $parentMissing
                ? osc_esc_html(sprintf(
                    __('This theme extends "%s", which is not installed. '
                       . 'Anything it does not carry itself is coming from the default theme.'),
                    $activeParent
                ))
                : '',
            'note_variant' => 'warning',
            'actions'     => array(
                'links' => array(
                    '<a target="_blank" rel="noopener" href="' . osc_esc_html(osc_base_url(true)) . '">'
                        . osc_esc_html(__('View site')) . '</a>',
                ),
            ),
        )); ?>
        <?php osc_package_list_close(); ?>

        <?php osc_admin_page_head(__('Other themes'), array(), array('class' => 'separate-top')); ?>
        <?php
        $otherThemes = array_filter($themes, static function ($theme) {
            return $theme !== osc_theme();
        });
        ?>
        <?php if ($otherThemes) : ?>
            <?php osc_package_list_open('osc-pkg-list--themes'); ?>
            <?php foreach ($otherThemes as $theme) :
                $tInfo  = WebThemes::newInstance()->loadThemeInfo($theme);
                $tName  = ucfirst($tInfo['name']);
                $update = $bThemesToUpdate && in_array($theme, $aThemesToUpdate, true);
                // Deleting this one would leave the active theme rendering on the default.
                $isParent  = $activeParent === $theme;
                $ownParent = $parentOf($theme);
                osc_package_row(array(
                    'art'         => array(
                        'src' => osc_theme_screenshot_url($theme),
                        'has' => osc_theme_has_screenshot($theme),
                    ),
                    'slug'        => $theme,
                    'name'        => $tName,
                    'state'       => 'disabled',
                    'state_word'  => __('Installed'),
                    'meta'        => array_merge(
                        $themeMeta($tInfo),
                        $ownParent !== null
                            ? array(sprintf(__('extends %s'), osc_esc_html($nameOf($ownParent))))
                            : array()
                    ),
                    'description' => $tInfo['description'],
                    'note'        => $isParent
                        ? osc_esc_html(__('The active theme extends this one. Deleting it would leave '
                                          . 'your site rendering on the default theme.'))
                        : ($update
                            ? osc_esc_html(__('An update is ready for this theme. Open the Updates tab to apply it.'))
                            : ''),
                    'note_variant' => $isParent ? 'warning' : 'update',
                    'actions'     => array(
                        'primary' => array(
                            'label' => __('Activate'),
                            'url'   => osc_admin_base_url(true) . '?page=appearance&amp;action=activate&amp;theme='
                                . urlencode($theme) . '&amp;' . $csrf_token,
                        ),
                        'links'   => array(
                            '<a target="_blank" rel="noopener" href="' . osc_esc_html(osc_base_url(true))
                                . '?theme=' . urlencode($theme) . '">' . osc_esc_html(__('Preview')) . '</a>',
                        ),
                        'danger'  => array(
                            '<a href="#" onclick="return delete_dialog(\'' . osc_esc_js($theme) . '\');">'
                                . osc_esc_html(__('Delete')) . '</a>',
                        ),
                    ),
                ));
            endforeach; ?>
            <?php osc_package_list_close(); ?>
        <?php else : ?>
            <?php osc_admin_empty(array(
                'icon'  => 'bi-palette',
                'title' => __('No other themes installed'),
                'text'  => __('Find one in Browse, or upload a theme package.'),
                'action' => array(
                    'label'   => __('Add theme'),
                    'url'     => osc_admin_base_url(true) . '?page=appearance&amp;action=add',
                    'variant' => 'primary',
                ),
            )); ?>
        <?php endif; ?>
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
    'method'     => 'post',
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
