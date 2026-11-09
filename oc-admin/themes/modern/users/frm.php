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

$user      = __get('user');
$countries = __get('countries');
$regions   = __get('regions');
$cities    = __get('cities');
$locales   = __get('locales');
$aboutLocale = in_array(osc_current_admin_locale(), array_column((array) $locales, 'pk_c_code'), true)
    ? osc_current_admin_locale()
    : (string) ($locales[0]['pk_c_code'] ?? '');

/**
 * The user form's add/edit copy, plus the alerts shown when editing.
 *
 * @return array{edit:bool,title:string,action_frm:string,btn_text:string,alerts:array<int,array<string,string>>}
 */
function customFrmText()
{
    $user   = __get('user');
    $return = array();

    if (isset($user['pk_i_id'])) {
        $return['edit']       = true;
        $return['title']      = __('Edit user');
        $return['action_frm'] = 'edit_post';
        $return['btn_text']   = __('Save changes');
        $return['alerts']     = Alerts::getInstance()->findByUser($user['pk_i_id'], true);
    } else {
        $return['edit']       = false;
        $return['title']      = __('Add new user');
        $return['action_frm'] = 'create_post';
        $return['btn_text']   = __('Add user');
        $return['alerts']     = array();
    }

    return $return;
}

osc_admin_page(array(
    'section' => __('Users'),
    'heading' => static fn () => customFrmText()['title'],
));

/**
 * Filter callback for `admin_title`: prefix the browser title with the form's title.
 *
 * @param string $string
 *
 * @return string
 */
function customPageTitle($string)
{
    $aux = customFrmText();

    return sprintf('%s &raquo; %s', $aux['title'], $string);
}

osc_add_filter('admin_title', 'customPageTitle');

//customize Head
/**
 * Emit the user form's client-side validation and location scripts, picking the edit or create rule set.
 *
 * @return void
 */
function customHead()
{
    $user = __get('user');

    if (isset($user['pk_i_id'])) {
        UserForm::js_validation_edit();
    } else {
        UserForm::js_validation();
    } ?>
    <?php UserForm::location_javascript('admin'); ?>

    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

$aux = customFrmText();
?>
<?php osc_enqueue_script('admin-editor');
osc_current_admin_theme_path('parts/header.php'); ?>
<?php if ($aux['edit'] && count($aux['alerts']) > 0) { ?>
    <script type="text/javascript">
        function delete_alert(id) {
            document.querySelector('#dialog-alert-delete input[name="alert_id[]"]').value = id;
            document.getElementById("dialog-alert-delete").showModal();
        }
    </script>
<?php } ?>
<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        oscValidateForm(document.getElementById('register'), {
            rules: { s_username: { required: true } },
            messages: {
                s_username: {
                    required: '<?php echo osc_esc_js(__('Username: this field is required')); ?>.'
                }
            },
            errorContainer: '#error_list',
            onInvalid: function () {
                var h1 = document.querySelector('h1');
                if (h1) { h1.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            }
        });

        // Debounced username-availability check as the field is typed.
        var cInterval;
        var username = document.getElementById('s_username');
        var available = document.getElementById('available');
        if (username) {
            username.addEventListener('keydown', function () {
                if (username.value !== '') {
                    clearInterval(cInterval);
                    cInterval = setInterval(function () {
                        clearInterval(cInterval);
                        fetch("<?php echo osc_base_url(true); ?>?page=ajax&action=check_username_availability&s_username=" + encodeURIComponent(username.value), {
                            credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        }).then(function (r) {
                            return r.json();
                        }).then(function (data) {
                            if (available) {
                                available.textContent = (data.exists == 0)
                                    ? '<?php echo osc_esc_js(__('The username is available')); ?>'
                                    : '<?php echo osc_esc_js(__('The username is NOT available')); ?>';
                            }
                        });
                    }, 1000);
                }
            });
        }
    });
</script>
<?php
// Editing has two tabs, like a settings page: the profile form, and the user's alerts.
$tab = $aux['edit'] && Params::getParamString('tab') === 'alerts' ? 'alerts' : 'profile';
if ($aux['edit']) {
    $editUrl = osc_admin_base_url(true) . '?page=users&action=edit&id=' . (int) $user['pk_i_id'];
    osc_admin_tab_nav(array(
        array('label' => __('Profile'), 'url' => $editUrl, 'active' => $tab === 'profile'),
        array('label' => __('Alerts'), 'url' => $editUrl . '&tab=alerts', 'active' => $tab === 'alerts', 'count' => count($aux['alerts'])),
    ));
}

