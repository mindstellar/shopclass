<?php

/*
 * This file is part of Osclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\utility\Sanitize;

/**
 * Class UserActions
 */
class UserActions
{
    public $is_admin;
    public $manager;
    /**
     * @var \mindstellar\utility\Sanitize
     */
    private $Sanitize;

    /**
     * UserActions constructor.
     *
     * @param $is_admin
     */
    public function __construct($is_admin)
    {
        $this->is_admin = $is_admin;
        $this->manager  = User::newInstance();
        $this->Sanitize = new Sanitize();
    }

    /**
     * Add user data
     * @return int
     */
    public function add()
    {
        $error       = array();
        $flash_error = '';
        if (!$this->is_admin && osc_recaptcha_private_key() && !osc_check_recaptcha()) {
            $flash_error .= _m('The reCAPTCHA was not entered correctly') . PHP_EOL;
            $error[]     = 4;
        }

        if (Params::getParam('s_password', false, false) == '') {
            $flash_error .= _m('The password cannot be empty') . PHP_EOL;
            $error[]     = 6;
        }

        if (Params::getParam('s_password', false, false) != Params::getParam('s_password2', false, false)) {
            $flash_error .= _m("Passwords don't match") . PHP_EOL;
            $error[]     = 7;
        }

        $input = $this->prepareData(true);

        if (!osc_validate_url($input['s_website'])) {
            $input['s_website'] = '';
        }

        if ($input['s_name'] == '') {
            $flash_error .= _m('The name cannot be empty') . PHP_EOL;
            $error[]     = 10;
        }

        if (!osc_validate_email($input['s_email'])) {
            $flash_error .= _m('The email is not valid') . PHP_EOL;
            $error[]     = 5;
        }

        if (is_array(Params::getParam('s_info'))) {
            foreach (Params::getParam('s_info') as $key => $value) {
                // validate max length to 512 chars
                $valid = osc_validate_text($value, 256, false);
                if (!$valid) {
                    $flash_error .= _m('The field %s is too long', $key) . PHP_EOL;
                    $error[]     = 11;
                }
            }
        }

        $email_taken = $this->manager->findByEmail($input['s_email']);
        if ($email_taken != false) {
            osc_run_hook('register_email_taken', $input['s_email']);
            $flash_error .= _m('The specified e-mail is already in use') . PHP_EOL;
            $error[]     = 3;
        }

        if ($input['s_username'] != '') {
            $username_taken = $this->manager->findByUsername($input['s_username']);
            if (!$error && $username_taken != false) {
                $flash_error .= _m('Username is already taken') . PHP_EOL;
                $error[]     = 8;
            }
            if (osc_is_username_blacklisted($input['s_username'])) {
                $flash_error .= _m('The specified username is not valid, it contains some invalid words') . PHP_EOL;
                $error[]     = 9;
            }
        }

        $flash_error = osc_apply_filter('user_add_flash_error', $flash_error);
        if ($flash_error != '') {
            Session::newInstance()->_setForm('user_s_name', $input['s_name']);
            Session::newInstance()->_setForm('user_s_username', $input['s_username']);
            Session::newInstance()->_setForm('user_s_email', $input['s_email']);
            Session::newInstance()->_setForm('user_s_phone_land', $input['s_phone_land']);
            Session::newInstance()->_setForm('user_s_phone_mobile', $input['s_phone_mobile']);

            osc_run_hook('user_register_failed', $error);

            return $flash_error;
        }

        // hook pre add or edit
        osc_run_hook('pre_user_post');


        $this->manager->insert($input);
        $userId = $this->manager->dao->insertedId();

        if ($input['s_username'] == '') {
            $this->manager->update(
                array('s_username' => $userId),
                array('pk_i_id' => $userId)
            );
        }

        if (is_array(Params::getParam('s_info'))) {
            foreach (Params::getParam('s_info') as $key => $value) {
                $this->manager->updateDescription($userId, $key, $value);
            }
        }

        Log::newInstance()->insertLog(
            'user',
            $this->is_admin ? 'add' : 'register',
            $userId,
            $input['s_email'],
            $this->is_admin ? 'admin' : 'user',
            $this->is_admin ? osc_logged_admin_id() : $userId
        );

        $user = $this->manager->findByPrimaryKey($userId);
        if (!$this->is_admin && osc_notify_new_user()) {
            osc_run_hook('hook_email_admin_new_user', $user);
        }

        if (!$this->is_admin && osc_user_validation_enabled()) {
            osc_run_hook('hook_email_user_validation', $user, $input);
            $success = 1;
        } else {
            $this->manager->update(
                array('b_active' => '1'),
                array('pk_i_id' => $userId)
            );

            // update items with s_contact_email the same as new user email
            $items_updated =
                Item::newInstance()->update(
                    array('fk_i_user_id' => $userId, 's_contact_name' => $input['s_name']),
                    array('s_contact_email' => $input['s_email'])
                );
            if ($items_updated !== false && $items_updated > 0) {
                User::newInstance()->update('i_items = i_items + ' . (int)$items_updated, array('pk_i_id' => $userId));
            }
            // update alerts user id with the same email
            Alerts::newInstance()->update(array('fk_i_user_id' => $userId), array('s_email' => $input['s_email']));

            $success = 2;
        }

        osc_run_hook('user_register_completed', $userId);

        return $success;
    }

