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
        $return['btn_text']   = __('Update user');
        $return['alerts']     = Alerts::getInstance()->findByUser($user['pk_i_id'], true);
    } else {
        $return['edit']       = false;
        $return['title']      = __('Add new user');
        $return['action_frm'] = 'create_post';
        $return['btn_text']   = __('Add new user');
        $return['alerts']     = array();
    }

    return $return;
}

osc_admin_page(array(
    'section' => __('Users'),
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
            document.getElementById("alert_id").value = id;
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
osc_admin_page_head($aux['title']);

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
        <?php osc_admin_form_row_open(__('Status')); ?>
            <div class="user-editor-status">
                <?php !empty($user['b_active']) ? osc_admin_status('active', __('Active')) : osc_admin_status('inactive', __('Inactive')); ?>
                <?php !empty($user['b_enabled']) ? osc_admin_status('active', __('Can sign in')) : osc_admin_status('blocked', __('Blocked')); ?>
            </div>
        <?php osc_admin_form_row_close(); ?>
        <?php osc_admin_form_row_open(__('Registered')); ?>
            <div class="user-editor-text"><?php echo osc_admin_date((string) $user['dt_reg_date']); ?></div>
        <?php osc_admin_form_row_close(); ?>
        <?php osc_admin_form_row_open(__('Last seen')); ?>
            <div class="user-editor-text"><?php echo !empty($user['dt_access_date'])
                ? osc_admin_date((string) $user['dt_access_date']) . ' ' . osc_esc_html(sprintf(__('from %s'), (string) $user['s_access_ip']))
                : osc_esc_html(__('Never')); ?></div>
        <?php osc_admin_form_row_close(); ?>
        <?php osc_admin_form_row_open(__('Listings')); ?>
            <div class="user-editor-text"><a href="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=items&userId=' . (int) $user['pk_i_id']); ?>"><?php
                echo osc_esc_html(sprintf(_n('%d listing', '%d listings', (int) $user['i_items']), (int) $user['i_items'])); ?></a></div>
        <?php osc_admin_form_row_close(); ?>
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
    <?php osc_admin_form_row_open(__('Name')); ?>
        <?php UserForm::name_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Username')); ?>
        <?php UserForm::username_text($user); ?>
        <div id="available"></div>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open('', array('label_html' => osc_esc_html(__('E-mail')) . ' <span class="osc-field-required"></span>')); ?>
        <?php UserForm::email_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Cell phone')); ?>
        <?php UserForm::mobile_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Phone')); ?>
        <?php UserForm::phone_land_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Website')); ?>
        <?php UserForm::website_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('User type')); ?>
        <?php UserForm::is_company_select($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Photo')); ?>
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
    <?php osc_admin_form_row_open(__('About')); ?>
        <?php UserForm::multilanguage_info($locales, $user); ?>
    <?php osc_admin_form_row_close(); ?>

    <?php osc_admin_form_section(__('Location')); ?>
    <?php osc_admin_form_row_open(__('Country')); ?>
        <?php UserForm::country_select($countries, $user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Region')); ?>
        <?php UserForm::region_select($regions, $user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('City')); ?>
        <?php UserForm::city_select($cities, $user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('City area')); ?>
        <?php UserForm::city_area_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Zip code')); ?>
        <?php UserForm::zip_text($user); ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Address')); ?>
        <?php UserForm::address_text($user); ?>
    <?php osc_admin_form_row_close(); ?>

    <?php osc_admin_form_section(__('Password')); ?>
    <?php osc_admin_form_row_open($aux['edit'] ? __('New password') : __('Password')); ?>
        <?php UserForm::password_text($user); ?>
        <?php if ($aux['edit']) { ?>
            <p class="help-box"><?php _e('Leave blank to keep the current password.'); ?></p>
        <?php } ?>
    <?php osc_admin_form_row_close(); ?>
    <?php osc_admin_form_row_open(__('Repeat password')); ?>
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
        <div class="table-contains-actions">
            <table class="table osc-table-stack user-alerts">
                <thead>
                <tr>
                    <th><?php _e('Search'); ?></th>
                    <th><?php _e('Status'); ?></th>
                    <th class="text-end"><span class="visually-hidden"><?php _e('Actions'); ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($aux['alerts'] as $alert) {
                    $raw    = osc_get_raw_search((array) json_decode((string) $alert['s_search'], true));
                    $search = array();
                    if (!empty($raw['sPattern'])) {
                        $search[] = sprintf(__('Words: %s'), $raw['sPattern']);
                    }
                    if (!empty($raw['aCategories'])) {
                        $search[] = sprintf(__('Categories: %s'), implode(', ', (array) $raw['aCategories']));
                    }
                    $toggle = osc_admin_base_url(true) . '?page=users&action=status_alerts&alert_id[]=' . (int) $alert['pk_i_id']
                        . '&' . osc_csrf_token_url() . '&user_id=' . (int) $user['pk_i_id'] . '&status=' . ($alert['b_active'] == 1 ? '0' : '1'); ?>
                    <tr>
                        <td><?php echo osc_esc_html($search !== array() ? implode(' · ', $search) : __('Every new listing')); ?></td>
                        <td data-col-name="<?php echo osc_esc_html(__('Status')); ?>">
                            <?php if (isset($raw['held'])) {
                                osc_admin_status('spam', __('Needs attention'));
                                echo ' ' . osc_esc_html(\mindstellar\search\AlertStore::reasonText((string) $raw['held']));
                            } else {
                                $alert['b_active'] == 1 ? osc_admin_status('active', __('On')) : osc_admin_status('inactive', __('Off'));
                            } ?>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-dim" href="<?php echo osc_esc_html($toggle); ?>"><?php $alert['b_active'] == 1 ? _e('Turn off') : _e('Turn on'); ?></a>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="delete_alert('<?php echo (int) $alert['pk_i_id']; ?>');"><?php _e('Delete'); ?></button>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>

        <dialog id="dialog-alert-delete" class="osc-dialog osc-dialog-danger">
            <form method="post" action="<?php echo osc_admin_base_url(true); ?>">
                <input type="hidden" name="page" value="users"/>
                <input type="hidden" name="action" value="delete_alerts"/>
                <input type="hidden" id="alert_id" name="alert_id[]" value=""/>
                <input type="hidden" name="user_id" value="<?php echo (int) $user['pk_i_id']; ?>"/>
                <div class="osc-dialog-body">
                    <p class="osc-dialog-title">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <?php _e('Delete alert'); ?>
                    </p>
                    <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this alert?'); ?></p>
                </div>
                <div class="osc-dialog-actions">
                    <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                    <button id="alert-delete-submit" type="submit" class="btn btn-danger btn-sm"><?php _e('Delete'); ?></button>
                </div>
            </form>
        </dialog>
    <?php }
} ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
