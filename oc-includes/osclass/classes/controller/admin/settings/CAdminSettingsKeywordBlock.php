<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\admin\BulkAction;
use mindstellar\admin\form\CoreSettings;
use mindstellar\admin\form\KeywordBlockSettingsScreen;
use mindstellar\admin\form\SpamSettingsScreen;
use mindstellar\admin\ListPaging;
use mindstellar\database\DbException;
use mindstellar\moderation\KeywordBlockStore;

/**
 * Settings › Security › Spam filter: the Akismet key, then the keyword blocklist
 * (t_keyword_block, KeywordBlock, ItemSpamFilter) with list/add/edit/delete, a comma-list
 * importer, and the `moderation`-section preferences that turn enforcement on.
 *
 * Class CAdminSettingsKeywordBlock
 */
class CAdminSettingsKeywordBlock extends AdminSecBaseModel
{
    /** @var string[] Valid t_keyword_block.s_scope values. */
    private static $validScopes = array('title', 'description', 'all', 'meta');

    /**
     * Boots the admin controller and fires the init_admin_settings_keyword_block hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_keyword_block');
    }

    //Business Layer...
    /**
     * Routes the Spam filter actions: the Akismet key, the list, add/edit/delete, the comma-list import and the enforcement preferences.
     *
     * @return void
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('keyword_block'):
                $this->drawList();
                break;
            case ('akismet_post'):
                osc_csrf_check();

                $result = CoreSettings::attempt(SpamSettingsScreen::registerAkismet());
                if ($result['errors'] !== array()) {
                    $this->drawList($result['values']);
                    break;
                }

                if ($result['updated'] !== 0 && $result['values']['akismetKey'] === '') {
                    osc_add_flash_info_message(_m('Your Akismet key has been cleared'), 'admin');
                } else {
                    osc_add_flash_ok_message(_m('Your Akismet key has been updated'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=keyword_block');
                break;
            case ('keyword_block_add'):
                $this->_exportVariableToView('keyword', null);
                $this->doView('settings/keywordBlock_frm.php');
                break;
            case ('keyword_block_add_post'):
                osc_csrf_check();
                $this->_saveKeywordBlock(null);
                break;
            case ('keyword_block_edit'):
                $keyword = KeywordBlock::getInstance()->findByPrimaryKey(Params::getParam('id'));
                if (empty($keyword)) {
                    osc_add_flash_error_message(_m('That keyword no longer exists'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=keyword_block');
                }
                $this->_exportVariableToView('keyword', $keyword);
                $this->doView('settings/keywordBlock_frm.php');
                break;
            case ('keyword_block_edit_post'):
                osc_csrf_check();
                $this->_saveKeywordBlock(Params::getParamInt('id'));
                break;
            case ('keyword_block_delete'):
                osc_csrf_check();
                $ids = Params::getParam('id');

                if (!is_array($ids)) {
                    osc_add_flash_error_message(_m("Keyword id isn't in the correct format"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=keyword_block');
                }

                BulkAction::apply(
                    static fn (int $id) => KeywordBlockStore::delete($id),
                    'One keyword has been deleted',
                    '%s keywords have been deleted',
                    _m('No keywords have been deleted')
                );
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=keyword_block');
                break;
            case ('keyword_block_import_post'):
                osc_csrf_check();
                $csv   = (string) Params::getParam('import_list');
                $scope = (string) Params::getParam('import_scope');
                if (!in_array($scope, self::$validScopes, true)) {
                    $scope = 'all';
                }

                $inserted = ItemSpamFilter::importCommaList($csv, $scope);

                osc_add_flash_ok_message(sprintf(
                    _mn('%d keyword has been imported', '%d keywords have been imported', $inserted),
                    $inserted
                ), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=keyword_block');
                break;
            case ('keyword_block_prefs_post'):
                osc_csrf_check();

                // The list page around this form is a datatable with its own paging and
                // sorting, so a rejection goes back to it rather than redrawing it. Nothing
                // here can be rejected in practice: four switches and a number that is
                // floored rather than refused.
                if (CoreSettings::attempt(KeywordBlockSettingsScreen::register())['errors'] === array()) {
                    osc_add_flash_ok_message(_m('Moderation settings have been updated'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=keyword_block');
                break;
        }
    }

    /**
     * The Spam filter screen: the Akismet key, the blocklist switches, the importer and the list.
     *
     * @param array<string,mixed>|null $akismetValues what a refused Akismet save was sent
     *
     * @return void
     */
    private function drawList(?array $akismetValues = null)
    {
        ListPaging::rememberedLength();
        $this->_exportVariableToView('iDisplayLength', Params::getParam('iDisplayLength'));

        if (Params::getParam('sort') == '') {
            Params::setParam('sort', 'date');
        }
        if (Params::getParam('direction') == '') {
            Params::setParam('direction', 'desc');
        }

        $page = ListPaging::page();

        $params = Params::getParamsAsArray();

        $keywordBlocksDataTable = new KeywordBlocksDataTable();
        $keywordBlocksDataTable->table($params);
        $aData = $keywordBlocksDataTable->getData();

        $pastEnd = ListPaging::pastEnd($aData, (int) $page);
        if ($pastEnd !== null) {
            $this->redirectTo($pastEnd);
        }

        $this->_exportVariableToView('aData', $aData);
        $this->_exportVariableToView('aRawRows', $keywordBlocksDataTable->rawRows());
        // Exported under the name it has always had as well: a replaced admin theme's
        // own view reads it, and View::_get() answers '' for a key nobody exported --
        // so every switch would draw unticked and the next save would clear them.
        $this->_exportVariableToView('moderation_prefs', $this->moderationPrefs());
        $this->_exportVariableToView('moderation_form', KeywordBlockSettingsScreen::formVars());

        $akismetStatus = SpamSettingsScreen::akismetStatus();
        $this->_exportVariableToView('akismet_status', $akismetStatus);
        $this->_exportVariableToView('akismet_form', SpamSettingsScreen::formVars(
            $akismetStatus,
            $akismetValues === null ? '' : SpamSettingsScreen::PAGE_AKISMET,
            $akismetValues
        )['akismet']);

        $bulk_options = BulkAction::options(
            array(
                'keyword_block_delete' => __('Delete')
            ),
            __('Are you sure you want to %s the selected keywords?')
        );
        $bulk_options = osc_apply_filter('keyword_block_bulk_filter', $bulk_options);
        $this->_exportVariableToView('bulk_options', $bulk_options);

        $this->doView('settings/keywordBlock.php');
    }

