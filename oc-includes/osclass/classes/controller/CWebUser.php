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

use mindstellar\admin\ListPaging;
use mindstellar\auth\Actor;
use mindstellar\search\UserAlerts;
use mindstellar\user\AccountInput;
use mindstellar\user\AccountService;
use mindstellar\user\Usernames;
use mindstellar\utility\AjaxResponse;
use mindstellar\validation\BlockedException;
use mindstellar\validation\InvalidException;
use mindstellar\validation\RefusedException;

/**
 * Class CWebUser
 */
class CWebUser extends WebSecBaseModel
{
    /**
     * Boots the secured base controller, bounces the visitor home when accounts are
     * disabled, and fires the `init_user` hook.
     */
    public function __construct()
    {
        parent::__construct();
        if (!osc_users_enabled()) {
            osc_add_flash_error_message(_m('Users not enabled'));
            $this->redirectTo(osc_base_url());
        }
        osc_run_hook('init_user');
    }

    //Business Layer...
    /**
     * Dispatches the signed-in account actions (dashboard, profile, alerts, listings,
     * password and email changes, account deletion) and renders their views.
     *
     * @return void
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('dashboard'):      //dashboard...
                $max_items =
                    (Params::getParam('max_items') != '') ? Params::getParam('max_items') : 5;
                $aItems    =
                    Item::getInstance()->findByUserIDEnabled(osc_logged_user_id(), 0, $max_items);
                //calling the view...
                $this->_exportVariableToView('items', $aItems);
                $this->_exportVariableToView('max_items', $max_items);
                $this->doView(osc_locate_template(array('user-dashboard.php'), 'user-dashboard'));
                break;
            case ('profile'):        //profile...
                $aUser      = User::getInstance()->findByPrimaryKey(osc_logged_user_id());
                $aCountries = Country::getInstance()->listAll();
                $aRegions   = array();
                if ($aUser['fk_c_country_code'] != '') {
                    $aRegions = Region::getInstance()->findByCountry($aUser['fk_c_country_code']);
                } elseif (count($aCountries) > 0) {
                    $aRegions = Region::getInstance()->findByCountry($aCountries[0]['pk_c_code']);
                }
                $aCities = array();
                if ($aUser['fk_i_region_id'] != '') {
                    $aCities = City::getInstance()->findByRegion((int) $aUser['fk_i_region_id']);
                } elseif (count($aRegions) > 0) {
                    $aCities = City::getInstance()->findByRegion((int) $aRegions[0]['pk_i_id']);
                }

                // user profile info description | user-profile.php @ frontend
                $aLocale = $aUser['locale'];
                foreach ($aLocale as $locale => $aInfo) {
                    $aUser['locale'][$locale]['s_info'] =
                        osc_apply_filter(
                            'user_profile_info',
                            $aInfo['s_info'],
                            $aUser['pk_i_id'],
                            $aInfo['fk_c_locale_code']
                        );
                }

                //calling the view...
                $this->_exportVariableToView('user', $aUser);
                $this->_exportVariableToView('countries', $aCountries);
                $this->_exportVariableToView('regions', $aRegions);
                $this->_exportVariableToView('cities', $aCities);
                $this->_exportVariableToView('locales', OSCLocale::getInstance()->listAllEnabled());

                $this->doView(osc_locate_template(array('user-profile.php'), 'user-profile'));
                break;
            case ('profile_post'):   //profile post...
                osc_csrf_check();
                $userId = (int) osc_logged_user_id();
                try {
                    (new AccountService())->update($userId, AccountInput::read(false), Actor::fromSession(false));
                    UserActions::refreshIdentity($userId);
                    $saved = true;
                } catch (InvalidException $e) {
                    $saved = false;
                    osc_add_flash_error_message(implode(PHP_EOL, array_column($e->errors(), 'message')) . PHP_EOL);
                }

                // Avatar is the logged-in user's own; never trust a posted user id here.
                $this->handleAvatarUpload($userId);

                if ($saved) {
                    osc_add_flash_ok_message(_m('Your profile has been updated successfully'));
                }
                $this->redirectTo(osc_user_profile_url());
                break;
            case ('alerts'):         //alerts
                $aAlerts = (new UserAlerts())->live((int) Session::getInstance()->_get('userId'));
                $user    =
                    User::getInstance()->findByPrimaryKey(Session::getInstance()->_get('userId'));
                foreach ($aAlerts as $k => $a) {
                    $search = \mindstellar\search\AlertReplay::search($a);
                    if ($search === null) {
                        $aAlerts[$k]['items'] = array();
                        continue;
                    }
                    $search->notFromUser(Session::getInstance()->_get('userId'));
                    $search->limit(0, 3);

                    $aAlerts[$k]['items'] = $search->doSearch();
                }

                $this->_exportVariableToView('alerts', $aAlerts);
                View::getInstance()->_reset('alerts');
                $this->_exportVariableToView('user', $user);
                $this->doView(osc_locate_template(array('user-alerts.php'), 'user-alerts'));
                break;
            case ('change_email'):           //change email
                $this->doView(osc_locate_template(array('user-change_email.php'), 'user-change_email'));
                break;
            case ('change_email_post'):      //change email post
                osc_csrf_check();
                try {
                    (new AccountService())->requestEmailChange((int) osc_logged_user_id(), Params::getParamString('new_email'), Actor::fromSession(false));
                } catch (RefusedException $e) {
                    osc_add_flash_error_message($e->getMessage());
                    $this->redirectTo(osc_change_user_email_url());
                }
                $this->redirectTo(osc_user_profile_url());
                break;
            case ('change_username'):        //change username
                $this->doView(osc_locate_template(array('user-change_username.php'), 'user-change_username'));
                break;
            case ('change_username_post'):   //change username
                osc_csrf_check();
                $username = (new \mindstellar\utility\Sanitize())->username(Params::getParam('s_username'));
                osc_run_hook(
                    'before_username_change',
                    Session::getInstance()->_get('userId'),
                    $username
                );
                if ($username != '') {
                    $user    = User::getInstance()->findByUsername($username);
                    $numeric = Usernames::numericError($username);
                    $claim   = '';
                    if ($numeric !== '') {
                        osc_add_flash_error_message($numeric);
                    } elseif (isset($user['s_username'])) {
                        osc_add_flash_error_message(_m('The specified username is already in use'));
                    } elseif (osc_is_username_blacklisted($username)) {
                        osc_add_flash_error_message(_m('The specified username is not valid, it contains some invalid words'));
                    } else {
                        $claim = Usernames::claim((int) Session::getInstance()->_get('userId'), $username);
                        if ($claim === 'taken') {
                            osc_add_flash_error_message(_m('The specified username is already in use'));
                        } elseif ($claim !== 'ok') {
                            osc_add_flash_error_message(_m('Your profile could not be saved. Please try again.'));
                        }
                    }
                    if ($claim === 'ok') {
                        osc_add_flash_ok_message(_m('The username was updated'));
                        osc_run_hook(
                            'after_username_change',
                            Session::getInstance()->_get('userId'),
                            Params::getParam('s_username')
                        );
                        $this->redirectTo(osc_user_profile_url());
                    }
                } else {
                    osc_add_flash_error_message(_m('The specified username could not be empty'));
                }
                $this->redirectTo(osc_change_user_username_url());
                break;
            case ('change_password'):        //change password
                $this->doView(osc_locate_template(array('user-change_password.php'), 'user-change_password'));
                break;
            case 'change_password_post':    //change password post
                osc_csrf_check();
                $user =
                    User::getInstance()->findByPrimaryKey(Session::getInstance()->_get('userId'));

                $password     = Params::getParamString('password', false, false);
                $newPassword  = Params::getParamString('new_password', false, false);
                $newPassword2 = Params::getParamString('new_password2', false, false);
                if ($password === '' || $newPassword === '' || $newPassword2 === '') {
                    osc_add_flash_warning_message(_m('Password cannot be blank'));
                    $this->redirectTo(osc_change_user_password_url());
                }

                try {
                    (new AccountService())->changePassword((array)$user, $password, $newPassword, $newPassword2);
                } catch (BlockedException $e) {
                    osc_add_flash_error_message(osc_login_throttle_message($e->retryAfter()));
                    $this->redirectTo(osc_change_user_password_url());
                } catch (InvalidException $e) {
                    osc_add_flash_error_message($e->pointer() === '/current_password' ? _m("Current password doesn't match") : $e->getMessage());
                    $this->redirectTo(osc_change_user_password_url());
                }

                osc_add_flash_ok_message(_m('Password has been changed'));
                $this->redirectTo(osc_user_profile_url());
                break;
            case 'sign_out_all_post':
                osc_csrf_check();
                $userId = (int) osc_logged_user_id();
                $user   = User::getInstance()->findByPrimaryKey($userId);
                $refused = empty($user) ? _m("Current password doesn't match")
                    : \mindstellar\auth\Reauth::verify($user, Params::getParamString('password', false, false));
                if ($refused !== '') {
                    osc_add_flash_error_message($refused);
                    $this->redirectTo(osc_change_user_password_url() . '#sign-out-all');
                    break;
                }
                \mindstellar\auth\SignOut::everywhereUser($userId);
                $this->logout();
                osc_add_flash_ok_message(_m('You are signed out on every device, this one too. Sign in again.'));
                $this->redirectTo(osc_user_login_url());
                break;
            case 'items':                   // view items user
                $itemsPerPage = ListPaging::length(10, 'itemsPerPage', 100);
                $page         = ListPaging::page() - 1;
                // The owner sees every listing they hold unless a status tab narrows it.
                $itemType     = Params::getParamString('itemType') ?: 'all';
                $total_items  =
                    Item::getInstance()->countItemTypesByUserID(osc_logged_user_id(), $itemType);
                $total_pages  = ceil($total_items / $itemsPerPage);
                $items        = Item::getInstance()
                    ->findItemTypesByUserID(
                        osc_logged_user_id(),
                        $page * $itemsPerPage,
                        $itemsPerPage,
                        $itemType
                    );

                osc_prime_item_upgrades($items);
                $this->_exportVariableToView('items', $items);
                $this->_exportVariableToView('search_total_pages', $total_pages);
                $this->_exportVariableToView('search_total_items', $total_items);
                $this->_exportVariableToView('items_per_page', $itemsPerPage);
                $this->_exportVariableToView('items_type', $itemType);
                $this->_exportVariableToView('search_page', $page);

                $this->doView(osc_locate_template(array('user-items.php'), 'user-items'));
                break;
            case 'unsub_alert':
                $email  = Params::getParam('email');
                $secret = Params::getParam('secret');
                $id     = Params::getParam('id');

                $alert  = Alerts::getInstance()->findByPrimaryKey($id);
                $result = 0;
                if (!empty($alert) && hash_equals((string)$alert['s_email'], (string)$email)
                    && hash_equals((string)$alert['s_secret'], (string)$secret)
                ) {
                    $result = (new UserAlerts())->unsubscribe((int) $id);
                }

                if ($result == 1) {
                    osc_add_flash_ok_message(_m('Unsubscribed correctly'));
                } else {
                    osc_add_flash_error_message(_m('Oops! There was a problem trying to unsubscribe you. Please contact an administrator'));
                }

                $this->redirectTo(osc_user_alerts_url());
                break;
            case 'export':
                // A copy of everything held about the person, for their own request.
                // Signed in, and the id and secret in the link both matching the session,
                // because it hands out the same data that deleting the account destroys.
                $id     = Params::getParamInt('id');
                $secret = Params::getParamString('secret');
                if (!osc_is_web_user_logged_in()) {
                    osc_add_flash_error_message(_m('Please sign in to download your data'));
                    $this->redirectTo(osc_user_login_url());
                    break;
                }

                $user = User::getInstance()->findByPrimaryKey(osc_logged_user_id());
                if (empty($user) || osc_logged_user_id() != $id || $secret !== $user['s_secret']) {
                    osc_add_flash_error_message(_m('That link is not valid'));
                    $this->redirectTo(osc_user_profile_url());
                    break;
                }

                $data = \mindstellar\privacy\PersonalData::export(osc_logged_user_id());
                if ($data === null) {
                    osc_add_flash_error_message(_m('Your data could not be prepared'));
                    $this->redirectTo(osc_user_profile_url());
                    break;
                }

                // Streamed rather than written somewhere and linked to. A file would need
                // a location, a name nobody can guess and something to delete it later;
                // sending the bytes straight to the person who asked needs none of that.
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="my-data-' . date('Y-m-d') . '.json"');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, no-store');
                AjaxResponse::json($data, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            case 'api_access':
                $this->apiAccessView(\mindstellar\apiaccess\ApiAccess::site()->accountAccess());
                break;
            case 'api_access_post':
                osc_csrf_check();
                $this->apiAccessPost(\mindstellar\apiaccess\ApiAccess::site()->accountAccess());
                break;
            case 'delete':
                // GET must not delete. Older themes still point here with id and
                // secret in the query string; those land on the confirm form and
                // the query values are ignored. Mail scanners and prefetchers
                // that only GET therefore cannot remove the account.
                $user = User::getInstance()->findByPrimaryKey(osc_logged_user_id());
                if (empty($user)) {
                    osc_add_flash_error_message(_m('Oops! you can not do that'));
                    $this->redirectTo(osc_user_login_url());
                    break;
                }
                $this->_exportVariableToView('user', $user);
                $this->doView(osc_locate_template(array('user-delete_account.php'), 'user-delete_account'));
                break;
            case 'delete_post':
                osc_csrf_check();
                $userId = (int) osc_logged_user_id();
                try {
                    (new AccountService())->delete($userId, Actor::fromSession(false), Params::getParamString('password', false, false));
                } catch (BlockedException $e) {
                    osc_add_flash_error_message(osc_login_throttle_message($e->retryAfter()));
                    $this->redirectTo(osc_user_delete_url());
                    break;
                } catch (InvalidException $e) {
                    if ($e->reason() === 'required') {
                        osc_add_flash_warning_message(_m('Password cannot be blank'));
                    } else {
                        osc_add_flash_error_message(_m("Current password doesn't match"));
                    }
                    $this->redirectTo(osc_user_delete_url());
                    break;
                } catch (RefusedException | RuntimeException $e) {
                    if ($e instanceof RuntimeException) {
                        trigger_error($e->getMessage(), E_USER_WARNING);
                    }
                    osc_add_flash_error_message(_m('Oops! you can not do that'));
                    $this->redirectTo($e instanceof \mindstellar\validation\NotFoundException ? osc_user_login_url() : osc_user_delete_url());
                    break;
                }

                Session::getInstance()->_drop('userId');
                Session::getInstance()->_drop('userName');
                Session::getInstance()->_drop('userEmail');
                Session::getInstance()->_drop('userPhone');
                Session::getInstance()->_dropEphemeral('userId');
                Session::getInstance()->_dropEphemeral('userName');
                Session::getInstance()->_dropEphemeral('userEmail');
                Session::getInstance()->_dropEphemeral('userPhone');
                View::getInstance()->_erase('_loggedUser');

                Cookie::getInstance()->pop('oc_userId');
                Cookie::getInstance()->pop('oc_userSecret');
                Cookie::getInstance()->set();

                osc_add_flash_ok_message(_m('Your account have been deleted'));
                $this->redirectTo(osc_base_url());
                break;
        }
    }

    /**
     * Handle an avatar file upload / removal for a user.
     *
     * @param int $userId
     *
     * @return void
     */
    private function handleAvatarUpload($userId)
    {
        \mindstellar\storage\AvatarUpload::handle((int)$userId, 'pubMessages');
    }

