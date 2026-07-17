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

/**
 * @param null $catId
 */
function osc_meta_publish($catId = null)
{
    osc_enqueue_script('php-date');
    echo '<div class="row">';
    FieldForm::meta_fields_input($catId);
    echo '</div>';
}


/**
 * @param null $catId
 * @param null $item_id
 */
function osc_meta_edit($catId = null, $item_id = null)
{
    osc_enqueue_script('php-date');
    echo '<div class="row">';
    FieldForm::meta_fields_input($catId, $item_id);
    echo '</div>';
}


osc_add_hook('item_form', 'osc_meta_publish');
osc_add_hook('item_edit', 'osc_meta_edit');

/**
 *
 * All CF will be searchable
 *
 * @param null $catId
 */
function osc_meta_search($catId = null)
{
    FieldForm::meta_fields_search($catId);
}


osc_add_hook('search_form', 'osc_meta_search');

/**
 * @return string
 */
function search_title()
{
    $region   = osc_search_region();
    $city     = osc_search_city();
    $category = osc_search_category_id();
    $result   = '';

    $b_show_all = ($region == '' && $city == '' && $category == '');
    $b_category = ($category != '');
    $b_city     = ($city != '');
    $b_region   = ($region != '');

    if ($b_show_all) {
        return __('Search results');
    }

    if (osc_get_preference('seo_title_keyword') != '') {
        $result .= osc_get_preference('seo_title_keyword') . ' ';
    }

    if ($b_category && !empty($category)) {
        $cat = Category::newInstance()->findByPrimaryKey($category[0]);
        if (isset($cat['s_name'])) {
            $result .= $cat['s_name'] . ' ';
        }
    }

    if ($b_city) {
        $result .= $city;
    } elseif ($b_region) {
        $result .= $region;
    }

    return $result;
}


/**
 * @return bool|mixed
 */
function meta_title()
{
    $location = Rewrite::newInstance()->get_location();
    $section  = Rewrite::newInstance()->get_section();
    $text     = '';

    switch ($location) {
        case ('item'):
            switch ($section) {
                case 'item_add':
                    $text = __('Publish a listing');
                    break;
                case 'item_edit':
                    $text = __('Edit your listing');
                    break;
                case 'send_friend':
                    $text = __('Send to a friend') . ' - ' . osc_item_title();
                    break;
                case 'contact':
                    $text = __('Contact seller') . ' - ' . osc_item_title();
                    break;
                default:
                    $text = osc_item_title() . ' ' . osc_item_city();
                    break;
            }
            break;
        case ('page'):
            $text = osc_static_page_title();
            break;
        case ('error'):
            $text = __('Error');
            break;
        case ('search'):
            $region   = osc_search_region();
            $city     = osc_search_city();
            $pattern  = osc_search_pattern();
            $category = osc_search_category_id();
            $s_page   = '';
            $i_page   = Params::getParam('iPage');

            if ($i_page && $i_page > 1) {
                $s_page = __('Page') . ' ' . $i_page . ': ';
            }

            $b_show_all = (!$region && !$city && !$pattern && empty($category));
            $b_category = !empty($category);
            $b_pattern  = ($pattern);
            $b_city     = ($city);
            $b_region   = ($region);

            $result = '';
            if ($b_show_all) {
                $result = __('Show all listings');
            }

            if ($b_pattern) {
                $result .= $pattern . ' &raquo; ';
            }

            if ($b_category && is_array($category) && count($category) > 0) {
                $cat = Category::newInstance()->findByPrimaryKey($category[0]);
                if ($cat) {
                    $result .= $cat['s_name'] . ' ';
                }
            }

            if ($b_city) {
                $result .= $city . ' &raquo; ';
            } elseif ($b_region) {
                $result .= $region . ' &raquo; ';
            }

            $result = preg_replace('|\s?&raquo;\s$|', '', $result);

            if (!$result) {
                $result = __('Search results');
            }

            $text = '';
            if (osc_get_preference('seo_title_keyword')) {
                $text .= osc_get_preference('seo_title_keyword') . ' ';
            }
            $text .= $s_page . $result;
            break;
        case ('login'):
            switch ($section) {
                case ('recover'):
                    $text = __('Recover your password');
                    break;
                case ('forgot'):
                    $text = __('Recover my password');
                    break;
                default:
                    $text = __('Login');
            }
            break;
        case ('register'):
            $text = __('Create a new account');
            break;

        case ('user'):
            switch ($section) {
                case ('dashboard'):
                    $text = __('Dashboard');
                    break;
                case ('items'):
                    $text = __('Manage my listings');
                    break;
                case ('alerts'):
                    $text = __('Manage my alerts');
                    break;
                case ('profile'):
                    $text = __('Update my profile');
                    break;
                case ('pub_profile'):
                    $text = __('Public profile') . ' - ' . osc_user_name();
                    break;
                case ('change_email'):
                    $text = __('Change my email');
                    break;
                case ('change_username'):
                    $text = __('Change my username');
                    break;
                case ('change_password'):
                    $text = __('Change my password');
                    break;
            }
            break;
        case ('contact'):
            $text = __('Contact');
            break;
        case ('custom'):
            $text = Rewrite::newInstance()->get_title();
            break;
        default:
            $text = osc_page_title();
            break;
    }

    if (!osc_is_home_page()) {
        if ($text != '') {
            $text .= ' - ' . osc_page_title();
        } else {
            $text = osc_page_title();
        }
    }

    return osc_apply_filter('meta_title_filter', $text);
}