if ($tab === 'profile') {
    echo '<ul id="error_list"></ul>';
    // The shipped validator and plugins find this form by its name and id.
    osc_admin_form_open(array(
        'id'     => 'register',
        'name'   => 'register',
        'page'   => 'users',
        'action' => $aux['action_frm'],
        'upload' => true,
    ));
    UserForm::primary_input_hidden($user);

    if ($aux['edit']) {
        $userMoves = (array) __get('userMoves'); ?>
        <input type="hidden" name="b_enabled" value="<?php echo (int) $user['b_enabled']; ?>"/>
        <input type="hidden" name="b_active" value="<?php echo (int) $user['b_active']; ?>"/>
        <?php osc_admin_form_section(__('Account')); ?>
        <?php
        $lastSeen = !empty($user['dt_access_date'])
            ? osc_admin_date((string) $user['dt_access_date']) . ' ' . osc_esc_html(sprintf(__('from %s'), (string) $user['s_access_ip']))
            : osc_esc_html(__('Never'));
        osc_admin_definition(array(
            array('label' => __('Status'), 'html' => true, 'value' => '<div class="user-editor-status">'
                . \mindstellar\admin\ui\Form::statusBadge(!empty($user['b_active']) ? 'active' : 'inactive', osc_esc_html(!empty($user['b_active']) ? __('Active') : __('Inactive')))
                . \mindstellar\admin\ui\Form::statusBadge(!empty($user['b_enabled']) ? 'active' : 'blocked', osc_esc_html(!empty($user['b_enabled']) ? __('Can sign in') : __('Blocked')))
                . '</div>'),
            array('label' => __('Registered'), 'html' => true, 'value' => osc_admin_date((string) $user['dt_reg_date'])),
            array('label' => __('Last seen'), 'html' => true, 'value' => $lastSeen),
            array('label' => __('Listings'), 'html' => true, 'value' => '<a href="'
                . osc_esc_html(osc_admin_base_url(true) . '?page=items&userId=' . (int) $user['pk_i_id']) . '">'
                . osc_esc_html(sprintf(_n('%d listing', '%d listings', (int) $user['i_items']), (int) $user['i_items'])) . '</a>'),
        )); ?>
        <?php osc_admin_form_row_open(__('Actions')); ?>
            <div class="user-editor-actions">
                <?php foreach ($userMoves['routine'] ?? array() as $move) {
                    osc_admin_action_button($move + array('variant' => 'dim'));
                }
                foreach ($userMoves['danger'] ?? array() as $move) {
                    osc_admin_action_button($move + array('variant' => 'outline-danger'));
                } ?>
            </div>
        <?php osc_admin_form_row_close(); ?>
    <?php } ?>

    <?php osc_admin_form_section(__('Profile')); ?>
    <?php osc_admin_form_row_open(__('Name'), array('for' => 's_name')); ?>
        <?php UserForm::name_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Username'), array('for' => 's_username')); ?>
        <?php UserForm::username_text($user); ?>
        <div id="available"></div>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open('', array('for' => 's_email', 'label_html' => osc_esc_html(__('E-mail')) . ' <span class="osc-field-required"></span>')); ?>
        <?php UserForm::email_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Cell phone'), array('for' => 's_phone_mobile')); ?>
        <?php UserForm::mobile_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Phone'), array('for' => 's_phone_land')); ?>
        <?php UserForm::phone_land_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Website'), array('for' => 's_website')); ?>
        <?php UserForm::website_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('User type'), array('for' => 'b_company')); ?>
        <?php UserForm::is_company_select($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Photo'), array('for' => 'avatar')); ?>
        <div class="user-editor-photo">
            <?php if ($aux['edit'] && function_exists('osc_user_avatar_url')) { ?>
                <img class="user-editor-avatar" src="<?php echo osc_esc_html(osc_user_avatar_url($user['pk_i_id'], 'normal')); ?>"
                     alt="<?php echo osc_esc_html(__('Current avatar')); ?>" width="64" height="64"/>
            <?php } ?>
            <input type="file" name="avatar" id="avatar" accept="image/*" class="form-control form-control-sm"/>
            <?php if ($aux['edit']) { ?>
                <label class="user-editor-remove">
                    <input type="checkbox" name="remove_avatar" id="remove_avatar" value="1"/>
                    <?php _e('Remove'); ?>
                </label>
            <?php } ?>
        </div>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('About'), array('for' => 's_info[' . $aboutLocale . ']')); ?>
        <?php UserForm::multilanguage_info($locales, $user); ?>
    <?php osc_admin_form_row_close(); ?>

    <?php osc_admin_form_section(__('Location')); ?>
    <?php osc_admin_form_row_open(__('Country'), array('for' => count($countries) > 1 ? 'countryId' : 'country')); ?>
        <?php UserForm::country_select($countries, $user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Region'), array('for' => count($regions) >= 1 ? 'regionId' : 'region')); ?>
        <?php UserForm::region_select($regions, $user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('City'), array('for' => count($cities) >= 1 ? 'cityId' : 'city')); ?>
        <?php UserForm::city_select($cities, $user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('City area'), array('for' => 'cityArea')); ?>
        <?php UserForm::city_area_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Zip code'), array('for' => 'zip')); ?>
        <?php UserForm::zip_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Address'), array('for' => 'address')); ?>
        <?php UserForm::address_text($user); ?>
    <?php osc_admin_form_row_close(); ?>

    <?php osc_admin_form_section(__('Password')); ?>
    <?php osc_admin_form_row_open($aux['edit'] ? __('New password') : __('Password'), array('for' => 's_password')); ?>
        <?php UserForm::password_text($user); ?>
        <?php if ($aux['edit']) { ?>
            <p class="help-box"><?php _e('Leave blank to keep the current password.'); ?></p>
        <?php } ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Repeat password'), array('for' => 's_password2')); ?>
        <?php UserForm::check_password_text($user); ?>
    <?php osc_admin_form_row_close(); ?>

    <?php
    // Plugin fields print their own rows; they get a heading only when one prints.
    ob_start();
    if (!$aux['edit']) {
        osc_run_hook('user_register_form');
    } else {
        osc_run_hook('user_profile_form', $user);
        osc_run_hook('user_form', $user);
    }
    $pluginFields = (string) ob_get_clean();
    if (trim($pluginFields) !== '') {
        osc_admin_form_section(__('More'));
        echo $pluginFields;
    }

    osc_admin_form_close(array(
        array('label' => $aux['btn_text'], 'type' => 'submit', 'variant' => 'primary'),
    ));
} else {
    if ($aux['alerts'] === array()) { ?>
        <p class="text-muted"><?php _e('This user has no alerts.'); ?></p>
    <?php } else { ?>
        <?php
        $tableRows = array();
        foreach ($aux['alerts'] as $alert) {
            $raw    = osc_get_raw_search((array) json_decode((string) $alert['s_search'], true));
            $search = array();
            if (!empty($raw['sPattern'])) {
                $search[] = sprintf(__('Words: %s'), $raw['sPattern']);
            }
            if (!empty($raw['aCategories'])) {
                $search[] = sprintf(__('Categories: %s'), implode(', ', (array) $raw['aCategories']));
            }
            $toggle = osc_admin_base_url(true) . '?page=users&action=status_alerts&alert_id[]=' . (int) $alert['pk_i_id']
                . '&' . osc_csrf_token_url() . '&user_id=' . (int) $user['pk_i_id'] . '&status=' . ($alert['b_active'] == 1 ? '0' : '1');
            $tableRows[] = array(
                'status' => array('render' => static function () use ($raw, $alert): void {
                    if (isset($raw['held'])) {
                        osc_admin_status('spam', __('Needs attention'));
                        echo ' ' . osc_esc_html(\mindstellar\search\AlertStore::reasonText((string) $raw['held']));
                    } else {
                        $alert['b_active'] == 1 ? osc_admin_status('active', __('On')) : osc_admin_status('inactive', __('Off'));
                    }
                }),
                'search'  => $search !== array() ? implode(' · ', $search) : __('Every new listing'),
                'actions' => array('class' => 'text-end', 'render' => static function () use ($toggle, $alert): void { ?>
                    <a class="btn btn-sm btn-dim" href="<?php echo osc_esc_html($toggle); ?>"><?php $alert['b_active'] == 1 ? _e('Turn off') : _e('Turn on'); ?></a>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="delete_alert('<?php echo (int) $alert['pk_i_id']; ?>');"><?php _e('Delete'); ?></button>
                <?php }),
            );
        }
        osc_admin_table(array(
            'status'  => array('label' => __('Status'), 'class' => 'col-status'),
            'search'  => __('Search'),
            'actions' => array('label' => __('Actions'), 'hide_label' => true, 'class' => 'text-end', 'col_name' => false),
        ), $tableRows, array('wrap' => 'actions', 'class' => 'user-alerts', 'stack' => true));
        ?>

        <?php osc_admin_confirm_dialog(array(
            'id'         => 'dialog-alert-delete',
            'title'      => __('Delete alert'),
            'text'       => __('Are you sure you want to delete this alert?'),
            'confirm'    => __('Delete'),
            'confirm_id' => 'alert-delete-submit',
            'url'        => osc_admin_base_url(true),
            'fields'     => array('page' => 'users', 'action' => 'delete_alerts', 'alert_id[]' => '', 'user_id' => (int) $user['pk_i_id']),
        )); ?>
    <?php }
} ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
