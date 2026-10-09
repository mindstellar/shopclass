<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
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

use mindstellar\admin\BulkAction;
use mindstellar\admin\form\BanRuleForm;
use mindstellar\admin\form\CoreSettings;
use mindstellar\admin\form\UserSettingsScreen;
use mindstellar\admin\ListPaging;
use mindstellar\auth\Actor;
use mindstellar\database\DbException;
use mindstellar\exception\InvalidException;
use mindstellar\exception\RefusedException;
use mindstellar\search\UserAlerts;
use mindstellar\security\BanRuleStore;
use mindstellar\user\AccountInput;
use mindstellar\user\AccountService;

/**
 * Class CAdminUsers
 */
class CAdminUsers extends AdminSecBaseModel
{
    use \mindstellar\base\ActionMap;

    /** Each action and the method that answers it; any other action goes to users(). */
    private const ACTIONS = array(
        'create'               => 'createForm',
        'create_post'          => 'createPost',
        'edit'                 => 'editForm',
        'edit_post'            => 'editPost',
        'resend_activation'    => 'resendActivation',
        'activate'             => 'activateUser',
        'deactivate'           => 'deactivateUser',
        'enable'               => 'enableUser',
        'disable'              => 'disableUser',
        'sign_out_all'         => 'signOutAll',
        'delete'               => 'deleteUsers',
        'delete_alerts'        => 'deleteAlerts',
        'status_alerts'        => 'statusAlerts',
        'settings'             => 'settings',
        'settings_post'        => 'settingsPost',
        'alerts'               => 'alerts',
        'ban'                  => 'banRules',
        'edit_ban_rule'        => 'editBanRule',
        'edit_ban_rule_post'   => 'editBanRulePost',
        'create_ban_rule'      => 'createBanRule',
        'create_ban_rule_post' => 'createBanRulePost',
        'delete_ban_rule'      => 'deleteBanRules',
        'user_login'           => 'signInAsUser',
    );

    //specific for this class
    private $userManager;

