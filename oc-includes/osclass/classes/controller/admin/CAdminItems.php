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

/**
 * Class CAdminItems
 */
use mindstellar\admin\BulkAction;
use mindstellar\admin\form\CoreSettings;
use mindstellar\admin\form\ItemSettingsScreen;
use mindstellar\admin\ListPaging;
use mindstellar\auth\Actor;
use mindstellar\exception\ConflictException;
use mindstellar\exception\RefusedException;
use mindstellar\listing\ListingCounters;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\listing\PhotoService;
use mindstellar\moderation\ListingModeration;

class CAdminItems extends AdminSecBaseModel
{
    use \mindstellar\base\ActionMap;

    //specific for this class
    private Item $itemManager;

    /** Each action and the method that answers it; any other action shows the listings table. */
    private const ACTIONS = array(
        'bulk_actions'   => 'bulkActions',
        'delete'         => 'deleteListings',
        'status'         => 'setStatus',
        'status_premium' => 'setFlag',
        'status_spam'    => 'setFlag',
        'clear_reports'  => 'clearReports',
        'clear_stat'     => 'clearStat',
        'item_edit'      => 'itemEdit',
        'item_edit_post' => 'itemEditPost',
        'deleteResource' => 'deleteResource',
        'post'           => 'newItem',
        'post_item'      => 'newItemPost',
        'settings'       => 'settings',
        'settings_post'  => 'settingsPost',
        'items_reported' => 'reported',
    );

