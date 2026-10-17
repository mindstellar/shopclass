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
echo '<ul id="error_list"></ul>';

// The shipped validator and plugins find this form by its name and id.
osc_admin_editor_open(array(
    'id'      => 'register',
    'name'    => 'register',
    'page'    => 'users',
    'action'  => $aux['action_frm'],
    'upload'  => true,
    'main_id'    => 'left-side',
    'main_class' => 'user-editor-main',
));
UserForm::primary_input_hidden($user);
if ($aux['edit']) { ?>
    <input type="hidden" name="b_enabled" value="<?php echo (int) $user['b_enabled']; ?>"/>
    <input type="hidden" name="b_active" value="<?php echo (int) $user['b_active']; ?>"/>
<?php }

// One stacked field, as the listing editor draws them: the label above, the control under it.
$field = static function (string $label, string $for, callable $control, bool $required = false, string $help = ''): void {
    echo '<div class="osc-field"><label class="form-label' . ($required ? ' osc-field-required' : '') . '" for="' . osc_esc_html($for) . '">'
        . osc_esc_html($label) . '</label>';
    $control();
    if ($help !== '') {
        echo '<p class="help-box">' . osc_esc_html($help) . '</p>';
    }
    echo '</div>';
};

// One plain form: section headings, no boxes. The avatar sits beside the profile fields.
osc_admin_form_section(__('Profile')); ?>
    <div class="user-editor-profile">
        <div class="user-editor-photo">
            <?php if ($aux['edit'] && function_exists('osc_user_avatar_url')) { ?>
                <img class="user-editor-avatar" src="<?php echo osc_esc_html(osc_user_avatar_url($user['pk_i_id'], 'normal')); ?>"
                     alt="<?php echo osc_esc_html(__('Current avatar')); ?>" width="96" height="96"/>
            <?php } ?>
            <label class="btn btn-sm btn-dim" for="avatar"><?php _e('Change photo'); ?></label>
            <input type="file" name="avatar" id="avatar" accept="image/*" class="visually-hidden"/>
            <?php if ($aux['edit']) { ?>
                <label class="user-editor-remove">
                    <input type="checkbox" name="remove_avatar" id="remove_avatar" value="1"/>
                    <?php _e('Remove'); ?>
                </label>
            <?php } ?>
        </div>
    <div class="user-editor-grid">
        <?php
        $field(__('Name'), 's_name', static fn () => UserForm::name_text($user));
        $field(__('Username'), 's_username', static function () use ($user) {
            UserForm::username_text($user);
            echo '<div id="available"></div>';
        });
        $field(__('E-mail'), 's_email', static fn () => UserForm::email_text($user), true);
        $field(__('Website'), 's_website', static fn () => UserForm::website_text($user));
        $field(__('Cell phone'), 's_phone_mobile', static fn () => UserForm::mobile_text($user));
        $field(__('Phone'), 's_phone_land', static fn () => UserForm::phone_land_text($user));
        $field(__('User type'), 'b_company', static fn () => UserForm::is_company_select($user));
        ?>
    </div>
    </div>
    <div class="osc-field">
        <span class="form-label"><?php _e('About'); ?></span>
        <?php UserForm::multilanguage_info($locales, $user); ?>
    </div>
<?php osc_admin_form_section(__('Location')); ?>
    <div class="user-editor-grid">
        <?php
        $field(__('Country'), 'countryId', static fn () => UserForm::country_select($countries, $user));
        $field(__('Region'), 'regionId', static fn () => UserForm::region_select($regions, $user));
        $field(__('City'), 'cityId', static fn () => UserForm::city_select($cities, $user));
        $field(__('City area'), 'cityArea', static fn () => UserForm::city_area_text($user));
        $field(__('Zip code'), 'zip', static fn () => UserForm::zip_text($user));
        $field(__('Address'), 'address', static fn () => UserForm::address_text($user));
        ?>
    </div>
<?php osc_admin_form_section(__('Password')); ?>
    <div class="user-editor-grid">
        <?php
        $field($aux['edit'] ? __('New password') : __('Password'), 's_password', static fn () => UserForm::password_text($user), !$aux['edit'],
            $aux['edit'] ? __('Leave blank to keep the current password.') : '');
        $field(__('Repeat password'), 's_password2', static fn () => UserForm::check_password_text($user), !$aux['edit']);
        ?>
    </div>
<?php

// Plugin fields get core's label column under their own heading, drawn only when one prints.
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
    echo '<div class="form-horizontal">' . $pluginFields . '</div>';
}

osc_admin_editor_rail(array('id' => 'right-side'));

if ($aux['edit']) {
    $userMoves = (array) __get('userMoves');
    $facts     = array(
        array('label' => __('Registered'), 'html' => true, 'value' => osc_admin_date((string) $user['dt_reg_date'])),
        array(
            'label' => __('Last seen'),
            'html'  => true,
            'value' => !empty($user['dt_access_date'])
                ? osc_admin_date((string) $user['dt_access_date']) . ' ' . osc_esc_html(sprintf(__('from %s'), (string) $user['s_access_ip']))
                : osc_esc_html(__('Never')),
        ),
        array(
            'label' => __('Listings'),
            'html'  => true,
            'value' => '<a href="' . osc_esc_html(osc_admin_base_url(true) . '?page=items&userId=' . (int) $user['pk_i_id']) . '">'
                . (int) $user['i_items'] . '</a>',
        ),
    );
    osc_admin_publish_panel(array(
        'status'  => array(
            !empty($user['b_active']) ? array('active', __('Active')) : array('inactive', __('Inactive')),
            !empty($user['b_enabled']) ? array('active', __('Can sign in')) : array('blocked', __('Blocked')),
        ),
        'rows'    => $facts,
        'actions' => $userMoves['routine'] ?? array(),
        'danger'  => $userMoves['danger'] ?? array(),
    ));
}


osc_admin_editor_close(array(
    array('label' => $aux['btn_text'], 'type' => 'submit', 'variant' => 'primary'),
    array('label' => __('Back to users'), 'url' => osc_admin_base_url(true) . '?page=users', 'variant' => 'dim'),
));

if ($aux['edit'] && count($aux['alerts']) > 0) {
    osc_admin_form_section(__('Alerts'), array('spaced' => true)); ?>
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
            <input type="hidden" id="alert_user_id" name="alert_user_id" value="<?php echo (int) $user['pk_i_id']; ?>"/>
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
<?php } ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