    /**
     * Prepare and sanitize user input data
     * @param $is_add
     *
     * @return array
     */
    public function prepareData($is_add)
    {
        $input = array();

        if ($is_add) {
            $date                    = date('Y-m-d H:i:s');
            $input['dt_reg_date']    = $date;
            $input['dt_mod_date']    = $date;
            $input['dt_access_date'] = $date;
            $input['s_secret']       = osc_genRandomPassword();
            $input['s_access_ip']    = Params::getServerParam('REMOTE_ADDR');
        } else {
            $input['dt_mod_date'] = date('Y-m-d H:i:s');
        }

        //only for administration, in the public website this two params are edited separately
        if ($this->is_admin || $is_add) {
            $input['s_email'] = $this->Sanitize->email(Params::getParam('s_email'));
            $password1 = Params::getParam('s_password', false, false);
            //if we want to change the password
            if ($password1) {
                $input['s_password'] = osc_hash_password($password1);
            }
            $input['s_username'] = $this->Sanitize->username(Params::getParam('s_username'));
        }

        $input['s_name']         = $this->Sanitize->string(Params::getParam('s_name'));
        $input['s_website']      = $this->Sanitize->websiteUrl(Params::getParam('s_website'));
        $input['s_phone_land']   = $this->Sanitize->phone(Params::getParam('s_phone_land'));
        $input['s_phone_mobile'] = $this->Sanitize->phone(Params::getParam('s_phone_mobile'));

        //locations...
        $country = Country::newInstance()->findByCode(Params::getParam('countryId'));
        if (count($country) > 0) {
            $input['fk_c_country_code']   = $country['pk_c_code'];
            $input['s_country'] = $country['s_name'];
        } else {
            $input['fk_c_country_code']   = null;
            $input['s_country'] = $this->Sanitize->string(Params::getParam('country'));
        }

        if ((int)Params::getParam('regionId')) {
            $region = Region::newInstance()->findByPrimaryKey(Params::getParam('regionId'));
            if (count($region) > 0) {
                $input['fk_i_region_id']   = $region['pk_i_id'];
                $input['s_region'] = $region['s_name'];
            }
        } else {
            $input['fk_i_region_id']   = null;
            $input['s_region'] = $this->Sanitize->string(Params::getParam('region'));
        }

        if ((int)Params::getParam('cityId')) {
            $city = City::newInstance()->findByPrimaryKey(Params::getParam('cityId'));
            if (count($city) > 0) {
                $input['fk_i_city_id']   = $city['pk_i_id'];
                $input['s_city'] = $city['s_name'];
            }
        } else {
            $input['fk_i_city_id']   = null;
            $input['s_city'] = $this->Sanitize->string(Params::getParam('city'));
        }

        $input['s_city_area']       = $this->Sanitize->string(Params::getParam('cityArea'));
        $input['s_address']         = $this->Sanitize->string(Params::getParam('address'));
        $input['s_zip']             = $this->Sanitize->string(Params::getParam('zip'));

        $latitude = $this->Sanitize->string(Params::getParam('d_coord_lat'));
        $input['d_coord_lat']       = ($latitude) ?: null;
        $longitude = $this->Sanitize->string(Params::getParam('d_coord_long'));
        $input['d_coord_long']      = ($longitude) ?: null;

        $input['b_company']         = (Params::getParam('b_company')) ? 1 : 0;

        return $input;
    }