    /**
     * Take the item manager for this request.
     */
    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->itemManager = Item::getInstance();
        osc_run_hook('init_admin_items');
    }

    //Business Layer...

    /**
     * Dispatch the requested listings action: bulk actions, the per-listing status,
     * premium, spam and stat toggles, the edit and post forms, item settings, and the
     * reported-listings screen.
     *
     * @return false|null false when a status action was given nothing usable to act on
     */
    public function doModel()
    {
        parent::doModel();

        if (osc_is_moderator() && ($this->action === 'settings' || $this->action === 'settings_post')) {
            osc_add_flash_error_message(_m("You don't have enough permissions"), 'admin');
            $this->redirectTo(osc_admin_base_url());
        }

        $method = $this->actionMethod('listings');

        return $this->$method() === false ? false : null;
    }

    /**
     * Apply a bulk action to the selected listings.
     */
    private function bulkActions(): void
    {
        osc_csrf_check();
        $moderate = static function (string $action) {
            $moderation = ListingModeration::make();
            $adminId    = (int) osc_logged_admin_id();

            return static function ($id) use ($moderation, $action, $adminId): bool {
                try {
                    return $moderation->apply($action, (int) $id, $adminId, '');
                } catch (RefusedException $e) {
                    return false;
                }
            };
        };
        switch (Params::getParam('bulk_actions')) {
            case 'enable_all':
                BulkAction::apply($moderate('enable'), '%d listing has been enabled', '%d listings have been enabled');
                break;
            case 'disable_all':
                BulkAction::apply($moderate('disable'), '%d listing has been disabled', '%d listings have been disabled');
                break;
            case 'activate_all':
                BulkAction::apply($moderate('activate'), '%d listing has been activated', '%d listings have been activated');
                break;
            case 'deactivate_all':
                BulkAction::apply($moderate('deactivate'), '%d listing has been deactivated', '%d listings have been deactivated');
                break;
            case 'premium_all':
                BulkAction::apply($moderate('premium'), '%d listing has been marked as premium', '%d listings have been marked as premium');
                break;
            case 'depremium_all':
                BulkAction::apply($moderate('unpremium'), '%d listing is no longer premium', '%d listings are no longer premium');
                break;
            case 'spam_all':
                BulkAction::apply($moderate('spam'), '%d listing has been marked as spam', '%d listings have been marked as spam');
                break;
            case 'despam_all':
                BulkAction::apply($moderate('unspam'), '%d listing is no longer marked as spam', '%d listings are no longer marked as spam');
                break;
            case 'delete_all':
                $manager = $this->itemManager;
                BulkAction::apply(
                    static function ($id) use ($manager) {
                        $item = $manager->findByPrimaryKey($id);

                        return $item && (new ListingService())->delete((int) $item['pk_i_id'], (string) $item['s_secret'], Actor::fromSession(true));
                    },
                    '%d listing has been deleted',
                    '%d listings have been deleted'
                );
                break;
            case 'clear_spam_all':
                $this->bulkClearStat('spam', '%d listing has been unmarked as spam', '%d listings have been unmarked as spam');
                break;
            case 'clear_bad_all':
                $this->bulkClearStat('bad', '%d listing has been unmarked as missclassified', '%d listings have been unmarked as missclassified');
                break;
            case 'clear_dupl_all':
                $this->bulkClearStat('duplicated', '%d listing has been unmarked as duplicated', '%d listings have been unmarked as duplicated');
                break;
            case 'clear_expi_all':
                $this->bulkClearStat('expired', '%d listing has been unmarked as expired', '%d listings have been unmarked as expired');
                break;
            case 'clear_offe_all':
                $this->bulkClearStat('offensive', '%d listing has been unmarked as offensive', '%d listings have been unmarked as offensive');
                break;
            case 'clear_all':
                $this->bulkClearStat('all', '%d listing has been unmarked', '%d listings have been unmarked');
                break;
            case 'clear_reports_all':
                // 'clear_all' only resets the raw t_item_stats counters. This also
                // forgets the deduplicated report log, so the reports already on
                // file cannot immediately re-trigger the report-threshold auto-block.
                $manager = $this->itemManager;
                BulkAction::apply(
                    static function ($id) use ($manager) {
                        if (!$manager->findByPrimaryKey($id)) {
                            return false;
                        }
                        ListingCounters::clearAllReports((int) $id);

                        return true;
                    },
                    '%d listing has had its reports cleared',
                    '%d listings have had their reports cleared'
                );
                break;
            default:
                if (Params::getParam('bulk_actions') != '') {
                    osc_run_hook('item_bulk_' . Params::getParam('bulk_actions'), Params::getParam('id'));
                }
                break;
        }
        $this->redirectTo(Params::getServerParam('HTTP_REFERER', false, false));
    }

    /**
     * Delete the selected listings.
     */
    private function deleteListings(): void
    {
        osc_csrf_check();
        $id      = Params::getParam('id');
        $success = false;

        foreach ($id as $i) {
            if ($i) {
                $aItem   = $this->itemManager->findByPrimaryKey($i);
                $success = (new ListingService())->delete((int) $aItem['pk_i_id'], (string) $aItem['s_secret'], Actor::fromSession(true));
            }
        }

        if ($success) {
            osc_add_flash_ok_message(_m('The listing has been deleted'), 'admin');
        } else {
            osc_add_flash_error_message(_m("The listing couldn't be deleted"), 'admin');
        }

        $this->redirectTo(Params::getServerParam('HTTP_REFERER', false, false));
    }

    /**
     * Activate, deactivate, enable or disable one listing.
     *
     * @return false|null false when there was nothing usable to act on
     */
    private function setStatus(): ?bool
    {
        osc_csrf_check();
        $id     = Params::getParamInt('id');
        $value  = Params::getParamString('value');
        $action = array('ACTIVE' => 'activate', 'INACTIVE' => 'deactivate', 'ENABLE' => 'enable', 'DISABLE' => 'disable')[$value] ?? '';
        if ($id <= 0 || $action === '') {
            return false;
        }
        $done = array(
            'activate'   => _m('The listing has been activated'),
            'deactivate' => _m('The listing has been deactivated'),
            'enable'     => _m('The listing has been enabled'),
            'disable'    => _m('The listing has been disabled'),
        );
        $this->moderate($action, $id, $done[$action], _m("The listing can't be activated because it's blocked"));
        $this->redirectTo(Params::getServerParam('HTTP_REFERER', false, false));

        return null;
    }

    /**
     * Mark one listing as premium or spam, or unmark it.
     *
     * @return false|null false when there was nothing usable to act on
     */
    private function setFlag(): ?bool
    {
        osc_csrf_check();
        $id    = Params::getParamInt('id');
        $value = Params::getParamString('value');
        if ($id <= 0 || !in_array($value, array('0', '1'), true)) {
            return false;
        }
        $action = ($value === '1' ? '' : 'un') . ($this->action === 'status_spam' ? 'spam' : 'premium');
        $this->moderate($action, $id, _m('Changes have been applied'), _m('An error has occurred'));
        $this->redirectTo(Params::getServerParam('HTTP_REFERER', false, false));

        return null;
    }

    /**
     * Clear one listing's reports and its report log.
     *
     * @return false|null false when there was nothing usable to act on
     */
    private function clearReports(): ?bool
    {
        // Row-level twin of the 'clear_reports_all' bulk action: resets the raw
        // t_item_stats counters AND forgets the deduplicated report log, so
        // reports already on file cannot immediately re-trigger the
        // report-threshold auto-block.
        osc_csrf_check();
        $id = Params::getParamInt('id');

        if ($id <= 0) {
            return false;
        }

        ListingCounters::clearAllReports($id);

        osc_add_flash_ok_message(_m('Reports have been cleared for this listing'), 'admin');
        $this->redirectTo(Params::getServerParam('HTTP_REFERER', false, false));

        return null;
    }

    /**
     * Clear one kind of report on a listing.
     *
     * @return false|null false when there was nothing usable to act on
     */
    private function clearStat(): ?bool
    {
        osc_csrf_check();
        $id   = Params::getParam('id');
        $stat = Params::getParam('stat');

        if (!$id) {
            return false;
        }

        if (!$stat) {
            return false;
        }

        $id = (int)$id;

        $success = is_string($stat) && ListingCounters::clearReport($id, $stat) > 0;

        if ($success) {
            osc_add_flash_ok_message(_m('The listing has been unmarked as') . " $stat", 'admin');
        } else {
            osc_add_flash_error_message(_m("The listing hasn't been unmarked as") . " $stat", 'admin');
        }

        $this->redirectTo(Params::getServerParam('HTTP_REFERER', false, false));

        return null;
    }

    /**
     * The listing editor.
     */
    private function itemEdit(): void
    {
        $id = Params::getParam('id');

        $item = Item::getInstance()->findByPrimaryKey((int) $id);
        if (count($item) <= 0) {
            $this->redirectTo(osc_admin_base_url(true) . '?page=items');
        }

        $this->exportItemState($item);

        $form     = count(Session::getInstance()->_getForm());
        $keepForm = count(Session::getInstance()->_getKeepForm());

        if ($form == 0 || $form == $keepForm) {
            Session::getInstance()->_dropKeepForm();
        }

        // save referer if belongs to manage items
        // redirect only if ManageItems or ReportedListngs
        if (Params::existServerParam('HTTP_REFERER')) {
            $referer = Params::getServerParam('HTTP_REFERER', false, false);
            if (preg_match('/page=items/', $referer)) {
                if (preg_match("/action=([\p{L}|_|-]+)/u", $referer, $matches)) {
                    if ($matches[1] === 'items_reported') {
                        Session::getInstance()->_set('osc_admin_referer', $referer);
                    }
                } else {
                    // no actions - Manage Listings
                    Session::getInstance()->_set('osc_admin_referer', $referer);
                }
            }
        }

        $this->_exportVariableToView('item', $item);
        $this->_exportVariableToView('new_item', false);

        osc_run_hook('before_item_edit', $item);
        $this->doView('items/frm.php');
    }

    /**
     * Save the listing editor.
     */
    private function itemEditPost(): void
    {
        osc_csrf_check();
        $formData = ListingInput::read(true, false);
        $meta     = Params::getParam('meta');
        ListingInput::keep($formData, $meta);

        $success = $this->saveListing($formData, false);

        // edit() answers with the number of rows it changed, or with the message it
        // refused on. A save that changed nothing affected no rows and is still a
        // save, so only a message is a refusal.
        if (!is_string($success) && $success !== false) {
            osc_add_flash_ok_message(_m('Changes saved correctly'), 'admin');
            $url = osc_admin_base_url(true) . '?page=items';
            // if Referer is saved that means referer is ManageListings or ReportListings
            if (Session::getInstance()->_get('osc_admin_referer') != '') {
                $url = Session::getInstance()->_get('osc_admin_referer');
            }
            Session::getInstance()->_clearVariables();
            ListingInput::dropKept($meta);

            $this->redirectTo($url);
        } else {
            // Drawn again with what was typed still in it, rather than thrown away
            // with a redirect. ListingInput::read() has already put the submission in the
            // session form, which is where the view reads the content fields from.
            osc_add_flash_error_message($success, 'admin');
            $this->drawItemForm(false, $this->itemErrors($success, $formData));

            return;
        }
    }

    /**
     * Delete one of a listing's photos.
     */
    private function deleteResource(): void
    {
        osc_csrf_check();
        $deleted = (new PhotoService())->delete(
            Params::getParamInt('id'),
            Params::getParamInt('fkid'),
            Actor::fromSession(true),
            Params::getParamString('name')
        );
        if (!$deleted) {
            osc_add_flash_error_message(_m('An error has occurred'), 'admin');
        } else {
            osc_add_flash_ok_message(_m('Resource deleted'), 'admin');
        }
        $this->redirectTo(osc_admin_base_url(true) . '?page=items');
    }

    /**
     * The form for a new listing.
     */
    private function newItem(): void
    {
        $form     = count(Session::getInstance()->_getForm());
        $keepForm = count(Session::getInstance()->_getKeepForm());
        if ($form == 0 || $form == $keepForm) {
            Session::getInstance()->_dropKeepForm();
        }

        $this->_exportVariableToView('new_item', true);
        osc_run_hook('post_item');
        $this->doView('items/frm.php');
    }

    /**
     * Save a new listing.
     */
    private function newItemPost(): void
    {
        osc_csrf_check();
        $formData = ListingInput::read(true, true);
        $meta     = Params::getParam('meta');
        ListingInput::keep($formData, $meta);

        $success = $this->saveListing($formData, true);

        if ($success == 1 || $success == 2) {
            $url = osc_admin_base_url(true) . '?page=items';
            // if Referer is saved that means referer is ManageListings or ReportListings
            if (Session::getInstance()->_get('osc_admin_referer') != '') {
                $url = Session::getInstance()->_get('osc_admin_referer');
                Session::getInstance()->_drop('osc_admin_referer');
            }
            Session::getInstance()->_clearVariables();
            ListingInput::dropKept($meta);
            osc_add_flash_ok_message(_m('A new listing has been added'), 'admin');

            $this->redirectTo($url);
        } else {
            osc_add_flash_error_message($success, 'admin');
            $this->drawItemForm(true, $this->itemErrors($success, $formData));

            return;
        }
    }

    /**
     * The listing settings page.
     */
    private function settings(): void
    {
        $this->drawSettings();
    }

    /**
     * Save the listing settings.
     */
    private function settingsPost(): void
    {
        osc_csrf_check();
        $result = CoreSettings::attempt(ItemSettingsScreen::register());
        if ($result['errors'] !== array()) {
            $this->drawSettings($result['values']);
            return;
        }
        if ($result['updated'] > 0) {
            osc_add_flash_ok_message(_m("Listings' settings have been updated"), 'admin');
        }
        $this->redirectTo(osc_admin_base_url(true) . '?page=items&action=settings');
    }

    /**
     * The reported listings.
     */
    private function reported(): void
    {
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

        $itemsDataTable = new ItemsDataTable();
        $itemsDataTable->tableReported($params);
        $aData = $itemsDataTable->getData();

        $pastEnd = ListPaging::pastEnd($aData, (int) $page);
        if ($pastEnd !== null) {
            $this->redirectTo($pastEnd);
        }

        $this->_exportVariableToView('aData', $aData);
        $this->_exportVariableToView('aRawRows', $itemsDataTable->rawRows());

        //calling the view...
        $this->doView('items/reported.php');
    }

    /**
     * The listings table.
     */
    private function listings(): void
    {
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

        $itemsDataTable = new ItemsDataTable();
        $aData          = $itemsDataTable->table($params);

        $pastEnd = ListPaging::pastEnd($aData, (int) $page);
        if ($pastEnd !== null) {
            $this->redirectTo($pastEnd);
        }

        $this->_exportVariableToView('aData', $aData);
        $this->_exportVariableToView('countries', Country::getInstance()->listAll());
        $this->_exportVariableToView('withFilters', $itemsDataTable->withFilters());
        $this->_exportVariableToView('aRawRows', $itemsDataTable->rawRows());

        $bulk_options = BulkAction::options(
            array(
                'delete_all' => __('Delete'),
                'activate_all' => __('Activate'),
                'deactivate_all' => __('Deactivate'),
                'disable_all' => __('Block'),
                'enable_all' => __('Unblock'),
                'premium_all' => __('Mark as premium'),
                'depremium_all' => __('Unmark as premium'),
                'spam_all' => __('Mark as spam'),
                'despam_all' => __('Unmark as spam')
            ),
            __('Are you sure you want to %s the selected listings?')
        );
        $bulk_options = osc_apply_filter('item_bulk_filter', $bulk_options);
        $this->_exportVariableToView('bulk_options', $bulk_options);

        //calling the view...
        $this->doView('items/index.php');
    }

    /**
     * The moderation links the listing editor draws above the form: activate or
     * deactivate, block or unblock, premium and spam. Each is its own CSRF-signed GET, as
     * it has always been; this is only where they are built.
     *
     * @param array<string,mixed> $item
     *
     * @return array<int,string>
     */
    private function itemStateActions(array $item)
    {
        $actions    = array();
        $csrf_token = osc_csrf_token_url();
        if ($item['b_active']) {
            $actions[] = '<a class="btn btn-outline-danger" href="' . osc_admin_base_url(true)
                . '?page=items&amp;action=status&amp;id=' . $item['pk_i_id'] . '&amp;' . $csrf_token
                . '&amp;value=INACTIVE">' . __('Deactivate') . '</a>';
        } else {
            $actions[] = '<a class="btn btn-danger" href="' . osc_admin_base_url(true)
                . '?page=items&amp;action=status&amp;id=' . $item['pk_i_id'] . '&amp;' . $csrf_token
                . '&amp;value=ACTIVE">' . __('Activate') . '</a>';
        }
        if ($item['b_enabled']) {
            $actions[] = '<a class="btn btn-outline-danger" href="' . osc_admin_base_url(true)
                . '?page=items&amp;action=status&amp;id=' . $item['pk_i_id'] . '&amp;' . $csrf_token
                . '&amp;value=DISABLE">' . __('Block') . '</a>';
        } else {
            $actions[] = '<a class="btn btn-danger" href="' . osc_admin_base_url(true)
                . '?page=items&amp;action=status&amp;id=' . $item['pk_i_id'] . '&amp;' . $csrf_token
                . '&amp;value=ENABLE">' . __('Unblock') . '</a>';
        }
        if ($item['b_premium']) {
            $actions[] = '<a class="btn btn-outline-danger" href="' . osc_admin_base_url(true)
                . '?page=items&amp;action=status_premium&amp;id=' . $item['pk_i_id'] . '&amp;' . $csrf_token
                . '&amp;value=0">' . __('Unmark as premium') . '</a>';
        } else {
            $actions[] = '<a class="btn btn-outline-danger" href="' . osc_admin_base_url(true)
                . '?page=items&amp;action=status_premium&amp;id=' . $item['pk_i_id'] . '&amp;' . $csrf_token
                . '&amp;value=1">' . __('Mark as premium') . '</a>';
        }
        if ($item['b_spam']) {
            $actions[] = '<a class="btn btn-danger" href="' . osc_admin_base_url(true)
                . '?page=items&amp;action=status_spam&amp;id=' . $item['pk_i_id'] . '&amp;' . $csrf_token
                . '&amp;value=0">' . __('Unmark as spam') . '</a>';
        } else {
            $actions[] = '<a class="btn btn-outline-danger" href="' . osc_admin_base_url(true)
                . '?page=items&amp;action=status_spam&amp;id=' . $item['pk_i_id'] . '&amp;' . $csrf_token
                . '&amp;value=1">' . __('Mark as spam') . '</a>';
        }

        return $actions;
    }

    /**
     * Hand the listing editor everything it draws about the record's state: the badges, the
     * facts, the moves that change it, and the account it belongs to. The view lays them
     * out; what they are is decided here.
     *
     * The legacy `actions` list of ready-made links is exported alongside, because an admin
     * theme older than the rail still renders it.
     *
     * @param array<string,mixed> $item
     *
     * @return void
     */
    private function exportItemState(array $item)
    {
        $this->_exportVariableToView('actions', $this->itemStateActions($item));
        $this->_exportVariableToView('itemStatus', $this->itemStatePills($item));
        $this->_exportVariableToView('itemActions', $this->itemStateMoves($item));

        $expired = osc_isExpired($item['dt_expiration'] ?? '');
        $this->_exportVariableToView('itemFacts', array(
            'published' => empty($item['dt_pub_date']) ? '' : osc_format_date($item['dt_pub_date']),
            // A listing that never expires carries the year-9999 sentinel, which is a date
            // nobody means to read.
            'expires'   => empty($item['dt_expiration']) || strpos((string)$item['dt_expiration'], '9999') === 0
                ? __('Never')
                : osc_format_date($item['dt_expiration']) . ($expired ? ' (' . __('expired') . ')' : ''),
            'views'     => $item['i_num_views'] ?? null,
        ));

        // The seller is whichever account matches the contact e-mail -- the rule the save
        // applies -- so the editor says so instead of leaving it to be guessed.
        $user = User::getInstance()->findByEmail($item['s_contact_email'] ?? '');
        $this->_exportVariableToView('itemUser', is_array($user) && isset($user['pk_i_id'])
            ? array(
                'id'    => $user['pk_i_id'],
                'name'  => $user['s_name'],
                'email' => $user['s_email'],
                'url'   => osc_admin_base_url(true) . '?page=users&action=edit&id=' . $user['pk_i_id'],
            )
            : null);
    }

    /**
     * The badges the status panel opens with: the state the listing is in, and whatever
     * else is true of it.
     *
     * @param array<string,mixed> $item
     *
     * @return array<int,array<int,string>>
     */
    private function itemStatePills(array $item)
    {
        if (!empty($item['b_spam'])) {
            $pills = array(array('spam', __('Spam')));
        } elseif (empty($item['b_enabled'])) {
            $pills = array(array('blocked', __('Blocked')));
        } elseif (empty($item['b_active'])) {
            $pills = array(array('inactive', __('Inactive')));
        } else {
            $pills = array(array('active', __('Active')));
        }

        if (!empty($item['b_premium'])) {
            $pills[] = array('premium', __('Premium'));
        }
        if (osc_isExpired($item['dt_expiration'] ?? '')) {
            $pills[] = array('expired', __('Expired'));
        }

        return $pills;
    }

    /**
     * The state changes the editor offers, as action specs: the routine ones, and the two
     * that hide a listing, which ask first.
     *
     * @param array<string,mixed> $item
     *
     * @return array{routine: array<int,array<string,mixed>>, danger: array<int,array<string,mixed>>}
     */
    private function itemStateMoves(array $item)
    {
        // Plain ampersands: an action spec's url is escaped where it is drawn.
        $url = static function ($action, $value) use ($item) {
            return osc_admin_base_url(true) . '?page=items&action=' . $action
                . '&id=' . $item['pk_i_id'] . '&' . osc_csrf_token_url()
                . '&value=' . $value;
        };

        $routine = array(
            !empty($item['b_active'])
                ? array('label' => __('Deactivate'), 'url' => $url('status', 'INACTIVE'))
                : array('label' => __('Activate'), 'url' => $url('status', 'ACTIVE')),
            !empty($item['b_premium'])
                ? array('label' => __('Remove premium'), 'url' => $url('status_premium', '0'))
                : array('label' => __('Mark as premium'), 'url' => $url('status_premium', '1')),
        );

        // Only the two that take a listing off the site ask: activating and premium are one
        // click to undo, and a confirm nobody needs is a confirm nobody reads.
        $danger = array(
            !empty($item['b_enabled'])
                ? array(
                    'label' => __('Block listing'),
                    'url'   => $url('status', 'DISABLE'),
                    'attrs' => array(
                        'data-osc-confirm'        => __('The listing is hidden from the site and from'
                            . " the seller's account until it is unblocked."),
                        'data-osc-confirm-title'  => __('Block this listing?'),
                        'data-osc-confirm-label'  => __('Block listing'),
                        'data-osc-confirm-cancel' => __('Cancel'),
                    ),
                )
                : array('label' => __('Unblock listing'), 'url' => $url('status', 'ENABLE')),
            empty($item['b_spam'])
                ? array(
                    'label' => __('Mark as spam'),
                    'url'   => $url('status_spam', '1'),
                    'attrs' => array(
                        'data-osc-confirm'        => __('The listing is hidden from the site and counted'
                            . ' against the seller.'),
                        'data-osc-confirm-title'  => __('Mark this listing as spam?'),
                        'data-osc-confirm-label'  => __('Mark as spam'),
                        'data-osc-confirm-cancel' => __('Cancel'),
                    ),
                )
                : array('label' => __('Unmark as spam'), 'url' => $url('status_spam', '0')),
        );

        return array('routine' => $routine, 'danger' => $danger);
    }

    /**
     * Draw the listing settings form.
     *
     * @param array|null $values values a rejected save is handing back
     */
    private function drawSettings(?array $values = null): void
    {
        $this->_exportVariableToView('item_form', ItemSettingsScreen::formVars($values));
        $this->doView('items/settings.php');
    }

    /**
     * Draw the listing form again over a rejected save, with what was typed still in it
     * rather than thrown away with a redirect.
     *
     * @param bool                    $isNew  The add form, not the edit form
     * @param array<array-key,mixed>  $errors field name => message, or a plain list
     *
     * @return void
     */
    private function drawItemForm($isNew, array $errors)
    {
        $this->_exportVariableToView('editorErrors', $errors);

        if ($isNew) {
            $this->_exportVariableToView('new_item', true);
            osc_run_hook('post_item');
            $this->doView('items/frm.php');

            return;
        }

        $item = Item::getInstance()->findByPrimaryKey(Params::getParamInt('id'));
        $this->exportItemState($item);
        $this->_exportVariableToView('item', $item);
        $this->_exportVariableToView('new_item', false);
        osc_run_hook('before_item_edit', $item);
        $this->doView('items/frm.php');
    }

    /**
     * Save the listing form as the admin: a new listing answers 1 when it waits for validation
     * and 2 otherwise, an edit the rows it changed; a refusal answers with its message.
     *
     * @param array<string,mixed> $data
     *
     * @return int|string
     */
    private function saveListing(array $data, bool $isAdd)
    {
        return ListingService::legacyResult((new ListingService())->saveForm(ListingInput::withMeta($data), Actor::fromSession(true), $isAdd), $isAdd);
    }

    /**
     * What a refused save has to say, split into the summary's lines plus the fields the
     * screen can name. The listing service reports one message with a line per problem, so the
     * lines are what the summary lists; an empty title is named per locale, because the
     * field promises one and the tab strip is where it has to be pointed out.
     *
     * @param string              $message The message the save refused with
     * @param array<string,mixed> $data    The submission, as ListingInput::read() left it
     *
     * @return array<array-key,mixed>
     */
    private function itemErrors($message, array $data)
    {
        $titles    = array();
        $superseded = array();
        foreach (osc_get_locales() as $locale) {
            $code = $locale['pk_c_code'];
            if (trim(strip_tags((string)($data['title'][$code] ?? ''))) === '') {
                $titles[$code]  = sprintf(_m('%s: this listing needs a title'), $locale['s_name']);
                // The same refusal in the validator's own words. Both would be listed, so
                // the summary counted one empty title twice and said so.
                $superseded[] = trim(sprintf(_m('Title too short (%s).'), $code));
            }
        }

        $errors = array();
        foreach (explode(PHP_EOL, (string)$message) as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $superseded, true)) {
                $errors[] = $line;
            }
        }

        if ($titles !== array()) {
            $errors['title'] = $titles;
        }

        return $errors;
    }

    //hopefully generic...

    /**
     * Run one moderation action on a listing and flash the outcome. A listing already in the
     * asked state counts as done.
     */
    private function moderate(string $action, int $id, string $done, string $conflict): void
    {
        try {
            ListingModeration::make()->apply($action, $id, (int) osc_logged_admin_id(), '');
            osc_add_flash_ok_message($done, 'admin');
        } catch (ConflictException $e) {
            osc_add_flash_error_message($conflict, 'admin');
        } catch (RefusedException | RuntimeException $e) {
            osc_add_flash_error_message(_m('An error has occurred'), 'admin');
        }
    }

    /**
     * Clear one moderation counter on the selected listings.
     *
     * Six bulk actions differ only in which counter they reset and what they say afterwards.
     * A listing that no longer exists is not counted: `clearReport()` reports nothing, so the
     * row has to be looked up for the number to mean what it says.
     *
     * @param string $stat
     * @param string $one  singular message, taking the count
     * @param string $many plural message, taking the count
     *
     * @return void
     */
    private function bulkClearStat($stat, $one, $many)
    {
        $manager = $this->itemManager;

        BulkAction::apply(
            static function ($id) use ($manager, $stat) {
                if (!$manager->findByPrimaryKey($id)) {
                    return false;
                }
                ListingCounters::clearReport((int) $id, $stat);

                return true;
            },
            $one,
            $many
        );
    }
}

/* file end: ./oc-admin/CAdminItems.php */