/**
 * @return bool|mixed
 */
function meta_description()
{
    $text = '';
    // home page
    if (osc_is_home_page()) {
        $text = osc_page_description();
    }
    // static page
    if (osc_is_static_page()) {
        $text = osc_highlight(osc_static_page_text(), 140, '', '');
    }
    // search
    if (osc_is_search_page()) {
        // search category
        if (osc_is_search_category_page() && osc_search_category_description()) {
            $text = osc_search_category_description();
        } elseif (osc_has_items()) {
            $text = osc_item_category() . ' ' . osc_item_city() . ', ' . osc_highlight(osc_item_description(), 120);
            osc_reset_items();
        }
    }
    // listing
    if (osc_is_ad_page()) {
        $text = osc_item_category() . ' ' . osc_item_city() . ', ' . osc_highlight(osc_item_description(), 120);
    }

    return osc_apply_filter('meta_description_filter', $text);
}


/**
 * @return bool|mixed
 */
function meta_keywords()
{
    $text = '';
    // search
    if (osc_is_search_page()) {
        if (osc_has_items()) {
            $keywords   = array();
            $keywords[] = osc_item_category();
            if (osc_item_city() != '') {
                $keywords[] = osc_item_city();
                $keywords[] = sprintf('%s %s', osc_item_category(), osc_item_city());
            }
            if (osc_item_region() != '') {
                $keywords[] = osc_item_region();
                $keywords[] = sprintf('%s %s', osc_item_category(), osc_item_region());
            }
            if ((osc_item_city() != '') && (osc_item_region() != '')) {
                $keywords[] = sprintf('%s %s %s', osc_item_category(), osc_item_region(), osc_item_city());
                $keywords[] = sprintf('%s %s', osc_item_region(), osc_item_city());
            }
            $text = implode(', ', $keywords);
        }
        osc_reset_items();
    }
    // listing
    if (osc_is_ad_page()) {
        $keywords   = array();
        $keywords[] = osc_item_category();
        if (osc_item_city() != '') {
            $keywords[] = osc_item_city();
            $keywords[] = sprintf('%s %s', osc_item_category(), osc_item_city());
        }
        if (osc_item_region() != '') {
            $keywords[] = osc_item_region();
            $keywords[] = sprintf('%s %s', osc_item_category(), osc_item_region());
        }
        if ((osc_item_city() != '') && (osc_item_region() != '')) {
            $keywords[] = sprintf('%s %s %s', osc_item_category(), osc_item_region(), osc_item_city());
            $keywords[] = sprintf('%s %s', osc_item_region(), osc_item_city());
        }
        $text = implode(', ', $keywords);
    }

    return osc_apply_filter('meta_keywords_filter', $text);
}