    /**
     * Take the user manager for this request.
     */
    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->userManager = User::getInstance();
        osc_run_hook('init_admin_users');
    }

    //Business Layer...

    /**
     * Dispatch the requested users action: create, edit and their saves, the activate,
     * enable and delete toggles, alerts, ban rules, user settings and login-as-user.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();

        $method = $this->actionMethod('users');

        $this->$method();
    }

    /**
     * The form for a new user.
     */
    private function createForm(): void
    {
        $aRegions = array();
        $aCities  = array();

        $aCountries = Country::getInstance()->listAll();

        if (isset($aCountries[0]['pk_c_code'])) {
            $aRegions = Region::getInstance()->findByCountry($aCountries[0]['pk_c_code']);
        }

        if (isset($aRegions[0]['pk_i_id'])) {
            $aCities = City::getInstance()->findByRegion((int) $aRegions[0]['pk_i_id']);
        }

        $this->_exportVariableToView('user', null);
        $this->_exportVariableToView('countries', $aCountries);
        $this->_exportVariableToView('regions', $aRegions);
        $this->_exportVariableToView('cities', $aCities);
        $this->_exportVariableToView('locales', OSCLocale::getInstance()->listAllEnabled());

        $this->doView('users/frm.php');
    }

    /**
     * Create a user from the form.
     */
    private function createPost(): void
    {
        osc_csrf_check();
        $userActions = new UserActions(true);
        $success     = $userActions->add();

        switch ($success) {
            case 1:
                osc_add_flash_ok_message(
                    _m("The user has been created. We've sent an activation e-mail"),
                    'admin'
                );
                break;
            case 2:
                osc_add_flash_ok_message(_m('The user has been created successfully'), 'admin');
                break;
            default:
                osc_add_flash_error_message($success, 'admin');
                break;
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=users');
    }

    /**
     * The user editor.
     */
    private function editForm(): void
    {
        $aUser      = $this->userManager->findByPrimaryKey(Params::getParam('id'));
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

        $csrf_token = osc_csrf_token_url();
        if ($aUser['b_active']) {
            $actions[] = '<a class="btn btn-outline-danger" href="' . osc_admin_base_url(true)
                . '?page=users&action=deactivate&id[]=' . $aUser['pk_i_id'] . '&' . $csrf_token
                . '&value=INACTIVE">' . __('Deactivate') . '</a>';
        } else {
            $actions[] = '<a class="btn btn-danger" href="' . osc_admin_base_url(true)
                . '?page=users&action=activate&id[]=' . $aUser['pk_i_id'] . '&' . $csrf_token
                . '&value=ACTIVE">' . __('Activate') . '</a>';
        }
        if ($aUser['b_enabled']) {
            $actions[] = '<a class="btn btn-outline-danger" href="' . osc_admin_base_url(true)
                . '?page=users&action=disable&id[]=' . $aUser['pk_i_id'] . '&' . $csrf_token
                . '&value=DISABLE">' . __('Block') . '</a>';
        } else {
            $actions[] = '<a class="btn btn-danger" href="' . osc_admin_base_url(true)
                . '?page=users&action=enable&id[]=' . $aUser['pk_i_id'] . '&' . $csrf_token . '&value=ENABLE">'
                . __('Unblock') . '</a>';
        }
        $actions[] = '<a class="btn btn-outline-secondary" href="' . osc_admin_base_url(true)
            . '?page=users&action=user_login&id=' . $aUser['pk_i_id'] . '&' . $csrf_token . '" target="_blank">'
            . __('Login') . '</a>';

        $aLocale = $aUser['locale'];
        foreach ($aLocale as $locale => $aInfo) {
            $aUser['locale'][$locale]['s_info'] =
                osc_apply_filter(
                    'admin_user_profile_info',
                    $aInfo['s_info'],
                    $aUser['pk_i_id'],
                    $aInfo['fk_c_locale_code']
                );
        }

        $this->_exportVariableToView('actions', $actions);

        $this->_exportVariableToView('user', $aUser);
        $this->_exportVariableToView('countries', $aCountries);
        $this->_exportVariableToView('regions', $aRegions);
        $this->_exportVariableToView('cities', $aCities);
        $this->_exportVariableToView('locales', OSCLocale::getInstance()->listAllEnabled());
        $this->doView('users/frm.php');
    }

    /**
     * Save the user editor.
     */
    private function editPost(): void
    {
        osc_csrf_check();
        $userId = Params::getParamInt('id');
        try {
            $success = (new AccountService())->update($userId, AccountInput::read(true), $this->actor());
        } catch (InvalidException $e) {
            $success = implode(PHP_EOL, array_column($e->errors(), 'message')) . PHP_EOL;
        }

        // Admin edits any user; the avatar owner is the edited user's id.
        $this->handleAvatarUpload($userId);

        if ($success === 1) {
            osc_add_flash_ok_message(_m('The user has been updated'), 'admin');
        } elseif ($success === 2) {
            osc_add_flash_ok_message(_m('The user has been updated and activated'), 'admin');
        } else {
            osc_add_flash_error_message($success);
            $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=edit&id=' . $userId);
        }
        $this->redirectTo(osc_admin_base_url(true) . '?page=users');
    }

    /**
     * Send a user the activation e-mail again.
     */
    private function resendActivation(): void
    {
        //activate
        osc_csrf_check();
        $userId   = Params::getParam('id');
        if (!is_array($userId)) {
            osc_add_flash_error_message(_m("User id isn't in the correct format"), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=users');
        }

        $accounts = new AccountService();
        $sent     = BulkAction::apply(
            static fn ($id) => $accounts->resendActivation((int) $id),
            'Activation email sent to one user',
            'Activation email sent to %s users',
            ''
        );
        if ($sent === 0) {
            osc_add_flash_error_message(_m('No users have been selected'), 'admin');
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=users');
    }

    /**
     * Activate the selected users.
     */
    private function activateUser(): void
    {
        osc_csrf_check();
        $accounts = new AccountService();
        $actor    = $this->actor();
        BulkAction::apply(
            static fn ($id) => $accounts->activate((int) $id, $actor),
            'One user has been activated',
            '%s users have been activated',
            _m('No users have been activated')
        );
        $this->redirectTo(Params::getServerParam('HTTP_REFERER', false, false));
    }

    /**
     * Deactivate the selected users.
     */
    private function deactivateUser(): void
    {
        osc_csrf_check();
        $accounts = new AccountService();
        $actor    = $this->actor();
        BulkAction::apply(
            static fn ($id) => $accounts->deactivate((int) $id, $actor),
            'One user has been deactivated',
            '%s users have been deactivated',
            _m('No users have been deactivated')
        );
        $this->redirectTo(Params::getServerParam('HTTP_REFERER', false, false));
    }

    /**
     * Unblock the selected users.
     */
    private function enableUser(): void
    {
        osc_csrf_check();
        $accounts = new AccountService();
        $actor    = $this->actor();
        BulkAction::apply(
            static fn ($id) => $accounts->enable((int) $id, $actor),
            'One user has been unblocked',
            '%s users have been unblocked',
            _m('No users have been enabled')
        );
        $this->redirectTo(Params::getServerParam('HTTP_REFERER', false, false));
    }

    /**
     * Block the selected users.
     */
    private function disableUser(): void
    {
        osc_csrf_check();
        $accounts = new AccountService();
        $actor    = $this->actor();
        BulkAction::apply(
            static fn ($id) => $accounts->disable((int) $id, $actor),
            'One user has been blocked',
            '%s users have been blocked',
            _m('No users have been disabled')
        );
        $this->redirectTo(Params::getServerParam('HTTP_REFERER', false, false));
    }

    /**
     * Sign the selected users out on every device.
     */
    private function signOutAll(): void
    {
        osc_csrf_check();
        BulkAction::apply(
            static fn ($id) => \mindstellar\auth\SignOut::everywhereUser((int) $id),
            'One user has been signed out of all devices',
            '%s users have been signed out of all devices',
            _m('No users have been signed out')
        );
        $this->redirectTo(Params::getServerParam('HTTP_REFERER', false, false));
    }

    /**
     * Delete the selected users.
     */
    private function deleteUsers(): void
    {
        osc_csrf_check();
        $accounts = new AccountService();
        $actor    = $this->actor();
        BulkAction::apply(
            static function ($id) use ($accounts, $actor): bool {
                try {
                    $accounts->delete((int) $id, $actor);
                } catch (RefusedException | RuntimeException $e) {
                    return false;
                }

                return true;
            },
            'One user has been deleted',
            '%s users have been deleted',
            _m('No users have been deleted')
        );
        $this->redirectTo(osc_admin_base_url(true) . '?page=users');
    }

    /**
     * Delete the selected alerts.
     */
    private function deleteAlerts(): void
    {
        osc_csrf_check();
        $alertId  = Params::getParam('alert_id');
        if (!is_array($alertId)) {
            osc_add_flash_error_message(_m("Alert id isn't in the correct format"), 'admin');
            if (Params::getParam('user_id') == '') {
                $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=alerts');
            } else {
                $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=edit&id='
                    . Params::getParam('user_id'));
            }
        }

        $userAlerts = new UserAlerts();
        BulkAction::apply(
            static function ($id) use ($userAlerts) {
                Log::getInstance()
                    ->insertLog('user', 'delete_alerts', $id, $id, 'admin', osc_logged_admin_id());

                return $userAlerts->delete((int)$id);
            },
            'One alert has been deleted',
            '%s alerts have been deleted',
            _m('No alerts have been deleted'),
            'alert_id'
        );
        if (Params::getParam('user_id') == '') {
            $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=alerts');
        } else {
            $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=edit&id='
                . Params::getParam('user_id'));
        }
    }

    /**
     * Turn the selected alerts on or off.
     */
    private function statusAlerts(): void
    {
        osc_csrf_check();
        $status   = Params::getParam('status');
        $alertId  = Params::getParam('alert_id');

        if (!is_array($alertId)) {
            osc_add_flash_error_message(_m("Alert id isn't in the correct format"), 'admin');
            if (Params::getParam('user_id') == '') {
                $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=alerts');
            } else {
                $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=edit&id='
                    . Params::getParam('user_id'));
            }
        }

        $userAlerts = new UserAlerts();
        $activating = $status == 1;
        if ($activating && is_array($alertId)
            && \mindstellar\search\AlertStore::heldIds($alertId) !== array()
        ) {
            osc_add_flash_error_message(
                _m('A held alert cannot be activated. Ask the user to save the search again.'),
                'admin'
            );
        }
        BulkAction::apply(
            static fn ($id) => $userAlerts->setActive((int)$id, $activating),
            $activating ? 'One alert has been activated' : 'One alert has been deactivated',
            $activating ? '%s alerts have been activated' : '%s alerts have been deactivated',
            $activating ? _m('No alerts have been activated') : _m('No alerts have been deactivated'),
            'alert_id'
        );
        if (Params::getParam('user_id') == '') {
            $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=alerts');
        } else {
            $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=edit&id='
                . Params::getParam('user_id'));
        }
    }

    /**
     * The user settings page.
     */
    private function settings(): void
    {
        $this->drawSettings();
    }

    /**
     * Save the user settings.
     */
    private function settingsPost(): void
    {
        osc_csrf_check();
        $result = CoreSettings::attempt(UserSettingsScreen::register());
        if ($result['errors'] !== array()) {
            $this->drawSettings($result['values']);
            return;
        }
        if ($result['updated'] > 0) {
            osc_add_flash_ok_message(_m('User settings have been updated'), 'admin');
        }
        $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=settings');
    }

    /**
     * The alerts table.
     */
    private function alerts(): void
    {
        require_once osc_lib_path() . 'osclass/classes/datatables/AlertsDataTable.php';

        // set default iDisplayLength
        ListPaging::rememberedLength();
        $this->_exportVariableToView('iDisplayLength', Params::getParam('iDisplayLength'));

        // Table header order by related
        if (Params::getParam('sort') == '') {
            Params::setParam('sort', 'date');
        }
        if (Params::getParam('direction') == '') {
            Params::setParam('direction', 'desc');
        }

        $page = ListPaging::page();

        $params = Params::getParamsAsArray();

        $alertsDataTable = new AlertsDataTable();
        $alertsDataTable->table($params);
        $aData = $alertsDataTable->getData();

        $pastEnd = ListPaging::pastEnd($aData, (int) $page);
        if ($pastEnd !== null) {
            $this->redirectTo($pastEnd);
        }

        $this->_exportVariableToView('aData', $aData);
        $this->_exportVariableToView('aRawRows', $alertsDataTable->rawRows());

        $this->doView('users/alerts.php');
    }

    /**
     * The ban rules table.
     */
    private function banRules(): void
    {
        if (Params::getParam('action') != '') {
            osc_run_hook('ban_rules_bulk_' . Params::getParam('action'), Params::getParam('id'));
        }

        require_once osc_lib_path() . 'osclass/classes/datatables/BanRulesDataTable.php';

        // set default iDisplayLength
        ListPaging::rememberedLength();
        $this->_exportVariableToView('iDisplayLength', Params::getParam('iDisplayLength'));

        // Table header order by related
        if (Params::getParam('sort') == '') {
            Params::setParam('sort', 'date');
        }
        if (Params::getParam('direction') == '') {
            Params::setParam('direction', 'desc');
        }

        $page = ListPaging::page();

        $params = Params::getParamsAsArray();

        $banRulesDataTable = new BanRulesDataTable();
        $banRulesDataTable->table($params);
        $aData = $banRulesDataTable->getData();

        $pastEnd = ListPaging::pastEnd($aData, (int) $page);
        if ($pastEnd !== null) {
            $this->redirectTo($pastEnd);
        }

        $this->_exportVariableToView('aData', $aData);
        $this->_exportVariableToView('aRawRows', $banRulesDataTable->rawRows());

        $bulk_options = BulkAction::options(
            array(
                'delete_ban_rule' => __('Delete')
            ),
            __('Are you sure you want to %s the selected ban rules?')
        );
        $bulk_options = osc_apply_filter('ban_rule_bulk_filter', $bulk_options);
        $this->_exportVariableToView('bulk_options', $bulk_options);

        //calling the view...
        $this->doView('users/ban.php');
    }

    /**
     * The ban rule editor.
     */
    private function editBanRule(): void
    {
        $ruleId = $this->banRuleRowId();
        if ($ruleId === null) {
            return;
        }
        $this->_exportVariableToView(
            'ban_rule_form',
            BanRuleForm::formVars($ruleId, osc_settings_values(BanRuleForm::register(), $ruleId))
        );
        $this->doView('users/ban_frm.php');
    }

    /**
     * Save the ban rule editor.
     */
    private function editBanRulePost(): void
    {
        osc_csrf_check();
        $ruleId = $this->banRuleRowId();
        if ($ruleId === null) {
            return;
        }
        $this->saveBanRule($ruleId);
    }

    /**
     * The form for a new ban rule.
     */
    private function createBanRule(): void
    {
        $this->_exportVariableToView(
            'ban_rule_form',
            BanRuleForm::formVars(null, osc_settings_values(BanRuleForm::register()))
        );
        $this->doView('users/ban_frm.php');
    }

    /**
     * Create a ban rule from the form.
     */
    private function createBanRulePost(): void
    {
        osc_csrf_check();
        $this->saveBanRule(null);
    }

    /**
     * Delete the selected ban rules.
     */
    private function deleteBanRules(): void
    {
        osc_csrf_check();
        $ruleId   = Params::getParam('id');

        if (!is_array($ruleId)) {
            osc_add_flash_error_message(_m("User id isn't in the correct format"), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=ban');
        }

        BulkAction::apply(
            static function ($id) {
                try {
                    return BanRuleStore::delete((int)$id) > 0;
                } catch (DbException $e) {
                    return false;
                }
            },
            'One ban rule has been deleted',
            '%s ban rules have been deleted',
            _m('No rules have been deleted')
        );
        $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=ban');
    }

    /**
     * Sign in as a user, to see the site as they do.
     */
    private function signInAsUser(): void
    {
        osc_csrf_check();
        $aUser = $this->userManager->findByPrimaryKey(Params::getParam('id'));
        if (!count($aUser)) {
            osc_add_flash_error_message(_m("The user doesn't exist"), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=users');
        }

        osc_web_user_login($aUser);

        osc_run_hook('after_login', $aUser, osc_user_dashboard_url());
        osc_add_flash_ok_message(sprintf(_m('Logged in as %s successfully'), $aUser['s_name']));
        $this->redirectTo(osc_user_dashboard_url());
    }

    /**
     * The users table.
     */
    private function users(): void
    {
        if (Params::getParam('action') != '') {
            osc_run_hook('user_bulk_' . Params::getParam('action'), Params::getParam('id'));
        }

        require_once osc_lib_path() . 'osclass/classes/datatables/UsersDataTable.php';

        // set default iDisplayLength
        ListPaging::rememberedLength();
        $this->_exportVariableToView('iDisplayLength', Params::getParam('iDisplayLength'));

        // Table header order by related
        if (Params::getParam('sort') == '') {
            Params::setParam('sort', 'date');
        }
        if (Params::getParam('direction') == '') {
            Params::setParam('direction', 'desc');
        }

        $page = ListPaging::page();

        $params = Params::getParamsAsArray();

        $usersDataTable = new UsersDataTable();
        $usersDataTable->table($params);
        $aData = $usersDataTable->getData();
        $this->_exportVariableToView('countries', Country::getInstance()->listAll());

        $pastEnd = ListPaging::pastEnd($aData, (int) $page);
        if ($pastEnd !== null) {
            $this->redirectTo($pastEnd);
        }

        $this->_exportVariableToView('aData', $aData);
        $this->_exportVariableToView('withFilters', $usersDataTable->withFilters());
        $this->_exportVariableToView('aRawRows', $usersDataTable->rawRows());

        $bulk_actions = array(
            'activate' => __('Activate'),
            'deactivate' => __('Deactivate'),
            'enable' => __('Unblock'),
            'disable' => __('Block'),
            'delete' => __('Delete')
        );
        if (osc_user_validation_enabled()) {
            $bulk_actions['resend_activation'] = array(
                __('Resend activation'),
                __('Resend the activation to')
            );
        }
        $bulk_options = BulkAction::options(
            $bulk_actions,
            __('Are you sure you want to %s the selected users?')
        );
        $bulk_options = osc_apply_filter('user_bulk_filter', $bulk_options);
        $this->_exportVariableToView('bulk_options', $bulk_options);

        //calling the view...
        $this->doView('users/index.php');
    }
    //hopefully generic...

    /**
     * The ban rule this request is about, or null once the admin has been sent away
     * because it named none. The key is read through Params, so it arrives either on the
     * query string or in the form's own hidden route field, and it is held to a decimal
     * with a row behind it before anything is written. No ownership check is needed here
     * only because every row of t_ban_rule is in scope for a screen only an administrator
     * can reach; a table whose rows belong to individual users needs one, or whoever can
     * reach the screen can name a row that is not theirs.
     *
     * @return int|null
     */
    private function banRuleRowId()
    {
        $requested = Params::getParam('id');
        $id        = is_string($requested) && preg_match('/^[1-9][0-9]*$/', $requested) ? (int)$requested : 0;

        if ($id > 0 && BanRule::getInstance()->findByPrimaryKey($id)) {
            return $id;
        }

        osc_add_flash_error_message(_m('That ban rule no longer exists'), 'admin');
        $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=ban');

        return null;
    }

    /**
     * Store a ban rule through its declaration -- inserting when $id is null and updating
     * the row it names otherwise. A rejected submission is drawn again with the values
     * that were rejected still in it, rather than thrown away with a redirect.
     *
     * @param int|null $id
     *
     * @return void
     */
    private function saveBanRule($id)
    {
        $result = osc_settings_save(BanRuleForm::register(), $id);
        BanRuleStore::forget();

        if ($result['errors'] !== array()) {
            foreach ($result['errors'] as $error) {
                osc_add_flash_error_message($error, 'admin');
            }
            $this->_exportVariableToView('ban_rule_form', BanRuleForm::formVars($id, $result['values']));
            $this->doView('users/ban_frm.php');

            return;
        }

        // An update that changed nothing affects no rows and is still a save: the store
        // throws when a write fails and refuses a key with no row behind it, so there is
        // nothing left for a zero to mean.
        osc_add_flash_ok_message(
            $id === null ? _m('Rule saved correctly') : _m('Rule updated correctly'),
            'admin'
        );
        $this->redirectTo(osc_admin_base_url(true) . '?page=users&action=ban');
    }

    /**
     * Draw the user settings form.
     *
     * @param array|null $values values a rejected save is handing back
     */
    private function drawSettings(?array $values = null): void
    {
        $this->_exportVariableToView('user_form', UserSettingsScreen::formVars($values));
        $this->doView('users/settings.php');
    }

    /**
     * The signed-in admin as core services take them.
     */
    private function actor(): Actor
    {
        return Actor::fromSession(true);
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
        \mindstellar\storage\AvatarUpload::handle((int)$userId, 'admin');
    }

}

/* file end: ./oc-admin/CAdminUsers.php */