    /**
     * Validate and persist a keyword — insert when $id is null, update otherwise.
     * Shared by the add and edit POST actions.
     *
     * @param int|null $id
     *
     * @return void
     */
    private function _saveKeywordBlock($id)
    {
        $keyword   = trim((string) Params::getParam('s_keyword'));
        $bare      = trim(str_replace('*', '', $keyword));
        $scope     = (string) Params::getParam('s_scope');
        $substring = Params::getParam('b_substring') != '';

        if (!in_array($scope, self::$validScopes, true)) {
            $scope = 'all';
        }

        if ($bare === '' || mb_strlen($bare) < ItemSpamFilter::MIN_KEYWORD_LENGTH) {
            osc_add_flash_error_message(
                sprintf(_m('The keyword must be at least %d characters long'), ItemSpamFilter::MIN_KEYWORD_LENGTH),
                'admin'
            );
            $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action='
                . ($id ? 'keyword_block_edit&id=' . $id : 'keyword_block_add'));
        }

        try {
            KeywordBlockStore::save($id ?: null, $bare, $scope, $substring);
            osc_add_flash_ok_message($id ? _m('Keyword updated correctly') : _m('Keyword saved correctly'), 'admin');
        } catch (DbException $e) {
            osc_add_flash_error_message(_m('An error has occurred'), 'admin');
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=keyword_block');
    }

    /**
     * The four moderation switches and the report threshold, as the screen's own view has
     * always been handed them.
     *
     * @return array
     */
    private function moderationPrefs()
    {
        return array(
            'keyword_spam_enabled'      => osc_keyword_block_enabled(),
            'keyword_spam_hard_block'   => osc_keyword_block_hard_block(),
            'report_autoblock'          => osc_report_autoblock_enabled(),
            'report_threshold'          => osc_report_threshold(),
            'enabled_recaptcha_reports' => osc_recaptcha_reports_enabled(),
        );
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsKeywordBlock.php