/**
 * @return array
 */
function osc_search_footer_links()
{
    if (!osc_rewrite_enabled()) {
        return array();
    }

    $categoryID = osc_search_category_id();
    if (!empty($categoryID) && Category::newInstance()->isRoot(current($categoryID))) {
        $cat = Category::newInstance()->findSubcategories(current($categoryID));
        if (count($cat) > 0) {
            $categoryID = array();
            foreach ($cat as $c) {
                $categoryID[] = $c['pk_i_id'];
            }
        }
    }

    if (osc_search_city() != '') {
        return array();
    }

    $regionID = '';
    if (osc_search_region() != '') {
        $aRegion = Region::newInstance()->findByName(osc_search_region());
        if (isset($aRegion['pk_i_id'])) {
            $regionID = $aRegion['pk_i_id'];
        }
    }

    $conn = DBConnectionClass::newInstance();
    $data = $conn->getOsclassDb();
    $comm = new DBCommandClass($data);

    $comm->select('i.fk_i_category_id');
    $comm->select('l.*');
    $comm->select('COUNT(*) AS total');
    $comm->from(DB_TABLE_PREFIX . 't_item as i');
    $comm->from(DB_TABLE_PREFIX . 't_item_location as l');
    if (!empty($categoryID)) {
        $comm->whereIn('i.fk_i_category_id', $categoryID);
    }
    $comm->where('i.pk_i_id = l.fk_i_item_id');
    $comm->where('i.b_enabled = 1');
    $comm->where('i.b_active = 1');
    $comm->where(sprintf("dt_expiration >= '%s'", date('Y-m-d H:i:s')));

    $comm->where('l.fk_i_region_id IS NOT NULL');
    $comm->where('l.fk_i_city_id IS NOT NULL');
    if ($regionID != '') {
        $comm->where('l.fk_i_region_id', $regionID);
        $comm->groupBy('l.fk_i_city_id');
    } else {
        $comm->groupBy('l.fk_i_region_id');
    }
    $rs = $comm->get();

    if (!$rs) {
        return array();
    }

    return $rs->result();
}


/**
 * @param null $f
 *
 * @return string
 */
function osc_footer_link_url($f = null)
{
    if ($f === null) {
        if (View::newInstance()->_exists('footer_link')) {
            $f = View::newInstance()->_get('footer_link');
        } else {
            return '';
        }
    } else {
        View::newInstance()->_exportVariableToView('footer_link', $f);
    }
    $params = array();
    $tmp    = osc_search_category_id();
    if (isset($tmp)) {
        $params['sCategory'] = $f['fk_i_category_id'];
    }

    if (osc_search_region() == '') {
        $params['sRegion'] = $f['fk_i_region_id'];
    } else {
        $params['sCity'] = $f['fk_i_city_id'];
    }

    return osc_search_url($params);
}


/**
 * @param null $f
 *
 * @return string
 */
function osc_footer_link_title($f = null)
{
    if ($f == null) {
        if (View::newInstance()->_exists('footer_link')) {
            $f = View::newInstance()->_get('footer_link');
        } else {
            return '';
        }
    } else {
        View::newInstance()->_exportVariableToView('footer_link', $f);
    }
    $text = '';

    if (osc_get_preference('seo_title_keyword') != '') {
        $text .= osc_get_preference('seo_title_keyword') . ' ';
    }

    $cat = osc_get_category('id', $f['fk_i_category_id']);
    if (@$cat['s_name'] != '') {
        $text .= $cat['s_name'] . ' ';
    }

    if (osc_search_region() == '') {
        $text .= $f['s_region'];
    } else {
        $text .= $f['s_city'];
    }

    $text = trim($text);

    return $text;
}


/**
 * Instantiate the admin toolbar object.
 *
 * @return bool
 * @since  3.0
 * @access private
 */