    /**
     * Edit user data
     * @param $userId
     *
     * @return int
     */
    public function edit($userId)
    {

        $input = $this->prepareData(false);

        // hook pre add or edit
        osc_run_hook('pre_user_post');
        $flash_error = '';
        $error       = array();
        if ($this->is_admin) {
            $user_email = $this->manager->findByEmail($input['s_email']);
            if (isset($user_email['pk_i_id']) && $user_email['pk_i_id'] != $userId) {
                $flash_error .= sprintf(_m('The specified e-mail is already used by %s'), $user_email['s_username'])
                    . PHP_EOL;
                $error[]     = 3;
            }
        }

        if (!osc_validate_url($input['s_website'])) {
            $input['s_website'] = '';
        }

        if ($input['s_name'] == '') {
            $flash_error .= _m('The name cannot be empty') . PHP_EOL;
            $error[]     = 10;
        }

        if ($this->is_admin
            && Params::getParam('s_password', false, false) != Params::getParam('s_password2', false, false)
        ) {
            $flash_error .= _m("Passwords don't match") . PHP_EOL;
            $error[]     = 7;
        }

        $flash_error = osc_apply_filter('user_edit_flash_error', $flash_error, $userId);
        if ($flash_error != '') {
            return $flash_error;
        }

        $this->manager->update($input, array('pk_i_id' => $userId));

        if ($this->is_admin) {
            Item::newInstance()->update(array(
                's_contact_name'  => $input['s_name'],
                's_contact_email' => $input['s_email']
            ), array('fk_i_user_id' => $userId));
            ItemComment::newInstance()->update(array(
                's_author_name'  => $input['s_name'],
                's_author_email' => $input['s_email']
            ), array('fk_i_user_id' => $userId));
            Alerts::newInstance()->update(array('s_email' => $input['s_email']), array('fk_i_user_id' => $userId));

            Log::newInstance()
                ->insertLog(
                    'user',
                    'edit',
                    $userId,
                    $input['s_email'],
                    $this->is_admin ? 'admin' : 'user',
                    $this->is_admin ? osc_logged_admin_id() : osc_logged_user_id()
                );
        } else {
            Item::newInstance()->update(array('s_contact_name' => $input['s_name']), array('fk_i_user_id' => $userId));
            ItemComment::newInstance()
                ->update(array('s_author_name' => $input['s_name']), array('fk_i_user_id' => $userId));
            $user = $this->manager->findByPrimaryKey($userId);

            Log::newInstance()->insertLog(
                'user',
                'edit',
                $userId,
                $user['s_email'],
                $this->is_admin ? 'admin' : 'user',
                $this->is_admin ? osc_logged_admin_id() : osc_logged_user_id()
            );
        }

        if (!$this->is_admin) {
            Session::newInstance()->_set('userName', $input['s_name']);
            $phone = $input['s_phone_mobile'] ?: $input['s_phone_land'];
            Session::newInstance()->_set('userPhone', $phone);
        }

        if (is_array(Params::getParam('s_info'))) {
            foreach (Params::getParam('s_info') as $key => $value) {
                $this->manager->updateDescription($userId, $key, $value);
            }
        }

        osc_run_hook('user_edit_completed', $userId);

        if ($this->is_admin) {
            $iUpdated = 0;
            if (Params::getParam('b_enabled')) {
                $iUpdated += $this->manager->update(array('b_enabled' => 1), array('pk_i_id' => $userId));
            } else {
                $iUpdated += $this->manager->update(array('b_enabled' => 0), array('pk_i_id' => $userId));
            }

            if (Params::getParam('b_active')) {
                $iUpdated += $this->manager->update(array('b_active' => 1), array('pk_i_id' => $userId));
            } else {
                $iUpdated += $this->manager->update(array('b_active' => 0), array('pk_i_id' => $userId));
            }

            if ($iUpdated > 0) {
                return 2;
            }
        }

        return 1;
    }

    /**
     * Recover user password
     * @return int
     */
    public function recover_password()
    {
        $user = User::newInstance()->findByEmail(Params::getParam('s_email'));
        Session::newInstance()->_set('recover_time', time());

        if ((osc_recaptcha_private_key() != '') && Session::newInstance()->_get('recover_captcha_not_set') != 1
            && !osc_check_recaptcha()
        ) {
            return 2; // BREAK THE PROCESS, THE RECAPTCHA IS WRONG
        }

        if (!$user || ($user['b_enabled'] == 0)) {
            return 1;
        }

        $code = osc_genRandomPassword(30);
        $date = date('Y-m-d H:i:s');
        User::newInstance()->update(
            array('s_pass_code' => $code, 's_pass_date' => $date, 's_pass_ip' => Params::getServerParam('REMOTE_ADDR')),
            array('pk_i_id' => $user['pk_i_id'])
        );

        $password_url = osc_forgot_user_password_confirm_url($user['pk_i_id'], $code);
        osc_run_hook('hook_email_user_forgot_password', $user, $password_url);

        return 0;
    }

