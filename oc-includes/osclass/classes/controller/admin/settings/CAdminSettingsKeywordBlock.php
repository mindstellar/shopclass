<?php if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Admin screens for the keyword blocklist (t_keyword_block, KeywordBlock,
 * ItemSpamFilter): list/add/edit/delete, a comma-list importer, and the four
 * `moderation`-section preferences that turn enforcement on. Modelled on the
 * ban-rule admin (CAdminUsers ban_rule_* actions).
 *
 * Class CAdminSettingsKeywordBlock
 */
class CAdminSettingsKeywordBlock extends AdminSecBaseModel
{
    /** @var string[] Valid t_keyword_block.s_scope values. */
    private static $validScopes = array('title', 'description', 'all', 'meta');

    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_keyword_block');
    }

    //Business Layer...
    public function doModel()
    {
        switch ($this->action) {
            case ('keyword_block'):
                // set default iDisplayLength
                if (Params::getParam('iDisplayLength') != '') {
                    Cookie::newInstance()->push('listing_iDisplayLength', Params::getParam('iDisplayLength'));
                    Cookie::newInstance()->set();
                } elseif (Cookie::newInstance()->get_value('listing_iDisplayLength') != '') {
                    Params::setParam('iDisplayLength', Cookie::newInstance()->get_value('listing_iDisplayLength'));
                } else {
                    Params::setParam('iDisplayLength', 10);
                }
                $this->_exportVariableToView('iDisplayLength', Params::getParam('iDisplayLength'));

                if (Params::getParam('sort') == '') {
                    Params::setParam('sort', 'date');
                }
                if (Params::getParam('direction') == '') {
                    Params::setParam('direction', 'desc');
                }

                $page = (int) Params::getParam('iPage');
                if ($page == 0) {
                    $page = 1;
                }
                Params::setParam('iPage', $page);

                $params = Params::getParamsAsArray();

                $keywordBlocksDataTable = new KeywordBlocksDataTable();
                $keywordBlocksDataTable->table($params);
                $aData = $keywordBlocksDataTable->getData();

                if (count($aData['aRows']) == 0 && $page != 1) {
                    $total   = (int) $aData['iTotalDisplayRecords'];
                    $maxPage = ceil($total / (int) $aData['iDisplayLength']);

                    $url = osc_admin_base_url(true) . '?' . Params::getServerParam('QUERY_STRING', false, false);

                    if ($maxPage == 0) {
                        $url = preg_replace('/&iPage=(\d)+/', '&iPage=1', $url);
                        $this->redirectTo($url);
                    }

                    if ($page > 1) {
                        $url = preg_replace('/&iPage=(\d)+/', '&iPage=' . $maxPage, $url);
                        $this->redirectTo($url);
                    }
                }

                $this->_exportVariableToView('aData', $aData);
                $this->_exportVariableToView('aRawRows', $keywordBlocksDataTable->rawRows());
                $this->_exportVariableToView('moderation_prefs', $this->_moderationPrefs());

                $bulk_options = array(
                    array('value' => '', 'data-dialog-content' => '', 'label' => __('Bulk actions')),
                    array(
                        'value'               => 'keyword_block_delete',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected keywords?'),
                            strtolower(__('Delete'))
                        ),
                        'label'               => __('Delete')
                    )
                );

                $bulk_options = osc_apply_filter('keyword_block_bulk_filter', $bulk_options);
                $this->_exportVariableToView('bulk_options', $bulk_options);

                $this->doView('settings/keywordBlock.php');
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
                $keyword = KeywordBlock::newInstance()->findByPrimaryKey(Params::getParam('id'));
                if (empty($keyword)) {
                    osc_add_flash_error_message(_m('That keyword no longer exists'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=keyword_block');
                }
                $this->_exportVariableToView('keyword', $keyword);
                $this->doView('settings/keywordBlock_frm.php');
                break;
            case ('keyword_block_edit_post'):
                osc_csrf_check();
                $this->_saveKeywordBlock((int) Params::getParam('id'));
                break;
            case ('keyword_block_delete'):
                osc_csrf_check();
                $ids = Params::getParam('id');

                if (!is_array($ids)) {
                    osc_add_flash_error_message(_m("Keyword id isn't in the correct format"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=keyword_block');
                }

                $model    = KeywordBlock::newInstance();
                $iDeleted = 0;
                foreach ($ids as $id) {
                    if ($model->deleteByPrimaryKey((int) $id)) {
                        $iDeleted++;
                    }
                }

                if ($iDeleted == 0) {
                    $msg = _m('No keywords have been deleted');
                } else {
                    $msg = sprintf(
                        _mn('One keyword has been deleted', '%s keywords have been deleted', $iDeleted),
                        $iDeleted
                    );
                }

                osc_add_flash_ok_message($msg, 'admin');
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

                $threshold = (int) Params::getParam('report_threshold');
                if ($threshold < 1) {
                    $threshold = 1;
                }

                osc_set_preference(
                    'keyword_spam_enabled',
                    Params::getParam('keyword_spam_enabled') != '' ? 1 : 0,
                    'moderation',
                    'BOOLEAN'
                );
                osc_set_preference(
                    'keyword_spam_hard_block',
                    Params::getParam('keyword_spam_hard_block') != '' ? 1 : 0,
                    'moderation',
                    'BOOLEAN'
                );
                osc_set_preference(
                    'report_autoblock',
                    Params::getParam('report_autoblock') != '' ? 1 : 0,
                    'moderation',
                    'BOOLEAN'
                );
                osc_set_preference('report_threshold', $threshold, 'moderation', 'INTEGER');

                osc_add_flash_ok_message(_m('Moderation settings have been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=keyword_block');
                break;
        }
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
        $substring = Params::getParam('b_substring') != '' ? 1 : 0;

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

        $values = array(
            's_keyword'   => $bare,
            's_scope'     => $scope,
            'b_substring' => $substring,
            'dt_date'     => date('Y-m-d H:i:s'),
        );

        $model = KeywordBlock::newInstance();
        if ($id) {
            $ok = $model->update($values, array('pk_i_id' => $id));
            $msgOk = _m('Keyword updated correctly');
        } else {
            $ok = $model->insert($values);
            $msgOk = _m('Keyword saved correctly');
        }

        if ($ok) {
            osc_add_flash_ok_message($msgOk, 'admin');
        } else {
            osc_add_flash_error_message(_m('An error has occurred'), 'admin');
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=keyword_block');
    }

    /**
     * The four `moderation`-section preferences the list page's settings form
     * reads and writes.
     *
     * @return array{keyword_spam_enabled:bool,keyword_spam_hard_block:bool,report_autoblock:bool,report_threshold:int}
     */
    private function _moderationPrefs()
    {
        return array(
            'keyword_spam_enabled'    => osc_keyword_block_enabled(),
            'keyword_spam_hard_block' => osc_keyword_block_hard_block(),
            'report_autoblock'        => osc_report_autoblock_enabled(),
            'report_threshold'        => osc_report_threshold(),
        );
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsKeywordBlock.php