function _osc_admin_toolbar_init()
{
    $adminToolbar = AdminToolbar::newInstance();

    $adminToolbar->init();
    $adminToolbar->add_menus();

    return true;
}


// and we hook our function via
osc_add_hook('init_admin', '_osc_admin_toolbar_init');

/**
 * Draws admin toolbar
 */
function osc_draw_admin_toolbar()
{
    $adminToolbar = AdminToolbar::newInstance();

    // run hook for adding
    osc_run_hook('add_admin_toolbar_menus');
    $adminToolbar->render();
}


/**
 * Add logout link
 */
function osc_admin_toolbar_logout()
{
    AdminToolbar::newInstance()->add_menu(array(
                                              'id'    => 'logout',
                                              'title' => __('Logout'),
                                              'href'  => osc_admin_base_url(true) . '?action=logout',
                                              'meta'  => array('class' => 'bi bi-box-arrow-right')
                                          ));
}


function osc_admin_toolbar_comments()
{
    $total = ItemComment::newInstance()->countAll('( c.b_active = 0 OR c.b_enabled = 0 OR c.b_spam = 1 )');
    if ($total > 0) {
        $title = '<i class="circle circle-green">' . $total . '</i>' . __('New comments');

        AdminToolbar::newInstance()->add_menu(
            array(
                'id'    => 'comments',
                'title' => $title,
                'href'  => osc_admin_base_url(true) . '?page=comments',
                'meta'  => array('class' => 'action-btn ')
            )
        );
    }
}


function osc_admin_toolbar_spam()
{
    $total = Item::newInstance()->countByMarkas('spam');
    if ($total > 0) {
        $title = '<i class="circle circle-red">' . $total . '</i>' . __('Spam');

        AdminToolbar::newInstance()->add_menu(
            array(
                'id'    => 'spam',
                'title' => $title,
                'href'  => osc_admin_base_url(true) . '?page=items&action=items_reported&sort=spam',
                'meta'  => array('class' => 'action-btn ')
            )
        );
    }
}


/**
 * @param bool $force
 */
function osc_admin_toolbar_update_core($force = false)
{
    if (!osc_is_moderator()) {
        if ($force) {
            AdminToolbar::newInstance()->remove_menu('update_core');
        }
        if (getPreference('update_core_available')) {
            $update_json = json_decode(Preference::newInstance()->get('update_core_json'), false);
            $title       = __('Osclass ') . $update_json->s_new_version . __(' is available');
            AdminToolbar::newInstance()->add_menu(
                array(
                    'id'    => 'update_core',
                    'title' => $title,
                    'href'  => osc_admin_base_url(true) . '?page=tools&action=upgrade',
                    'meta'  => array('class' => 'action-btn ')
                )
            );
        }
    }
}


/**
 * @param bool $force
 *
 * @return int|string
 */
function osc_check_plugins_update($force = false)
{
    $total = getPreference('plugins_update_count');
    if ($force) {
        return _osc_check_plugins_update();
    }

    if ((time() - (int)osc_plugins_last_version_check()) > (24 * 3600)) {
        osc_add_hook('admin_footer', 'check_plugins_admin_footer');
    }

    return $total;
}


/**
 * @return int
 */
function _osc_check_plugins_update()
{
    $total            = 0;
    $array            = array();
    $array_downloaded = array();
    $plugins          = Plugins::listAll();
    foreach ($plugins as $plugin) {
        $info = osc_plugin_get_info($plugin);
        if (osc_check_plugin_update(@$info['plugin_update_uri'], @$info['version'])) {
            $array[] = @$info['plugin_update_uri'];
            $total++;
        }
        $array_downloaded[] = @$info['plugin_update_uri'];
    }

    osc_set_preference('plugins_to_update', json_encode($array));
    osc_set_preference('plugins_downloaded', json_encode($array_downloaded));
    osc_set_preference('plugins_update_count', $total);
    osc_set_preference('plugins_last_version_check', time());
    osc_reset_preferences();

    return $total;
}