    /**
     * Activate User
     * @param $user_id
     *
     * @return bool
     */
    public function activate($user_id)
    {
        $user = $this->manager->findByPrimaryKey($user_id);

        if (!$user) {
            return false;
        }

        $this->manager->update(array('b_active' => 1), array('pk_i_id' => $user_id));

        if (!$this->is_admin) {
            osc_run_hook('hook_email_admin_new_user', $user);
        }

        Log::newInstance()
            ->insertLog(
                'user',
                'activate',
                $user_id,
                $user['s_email'],
                $this->is_admin ? 'admin' : 'user',
                $this->is_admin ? osc_logged_admin_id() : osc_logged_user_id()
            );

        if ($user['b_enabled'] == 1) {
            $mItem = new ItemActions(true);
            $items = Item::newInstance()->findByUserID($user_id);
            foreach ($items as $item) {
                $mItem->enable($item['pk_i_id']);
            }
        }

        // update items with s_contact_email the same as new user email
        $items_updated =
            Item::newInstance()->update(
                array('fk_i_user_id' => $user_id, 's_contact_name' => $user['s_name']),
                array('s_contact_email' => $user['s_email'])
            );
        if ($items_updated !== false && $items_updated > 0) {
            User::newInstance()->update('i_items = i_items + ' . (int)$items_updated, array('pk_i_id' => $user_id));
        }
        // update alerts user id with the same email
        Alerts::newInstance()->update(array('fk_i_user_id' => $user_id), array('s_email' => $user['s_email']));

        osc_run_hook('activate_user', $user);

        return true;
    }

    /**
     * Deactive user
     * @param $user_id
     *
     * @return bool
     */
    public function deactivate($user_id)
    {
        $user = $this->manager->findByPrimaryKey($user_id);

        if (!$user) {
            return false;
        }

        $this->manager->update(array('b_active' => 0), array('pk_i_id' => $user_id));

        Log::newInstance()
            ->insertLog(
                'user',
                'deactivate',
                $user_id,
                $user['s_email'],
                $this->is_admin ? 'admin' : 'user',
                $this->is_admin ? osc_logged_admin_id() : osc_logged_user_id()
            );

        if ($user['b_enabled'] == 1) {
            $mItem = new ItemActions(true);
            $items = Item::newInstance()->findByUserID($user_id);
            foreach ($items as $item) {
                $mItem->disable($item['pk_i_id']);
            }
        }
        osc_run_hook('deactivate_user', $user);

        return true;
    }

    /**
     * Enable User
     * @param $user_id
     *
     * @return bool
     */
    public function enable($user_id)
    {
        $user = $this->manager->findByPrimaryKey($user_id);

        if (!$user) {
            return false;
        }

        $this->manager->update(array('b_enabled' => 1), array('pk_i_id' => $user_id));

        Log::newInstance()->insertLog(
            'user',
            'enable',
            $user_id,
            $user['s_email'],
            $this->is_admin ? 'admin' : 'user',
            $this->is_admin ? osc_logged_admin_id() : osc_logged_user_id()
        );

        if ($user['b_active'] == 1) {
            $mItem = new ItemActions(true);
            $items = Item::newInstance()->findByUserID($user_id);
            foreach ($items as $item) {
                $mItem->enable($item['pk_i_id']);
            }
        }
        osc_run_hook('enable_user', $user);

        return true;
    }

    /**
     * Disable user
     * @param $user_id
     *
     * @return bool
     */
    public function disable($user_id)
    {
        $user = $this->manager->findByPrimaryKey($user_id);

        if (!$user) {
            return false;
        }

        $this->manager->update(array('b_enabled' => 0), array('pk_i_id' => $user_id));

        Log::newInstance()->insertLog(
            'user',
            'disable',
            $user_id,
            $user['s_email'],
            $this->is_admin ? 'admin' : 'user',
            $this->is_admin ? osc_logged_admin_id() : osc_logged_user_id()
        );

        if ($user['b_active'] == 1) {
            $mItem = new ItemActions(true);
            $items = Item::newInstance()->findByUserID($user_id);
            foreach ($items as $item) {
                $mItem->disable($item['pk_i_id']);
            }
        }
        osc_run_hook('disable_user', $user);

        return true;
    }

    /**
     * Resend user activation email
     * @param $user_id
     *
     * @return int
     */
    public function resend_activation($user_id)
    {
        $user              = $this->manager->findByPrimaryKey($user_id);
        $input['s_secret'] = $user['s_secret'];

        if (!$user || $user['b_active'] == 1) {
            return 0;
        }

        if (osc_user_validation_enabled()) {
            osc_run_hook('hook_email_user_validation', $user, $input);

            return 1;
        }

        return 0;
    }

    /**
     * Bootstrap user login
     * @param $user_id
     *
     * @return int
     */
    public function bootstrap_login($user_id)
    {
        $user = User::newInstance()->findByPrimaryKey($user_id);

        if (!$user) {
            return 0;
        }

        if (!$user['b_active']) {
            return 1;
        }

        if (!$user['b_enabled']) {
            return 2;
        }

        //we are logged in... let's go!
        Session::newInstance()->_set('userId', $user['pk_i_id']);
        Session::newInstance()->_set('userName', $user['s_name']);
        Session::newInstance()->_set('userEmail', $user['s_email']);
        $phone = $user['s_phone_mobile'] ?: $user['s_phone_land'];
        Session::newInstance()->_set('userPhone', $phone);

        return 3;
    }
}
