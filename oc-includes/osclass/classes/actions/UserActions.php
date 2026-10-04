<?php

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

use mindstellar\utility\Sanitize;

/**
 * Class UserActions
 */
class UserActions
{
    /**
     * Widths of the t_user columns an account form fills, from struct.sql.
     * tests/strict-write-guards.php reads this and pins it against the live schema, so a
     * column that is widened cannot leave a stale limit rejecting values it would now hold.
     */
    public const COLUMN_WIDTHS = array(
        's_name'         => 100,
        's_username'     => 100,
        's_email'        => 100,
        's_website'      => 100,
        's_phone_land'   => 45,
        's_phone_mobile' => 45,
        's_country'      => 80,
        's_region'       => 100,
        's_city'         => 100,
        's_city_area'    => 200,
        's_address'      => 100,
        's_zip'          => 15,
    );

    public $is_admin;
    public $manager;
    /**
     * @var \mindstellar\utility\Sanitize
     */
    private $Sanitize;

    /**
     * UserActions constructor.
     *
     * @param bool $is_admin Run as an admin edit rather than a front-end one
     */
    public function __construct($is_admin)
    {
        $this->is_admin = $is_admin;
        $this->manager  = User::newInstance();
        $this->Sanitize = new Sanitize();
    }

    /**
     * Add user data
     *
     * @return int|string 1 on success, 2 when activation is pending, else an error message
     */
    public function add()
    {
        $error       = array();
        $flash_error = '';
        if (!$this->is_admin && osc_captcha_enabled() && !osc_check_captcha()) {
            $flash_error .= _m('Please complete the security check.') . PHP_EOL;
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

        $too_long = $this->tooLongFields($input);
        if ($too_long !== '') {
            $flash_error .= $too_long;
            $error[]     = 11;
        }

        $email_taken = $this->manager->findByEmail($input['s_email']);
        if ($email_taken != false) {
            osc_run_hook('register_email_taken', $input['s_email']);
            $flash_error .= _m('The specified e-mail is already in use') . PHP_EOL;
            $error[]     = 3;
        }

        if ($input['s_username'] != '') {
            $numeric = self::numericUsernameError($input['s_username']);
            if ($numeric !== '') {
                $flash_error .= $numeric . PHP_EOL;
                $error[]     = 13;
            } elseif (osc_is_username_blacklisted($input['s_username'])) {
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

        // Persist only a fingerprint of the activation code; the plaintext travels solely in the
        // validation email below, which reads it back from $input.
        $activation_plain = $input['s_secret'] ?? '';
        if ($activation_plain !== '') {
            $input['s_secret'] = \mindstellar\security\ActionToken::hash($activation_plain);
        }

        // Insert with a unique placeholder, then claim the real name under the username lock.
        $chosen_username     = (string) $input['s_username'];
        $input['s_username'] = '_' . bin2hex(random_bytes(10));

        $userId = $this->manager->insertGetId($input);

        // insertGetId() swallows the database error and answers 0, so an unchecked call
        // walked on with $userId = 0 -- writing the log against no user, mailing an
        // activation link for a row that does not exist and telling the visitor the
        // account was created. Stop here instead and say so.
        if ($userId <= 0) {
            trigger_error('User insert produced no row; registration aborted.', E_USER_WARNING);
            osc_run_hook('user_register_failed', array(12));

            return _m('Your account could not be created. Please try again.') . PHP_EOL;
        }

        if ($chosen_username === '') {
            $input['s_username'] = self::assignDefaultUsername((int) $userId);
        } else {
            $claim = self::claimUsername((int) $userId, $chosen_username);
            if ($claim !== 'ok') {
                $this->manager->deleteByPrimaryKey($userId);
                Session::newInstance()->_setForm('user_s_name', $input['s_name']);
                Session::newInstance()->_setForm('user_s_username', $chosen_username);
                Session::newInstance()->_setForm('user_s_email', $input['s_email']);
                Session::newInstance()->_setForm('user_s_phone_land', $input['s_phone_land']);
                Session::newInstance()->_setForm('user_s_phone_mobile', $input['s_phone_mobile']);
                osc_run_hook('user_register_failed', array($claim === 'taken' ? 8 : 12));

                return $claim === 'taken'
                    ? _m('Username is already taken') . PHP_EOL
                    : _m('Your account could not be created. Please try again.') . PHP_EOL;
            }
            $input['s_username'] = $chosen_username;
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
            // Restore the plaintext code so the validation email builds a working link.
            $input['s_secret'] = $activation_plain;
            osc_run_hook('hook_email_user_validation', $user, $input);
            $success = 1;
        } else {
            $this->manager->update(
                array('b_active' => '1'),
                array('pk_i_id' => $userId)
            );

            // A visitor's address is unconfirmed here, so only an admin-made account takes the
            // guest listings and alerts under it.
            if ($this->is_admin) {
                self::claimGuestListings((int) $userId);
            }

            $success = 2;
        }

        osc_run_hook('user_register_completed', $userId);

        return $success;
    }

    /**
     * Prepare and sanitize user input data
     *
     * @param bool $is_add Build a row for an insert rather than an update
     *
     * @return array<string,mixed>
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

        if (Params::getParamInt('regionId')) {
            $region = Region::newInstance()->findByPrimaryKey(Params::getParam('regionId'));
            if (count($region) > 0) {
                $input['fk_i_region_id']   = $region['pk_i_id'];
                $input['s_region'] = $region['s_name'];
            }
        } else {
            $input['fk_i_region_id']   = null;
            $input['s_region'] = $this->Sanitize->string(Params::getParam('region'));
        }

        if (Params::getParamInt('cityId')) {
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

        // No user form posts coordinates, so a save without them keeps the stored ones.
        foreach (array('d_coord_lat' => 90, 'd_coord_long' => 180) as $coord => $limit) {
            if (Params::existParam($coord)) {
                $value         = Params::getParamString($coord);
                $input[$coord] = is_numeric($value) && abs((float) $value) <= $limit ? (float) $value : null;
            }
        }

        $input['b_company']         = (Params::getParam('b_company')) ? 1 : 0;

        return $input;
    }

    /**
     * Field-level rejection for anything wider than the column that has to hold it.
     *
     * prepareData() sanitises but never shortens, so an over-long value reaches
     * t_user as it was typed. A relaxed connection cuts it short and stores the
     * remainder; a strict one refuses the whole statement. Neither is something to
     * hand a visitor, so the value is refused by name before either can happen.
     *
     * @param array $input Row as prepareData() built it
     *
     * @return string Accumulated flash error, empty when every field fits
     */
    private function tooLongFields(array $input)
    {
        $labels = array(
            's_name'         => _m('Name'),
            's_username'     => _m('Username'),
            's_email'        => _m('E-mail'),
            's_website'      => _m('Website'),
            's_phone_land'   => _m('Landline'),
            's_phone_mobile' => _m('Mobile'),
            's_country'      => _m('Country'),
            's_region'       => _m('Region'),
            's_city'         => _m('City'),
            's_city_area'    => _m('Municipality'),
            's_address'      => _m('Address'),
            's_zip'          => _m('Zip code'),
        );

        $flash_error = '';
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            if (!isset($input[$column]) || osc_validate_max((string)$input[$column], $width)) {
                continue;
            }
            $flash_error .= sprintf(_m('%s is too long, the maximum is %d characters'), $labels[$column], $width)
                . PHP_EOL;
        }

        // s_info is TEXT, so its limit is 65535 bytes, not characters.
        foreach (Params::getParamArray('s_info') as $key => $value) {
            if (strlen(is_string($value) ? $value : '') > 65535) {
                $flash_error .= sprintf(_m('The field %s is too long'), osc_esc_html((string) $key)) . PHP_EOL;
            }
        }

        return $flash_error;
    }

    /**
     * Edit user data
     *
     * @param int $userId
     *
     * @return int|string 1 on success, 2 when a new email needs validating, else an error message
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

        $too_long = $this->tooLongFields($input);
        if ($too_long !== '') {
            $flash_error .= $too_long;
            $error[]     = 11;
        }

        // Only an admin edit carries s_username. A missing or unchanged name is left alone,
        // so an old id-based username still saves.
        $new_username = null;
        if (isset($input['s_username']) && Params::existParam('s_username')) {
            $current = $this->manager->findByPrimaryKey($userId);
            if (isset($current['s_username']) && $input['s_username'] !== $current['s_username']) {
                $new_username = $input['s_username'];
                $numeric      = self::numericUsernameError($new_username);
                if ($new_username === '') {
                    $flash_error .= _m('The specified username could not be empty') . PHP_EOL;
                    $error[]     = 14;
                } elseif ($numeric !== '') {
                    $flash_error .= $numeric . PHP_EOL;
                    $error[]     = 13;
                }
            }
        }
        unset($input['s_username']);

        $flash_error = osc_apply_filter('user_edit_flash_error', $flash_error, $userId);
        if ($flash_error != '') {
            return $flash_error;
        }

        $claim = $new_username !== null ? self::claimUsername((int) $userId, $new_username) : 'ok';
        if ($claim === 'taken') {
            return _m('The specified username is already in use') . PHP_EOL;
        }
        if ($claim !== 'ok') {
            return _m('Your profile could not be saved. Please try again.') . PHP_EOL;
        }

        if ($this->manager->update($input, array('pk_i_id' => $userId)) === false) {
            trigger_error('User update wrote no row; profile save aborted.', E_USER_WARNING);

            return _m('Your profile could not be saved. Please try again.') . PHP_EOL;
        }

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
            // Refresh the request-scoped identity after a self-service edit — no physical
            // session write, so a logged-in user stays session-free. The next request
            // re-resolves these from the database via the signed identity cookie.
            Session::newInstance()->_setEphemeral('userName', $input['s_name']);
            $phone = $input['s_phone_mobile'] ?: $input['s_phone_land'];
            Session::newInstance()->_setEphemeral('userPhone', $phone);
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
     *
     * The caller owns the captcha check, which has to happen before the throttle is
     * consulted — and a captcha token verifies exactly once, so there is only ever one
     * place to do it. CWebLogin's 'recover_post' does it, mirroring CAdminLogin.
     *
     * @return int 0 when the email was sent, 1 when the address matched no enabled account
     */
    public function recover_password()
    {
        $user = User::newInstance()->findByEmail(Params::getParam('s_email'));

        if (!$user || ($user['b_enabled'] == 0)) {
            return 1;
        }

        $code = User::newInstance()->issuePassCode((int)$user['pk_i_id'], User::PASS_CODE_RESET);

        $password_url = osc_forgot_user_password_confirm_url($user['pk_i_id'], $code);
        osc_run_hook('hook_email_user_forgot_password', $user, $password_url);

        return 0;
    }

    /**
     * Activate User
     *
     * @param int $user_id
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

        self::claimGuestListings((int) $user_id);

        osc_run_hook('activate_user', $user);

        return true;
    }

    /**
     * Move the guest listings and alerts posted with this account's e-mail to the account.
     * Call only once the address is confirmed, or for an account an admin made.
     *
     * @param int $userId
     *
     * @return void
     */
    public static function claimGuestListings(int $userId): void
    {
        $user = User::newInstance()->findByPrimaryKey($userId);
        if (!$user || (string) $user['s_email'] === '') {
            return;
        }

        try {
            $claimed = osc_db_table(Item::newInstance()->getTableName())
                ->where('s_contact_email', $user['s_email'])
                ->whereNull('fk_i_user_id')
                ->update(array('fk_i_user_id' => $userId, 's_contact_name' => $user['s_name']));
            if ($claimed > 0) {
                User::newInstance()->increaseNumItems($userId, $claimed);
            }
            osc_db_table(Alerts::newInstance()->getTableName())
                ->where('s_email', $user['s_email'])
                ->whereRaw('(fk_i_user_id IS NULL OR fk_i_user_id = 0)')
                ->update(array('fk_i_user_id' => $userId));
        } catch (\mindstellar\database\DbException $e) {
            trigger_error('Claiming guest listings failed: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /**
     * Deactive user
     *
     * @param int $user_id
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
     *
     * @param int $user_id
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
     *
     * @param int $user_id
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
     *
     * @param int $user_id
     *
     * @return int
     */
    public function resend_activation($user_id)
    {
        $user = $this->manager->findByPrimaryKey($user_id);

        if (!$user || $user['b_active'] == 1) {
            return 0;
        }

        if (osc_user_validation_enabled()) {
            // Rotate the activation code: email a fresh plaintext, persist only its fingerprint.
            $activation_plain  = osc_genRandomPassword();
            $input['s_secret'] = $activation_plain;
            $this->manager->update(
                array('s_secret' => \mindstellar\security\ActionToken::hash($activation_plain)),
                array('pk_i_id' => $user_id)
            );
            osc_run_hook('hook_email_user_validation', $user, $input);

            return 1;
        }

        return 0;
    }

    /**
     * Bootstrap user login
     *
     * @param int $user_id
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
        // Identity now lives in a signed, session-free cookie. Default to a browser-session
        // lifetime here; the login controller upgrades it to persistent when "remember me"
        // is ticked.
        osc_web_user_login($user);

        return 3;
    }

    /**
     * Apply a pending e-mail change once its confirmation code checks out.
     *
     * The code must match and be younger than User::PASS_CODE_TTL, and it is cleared on use.
     * The user row, their listings, comments and alerts switch in one transaction.
     *
     * @param int    $userId
     * @param string $code
     *
     * @return array{status:string,old:string,new:string} status is 'ok', 'invalid', 'taken' or 'failed'
     */
    public static function confirmEmailChange(int $userId, string $code): array
    {
        $result  = array('status' => 'invalid', 'old' => '', 'new' => '');
        $manager = User::newInstance();
        $user    = $userId > 0 && $code !== '' ? $manager->findByPrimaryKey($userId) : false;
        if (empty($user['pk_i_id'])) {
            return $result;
        }

        $stored = (string) ($user['s_pass_code'] ?? '');
        $issued = strtotime((string) ($user['s_pass_date'] ?? ''));
        if ($stored === '' || !hash_equals($stored, User::passCodeHash(User::PASS_CODE_EMAIL, $code))
            || (int) $user['b_enabled'] !== 1
            || $issued === false || $issued < time() - User::PASS_CODE_TTL
        ) {
            return $result;
        }

        $pending = UserEmailTmp::newInstance()->findByPrimaryKey($userId);
        $new     = (string) ($pending['s_new_email'] ?? '');
        if ($new === '') {
            return $result;
        }
        $result['old'] = (string) $user['s_email'];
        $result['new'] = $new;

        $holder = $manager->findByEmail($new);
        if (!empty($holder['pk_i_id']) && (int) $holder['pk_i_id'] !== $userId) {
            $result['status'] = 'taken';

            return $result;
        }

        $status = 'failed';
        try {
            osc_db_transaction(static function () use ($userId, $stored, $new, &$status) {
                // Matching on the code as well makes the link single-use under a double click.
                try {
                    $switched = osc_db_table(DB_TABLE_PREFIX . 't_user')
                        ->where('pk_i_id', $userId)
                        ->where('s_pass_code', $stored)
                        ->update(array('s_email' => $new, 's_pass_code' => null, 's_pass_date' => null));
                } catch (\mindstellar\database\DbException $e) {
                    $status = (int) $e->getCode() === 1062 ? 'taken' : 'failed';
                    throw $e;
                }
                if ($switched !== 1) {
                    $status = 'invalid';
                    throw new \RuntimeException('E-mail change not applied.');
                }
                osc_db_table(DB_TABLE_PREFIX . 't_item')->where('fk_i_user_id', $userId)->update(array('s_contact_email' => $new));
                osc_db_table(DB_TABLE_PREFIX . 't_item_comment')->where('fk_i_user_id', $userId)->update(array('s_author_email' => $new));
                osc_db_table(DB_TABLE_PREFIX . 't_alerts')->where('fk_i_user_id', $userId)->update(array('s_email' => $new));
                osc_db_table(DB_TABLE_PREFIX . 't_user_email_tmp')->where('s_new_email', $new)->delete();
            });
        } catch (\Throwable $e) {
            $result['status'] = $status;

            return $result;
        }

        $result['status'] = 'ok';

        return $result;
    }

    /**
     * The error for a username made only of digits, or '' when it has a letter or symbol.
     * Digit-only names are kept for the id-based name a blank registration gets.
     *
     * @param string $username Already sanitised
     *
     * @return string
     */
    public static function numericUsernameError(string $username): string
    {
        if ($username === '' || !ctype_digit($username)) {
            return '';
        }

        return _m('The username cannot be only numbers. Please add at least one letter.');
    }

    /**
     * Give a user a username unless another account already holds it.
     *
     * The check and the write run under a named lock; if the lock is not had within 5
     * seconds nothing is written. A duplicate-key error from a unique index on s_username
     * counts as taken.
     *
     * @param int    $userId
     * @param string $username
     *
     * @return string 'ok', 'taken' or 'failed'
     */
    public static function claimUsername(int $userId, string $username): string
    {
        $table  = DB_TABLE_PREFIX . 't_user';
        $lock   = 'osc_username_' . md5((defined('DB_NAME') ? DB_NAME : '') . $table);
        $locked = false;
        try {
            $locked = (int) osc_db_scalar('SELECT GET_LOCK(?, 5)', array($lock)) === 1;
        } catch (\mindstellar\database\DbException $e) {
            $locked = false;
        }
        if (!$locked) {
            return 'failed';
        }

        try {
            $taken = osc_db_table($table)
                ->where('s_username', $username)
                ->where('pk_i_id', '!=', $userId)
                ->count() > 0;
            if ($taken) {
                return 'taken';
            }
            osc_db_table($table)->where('pk_i_id', $userId)->update(array('s_username' => $username));
        } catch (\mindstellar\database\DbException $e) {
            return (int) $e->getCode() === 1062 ? 'taken' : 'failed';
        } finally {
            try {
                osc_db_scalar('SELECT RELEASE_LOCK(?)', array($lock));
            } catch (\mindstellar\database\DbException $e) {
                // The lock is dropped with the connection anyway.
            }
            if (function_exists('osc_invalidate_user_cache')) {
                osc_invalidate_user_cache($userId);
            }
        }

        return 'ok';
    }

    /**
     * Set the username a registration without one gets: the user id, or the id with a
     * suffix (_2, _3, ...) when an older account already holds it.
     *
     * @param int $userId
     *
     * @return string The name set, or '' when none could be written
     */
    private static function assignDefaultUsername(int $userId): string
    {
        for ($n = 1; $n <= 20; $n++) {
            $name   = $n === 1 ? (string) $userId : $userId . '_' . $n;
            $result = self::claimUsername($userId, $name);
            if ($result === 'ok') {
                return $name;
            }
            if ($result === 'failed') {
                break;
            }
        }
        trigger_error('No default username could be set for user ' . $userId . '.', E_USER_WARNING);

        return '';
    }
}