/**
 * @param bool $force
 */
function osc_admin_toolbar_update_plugins($force = false)
{
    if (!osc_is_moderator()) {
        $total = osc_check_plugins_update($force);

        if ($force) {
            AdminToolbar::newInstance()->remove_menu('update_plugin');
        }
        if ($total > 0) {
            $title = '<i class="circle circle-gray">' . $total . '</i>' . __('Plugin updates');
            AdminToolbar::newInstance()->add_menu(
                array(
                    'id'    => 'update_plugin',
                    'title' => $title,
                    'href'  => osc_admin_base_url(true) . '?page=plugins#update-plugins',
                    'meta'  => array('class' => 'action-btn ')
                )
            );
        }
    }
}


/**
 * @param bool $force
 *
 * @return int|string
 */
function osc_check_themes_update($force = false)
{
    $total = getPreference('themes_update_count');
    if ($force) {
        return _osc_check_themes_update();
    } elseif ((time() - (int)osc_themes_last_version_check()) > (24 * 3600)) {
        osc_add_hook('admin_footer', 'check_themes_admin_footer');
    }

    return $total;
}


/**
 * @return int
 */
function _osc_check_themes_update()
{
    $total            = 0;
    $array            = array();
    $array_downloaded = array();
    $themes           = WebThemes::newInstance()->getListThemes();
    foreach ($themes as $theme) {
        $info = WebThemes::newInstance()->loadThemeInfo($theme);
        if (osc_check_theme_update(@$info['theme_update_uri'], @$info['version'])) {
            $array[] = $theme;
            $total++;
        }
        $array_downloaded[] = @$info['theme_update_uri'];
    }
    osc_set_preference('themes_to_update', json_encode($array));
    osc_set_preference('themes_downloaded', json_encode($array_downloaded));
    osc_set_preference('themes_update_count', $total);
    osc_set_preference('themes_last_version_check', time());
    osc_reset_preferences();

    return $total;
}


/**
 * @param bool $force
 */
function osc_admin_toolbar_update_themes($force = false)
{
    if (!osc_is_moderator()) {
        $total = osc_check_themes_update($force);

        if ($force) {
            AdminToolbar::newInstance()->remove_menu('update_theme');
        }
        if ($total > 0) {
            $title = '<i class="circle circle-gray">' . $total . '</i>' . __('Theme updates');
            AdminToolbar::newInstance()->add_menu(
                array(
                    'id'    => 'update_theme',
                    'title' => $title,
                    'href'  => osc_admin_base_url(true) . '?page=appearance',
                    'meta'  => array('class' => 'action-btn ')
                )
            );
        }
    }
}


// languages todo
/**
 * @param bool $force
 *
 * @return int|string
 */
function osc_check_languages_update($force = false)
{
    $total = getPreference('languages_update_count');
    if ($force) {
        return _osc_check_languages_update();
    }

    if ((time() - (int)osc_languages_last_version_check()) > (24 * 3600)) {
        osc_add_hook('admin_footer', 'check_languages_admin_footer');
    }

    return $total;
}


/**
 * @return int
 */
function _osc_check_languages_update()
{
    $total            = 0;
    $array            = array();
    $array_downloaded = array();
    $languages        = OSCLocale::newInstance()->listAll();
    foreach ($languages as $lang) {
        if (osc_check_language_update($lang['pk_c_code'], $lang['s_version'])) {
            $array[] = $lang['pk_c_code'];
            $total++;
        }
        $array_downloaded[] = $lang['pk_c_code'];
    }
    osc_set_preference('languages_to_update', json_encode($array));
    osc_set_preference('languages_downloaded', json_encode($array_downloaded));
    osc_set_preference('languages_update_count', $total);
    osc_set_preference('languages_last_version_check', time());
    osc_reset_preferences();

    return $total;
}


/**
 * @param bool $force
 */