    /**
     * The "API access" page: the sign-ins and personal keys that act for this user.
     *
     * @param \mindstellar\apiaccess\AccountAccess $access
     * @param string                         $newKey a key's token, shown once right after it is made
     *
     * @return void
     */
    private function apiAccessView(\mindstellar\apiaccess\AccountAccess $access, string $newKey = '')
    {
        if (!osc_api_enabled()) {
            $this->redirectTo(osc_user_dashboard_url());
        }
        $userId = (int) osc_logged_user_id();
        $this->_exportVariableToView('api_sessions', $access->sessions($userId));
        $this->_exportVariableToView('api_user_keys', $access->userKeys());
        $this->_exportVariableToView('api_key_scopes', $access->userKeys() ? $access->keyScopes($userId) : array());
        $this->_exportVariableToView('api_new_key', $newKey);
        $this->doView(osc_locate_template(array('user-api_access.php'), 'user-api_access'));
    }

    /**
     * End a sign-in, revoke a key, or make a key. A new key's token is shown on this answer
     * and never again, so it is not put in a cookie or a redirect.
     *
     * @param \mindstellar\apiaccess\AccountAccess $access
     *
     * @return void
     */
    private function apiAccessPost(\mindstellar\apiaccess\AccountAccess $access)
    {
        if (!osc_api_enabled()) {
            $this->redirectTo(osc_user_dashboard_url());

            return;
        }
        $userId = (int) osc_logged_user_id();
        if (Params::getParamString('do') === 'create') {
            $user = User::getInstance()->findByPrimaryKey($userId);
            try {
                $token = $access->createKey(
                    is_array($user) ? $user : array(),
                    (string) Params::getParam('password', false, false),
                    Params::getParamString('name'),
                    array_map('strval', array_values(Params::getParamArray('scopes'))),
                    Params::getParamString('expires')
                );
            } catch (InvalidArgumentException $e) {
                osc_add_flash_error_message($e->getMessage());
                $this->redirectTo(osc_user_api_access_url());

                return;
            }
            osc_add_flash_ok_message(_m('The key is made. Copy it now: it is not shown again.'));
            $this->apiAccessView($access, $token);

            return;
        }

        if ($access->end($userId, Params::getParamString('session'))) {
            osc_add_flash_ok_message(_m('Access ended.'));
        } else {
            osc_add_flash_error_message(_m('That sign-in or key is not in the list any more.'));
        }
        $this->redirectTo(osc_user_api_access_url());
    }

    //hopefully generic...

    /**
     * Renders the account template, falling back to core's view when the theme has none.
     *
     * @param string $file Absolute path to the located template
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');
        // Core has a fallback page for every account view. A theme that ships the view
        // still wins; this only keeps a theme that does not from rendering blank.
        if (!osc_gui_account_view($file)) {
            osc_current_web_theme_path($file);
        }
        Session::getInstance()->_clearVariables();
        osc_run_hook('after_html');
    }
}

/* file end: ./CWebUser.php */
