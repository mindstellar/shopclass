<?php

if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}

function admin_js_lang_string()
{
    ?>
    <script type="text/javascript">
        var osc = window.osc || {};
        <?php
        $lang = array(
            'nochange_expiration' => __('No change expiration'),
            'without_expiration'  => __('Without expiration'),
            'expiration_day'      => __('1 day'),
            'expiration_days'     => __('%d days'),
            'select_category'     => __('Select category'),
            'no_subcategory'      => __('No subcategory'),
            'select_subcategory'  => __('Select subcategory')
        );
        $locales = osc_get_locales();
        $codes = array();
        foreach ($locales as $locale) {
            $codes[] = osc_esc_js($locale['pk_c_code']);
        }
        ?>
        osc.locales = {};
        osc.locales._default = '<?php echo osc_language(); ?>';
        osc.locales.current = '<?php echo osc_current_admin_locale(); ?>';
        osc.locales.codes = <?php echo json_encode($codes); ?>;
        osc.locales.string = '[name*="' + osc.locales.codes.join('"],[name*="') + '"],.' + osc.locales.codes.join(',.');
        osc.langs = <?php echo json_encode($lang); ?>;
    </script>
    <?php
}


osc_add_hook('admin_header', 'admin_js_lang_string');

// favicons
function admin_header_favicons()
{
    $favicons   = array();
    $favicons[] = array(
        'rel'   => 'icon',
        'type'  => 'image/svg+xml',
        'href'  => osc_current_admin_theme_url('images/shopclass-favicon.svg')
    );
    $favicons[] = array(
        'rel'   => 'icon',
        'type'  => 'image/png',
        'sizes' => '32x32',
        'href'  => osc_current_admin_theme_url('images/favicon-32.png')
    );
    $favicons[] = array(
        'rel'   => 'icon',
        'type'  => 'image/png',
        'sizes' => '16x16',
        'href'  => osc_current_admin_theme_url('images/favicon-16.png')
    );
    $favicons[] = array(
        'rel'   => 'apple-touch-icon',
        'sizes' => '180x180',
        'href'  => osc_current_admin_theme_url('images/favicon-180.png')
    );
    $favicons[] = array(
        'rel'   => 'manifest',
        'href'  => osc_current_admin_theme_url('images/site.webmanifest')
    );

    $favicons = osc_apply_filter('admin_favicons', $favicons);

    foreach ($favicons as $f) { ?>
        <link<?php
            foreach (array('rel', 'type', 'sizes') as $attr) {
                if (!empty($f[$attr])) {
                    echo ' ' . $attr . '="' . osc_esc_html($f[$attr]) . '"';
                }
            }
        ?> href="<?php echo osc_esc_html($f['href']); ?>">
    <?php }
}


osc_add_hook('admin_header', 'admin_header_favicons');

// admin footer
function admin_footer_html()
{
    ?>
    <div class="admin-footer-credit">
        <?php printf(
            __('Thank you for using <a href="%s" target="_blank">Shopclass</a>'),
            'https://github.com/mindstellar/shopclass/'
        ); ?> -
        <a title="<?php _e('Forums'); ?>" href="https://osclass.discourse.group"
           target="_blank"><?php _e('Forums'); ?></a> &middot;
        <a title="<?php _e('Report Issue'); ?>" href="https://github.com/mindstellar/shopclass/issues/"
           target="_blank"><?php _e('Report Issue'); ?></a>
    </div>
    <div class="admin-footer-version">
        <strong>Shopclass <?php echo OSCLASS_VERSION; ?></strong>
    </div><?php
}


osc_add_hook('admin_content_footer', 'admin_footer_html');

/**
 * @param $slug
 * @param $language_version
 *
 * @return bool
 */
function check_market_language_compatibility($slug, $language_version)
{
    return osc_check_language_update($slug);
}


/**
 * @param $versions
 *
 * @return bool
 */
function check_market_compatibility($versions)
{
    $versions        = explode(',', $versions);
    $current_version = OSCLASS_VERSION;

    foreach ($versions as $_version) {
        $result = version_compare2(OSCLASS_VERSION, $_version);

        if ($result == 0 || $result == -1) {
            return true;
        }
    }

    return false;
}


function check_version_admin_footer()
{
    if ((time() - osc_last_version_check()) > (24 * 3600)) {
        ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=check_version', {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
            });
        </script>
        <?php
    }
}


osc_add_hook('admin_footer', 'check_version_admin_footer');

function check_languages_admin_footer()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=check_languages', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        });
    </script>
    <?php
}


function check_themes_admin_footer()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=check_themes', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        });
    </script>
    <?php
}


function check_plugins_admin_footer()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=check_plugins', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        });
    </script>
    <?php
}

/* end of file */