function osc_admin_toolbar_update_languages($force = false)
{
    if (!osc_is_moderator()) {
        $total = osc_check_languages_update($force);

        if ($force) {
            AdminToolbar::newInstance()->remove_menu('update_language');
        }
        if ($total > 0) {
            $title = '<i class="circle circle-gray">' . $total . '</i>' . __('Language updates');
            AdminToolbar::newInstance()->add_menu(
                array(
                    'id'    => 'update_language',
                    'title' => $title,
                    'href'  => osc_admin_base_url(true) . '?page=languages',
                    'meta'  => array('class' => 'action-btn ')
                )
            );
        }
    }
}


function osc_ga_analytics_footer()
{
    $id = osc_google_analytics_id();
    if ($id) {
        ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo osc_esc_html($id); ?>"></script>
        <script>
            window.dataLayer = window.dataLayer || [];

            function gtag() {
                dataLayer.push(arguments);
            }

            gtag('js', new Date());
            gtag('config', '<?php echo osc_esc_js($id); ?>');
        </script>
        <?php
    }
}


if (osc_google_analytics_id()) {
    osc_add_hook('footer', 'osc_ga_analytics_footer');
}

function osc_item_tinymce_header()
{
    if (!osc_is_publish_page() && !osc_is_edit_page()) {
        return;
    }
    osc_enqueue_script('tiny_mce');
}


/**
 * Load the shared vanilla UI helpers on the public item form (publish/edit): the
 * oscAutocomplete combobox for the location fields, and the osc-uploader for photos.
 * Both replace jQuery widgets, so jQuery stays merely registered here, never enqueued.
 */
function osc_ui_common_header()
{
    if (!osc_is_publish_page() && !osc_is_edit_page()) {
        return;
    }
    osc_enqueue_script('osc-ui-common');
    osc_enqueue_style('osc-ui-common');
    osc_enqueue_script('osc-uploader');
    osc_enqueue_style('osc-uploader');
}
osc_add_hook('header', 'osc_ui_common_header');


function osc_item_tinymce_footer()
{
    if (!osc_is_publish_page() && !osc_is_edit_page()) {
        return;
    }
    ?>
    <script>
        tinyMCE.init({
            mode: 'none',
            theme_advanced_toolbar_align: 'left',
            theme_advanced_toolbar_location: 'top',
            theme_advanced_buttons1_add: 'forecolorpicker,fontsizeselect',
            theme_advanced_buttons2_add: 'media',
            plugins: 'advlist autolink lists link charmap preview anchor searchreplace visualblocks code fullscreen table paste'
        });
        $(function () {
            $('textarea[id^=description]').each(function () {
                tinyMCE.execCommand('mceAddEditor', true, this.id);
            });
        });
    </script>
    <?php
}


if (osc_tinymce_frontend()) {
    osc_add_hook('header', 'osc_item_tinymce_header');
    osc_add_hook('footer', 'osc_item_tinymce_footer');
}

function osc_show_maintenance()
{
    if (defined('__OSC_MAINTENANCE__')) { ?>
        <div id="maintenance" name="maintenance">
            <?php _e('The website is currently undergoing maintenance'); ?>
        </div>
        <style>
            #maintenance {
                position: static;
                top: 0px;
                right: 0px;
                background-color: #ff5252;
                width: 100%;
                text-align: center;
                padding: 10px 0;
                font-size: 14px;
                color: #fefefe;
            }
        </style>
    <?php }
}


osc_add_hook('header', 'osc_show_maintenance');

function osc_meta_generator()
{
    echo '<meta name="generator" content="Osclass ' . OSCLASS_VERSION . '" />';
}


osc_add_hook('header', 'osc_meta_generator');

if (osc_force_jpeg()) {
    /**
     * @param $content
     *
     * @return string
     */
    function osc_force_jpeg_extension($content)
    {
        return 'jpg';
    }


    /**
     * @param $content
     *
     * @return string
     */
    function osc_force_jpeg_mime($content)
    {
        return 'image/jpeg';
    }


    osc_add_filter('upload_image_extension', 'osc_force_jpeg_extension');
    osc_add_filter('upload_image_mime', 'osc_force_jpeg_mime');
}
