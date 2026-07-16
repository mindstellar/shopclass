<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Class CAdminItemComments
 */
class CAdminItemComments extends AdminSecBaseModel
{

    private $itemCommentManager;

    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->itemCommentManager = ItemComment::newInstance();
        osc_run_hook('init_admin_comments');
    }

    //Business Layer...

    /**
     * @return bool|false
     * @throws \Exception
     */
    public function doModel()
    {
        parent::doModel();

        //specific things for this class
        switch ($this->action) {
            case ('bulk_actions'):
                osc_csrf_check();
                $id = Params::getParam('id');
                if ($id) {
                    switch (Params::getParam('bulk_actions')) {
                        case ('delete_all'):
                            $this->itemCommentManager->delete(array(
                                DB_CUSTOM_COND => 'pk_i_id IN (' . implode(', ', $id) . ')'
                            ));
                            foreach ($id as $_id) {
                                $iUpdated = $this->itemCommentManager->delete(array(
                                    'pk_i_id' => $_id
                                ));
                                osc_run_hook('delete_comment', $_id);
                            }
                            osc_add_flash_ok_message(_m('The comments have been deleted'), 'admin');
                            break;
                        case ('activate_all'):
                            foreach ($id as $_id) {
                                $iUpdated = $this->itemCommentManager->update(
                                    array('b_active' => 1),
                                    array('pk_i_id' => $_id)
                                );
                                if ($iUpdated) {
                                    $this->sendCommentActivated($_id);
                                }
                                osc_run_hook('activate_comment', $_id);
                            }
                            osc_add_flash_ok_message(_m('The comments have been approved'), 'admin');
                            break;
                        case ('deactivate_all'):
                            foreach ($id as $_id) {
                                $this->itemCommentManager->update(
                                    array('b_active' => 0),
                                    array('pk_i_id' => $_id)
                                );
                                osc_run_hook('deactivate_comment', $_id);
                            }
                            osc_add_flash_ok_message(_m('The comments have been disapproved'), 'admin');
                            break;
                        case ('enable_all'):
                            foreach ($id as $_id) {
                                $iUpdated = $this->itemCommentManager->update(
                                    array('b_enabled' => 1),
                                    array('pk_i_id' => $_id)
                                );
                                if ($iUpdated) {
                                    $this->sendCommentActivated($_id);
                                }
                                osc_run_hook('enable_comment', $_id);
                            }
                            osc_add_flash_ok_message(_m('The comments have been unblocked'), 'admin');
                            break;
                        case ('disable_all'):
                            foreach ($id as $_id) {
                                $this->itemCommentManager->update(
                                    array('b_enabled' => 0),
                                    array('pk_i_id' => $_id)
                                );
                                osc_run_hook('disable_comment', $_id);
                            }
                            osc_add_flash_ok_message(_m('The comments have been blocked'), 'admin');
                            break;
                        default:
                            if (Params::getParam('bulk_actions') != '') {
                                osc_run_hook('item_bulk_' . Params::getParam('bulk_actions'), Params::getParam('id'));
                            }
                            break;
                    }
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=comments');
                break;
            case ('status'):
                osc_csrf_check();
                $id    = Params::getParam('id');
                $value = Params::getParam('value');

                if (!$id) {
                    return false;
                }
                $id = (int)$id;
                if (!is_numeric($id)) {
                    return false;
                }
                if (!in_array($value, array('ACTIVE', 'INACTIVE', 'ENABLE', 'DISABLE'))) {
                    return false;
                }

                if ($value === 'ACTIVE') {
                    $iUpdated = $this->itemCommentManager->update(
                        array('b_active' => 1),
                        array('pk_i_id' => $id)
                    );
                    if ($iUpdated) {
                        $this->sendCommentActivated($id);
                    }
                    osc_run_hook('activate_comment', $id);
                    osc_add_flash_ok_message(_m('The comment has been approved'), 'admin');
                } elseif ($value === 'INACTIVE') {
                    $iUpdated = $this->itemCommentManager->update(
                        array('b_active' => 0),
                        array('pk_i_id' => $id)
                    );
                    osc_run_hook('deactivate_comment', $id);
                    osc_add_flash_ok_message(_m('The comment has been disapproved'), 'admin');
                } elseif ($value === 'ENABLE') {
                    $iUpdated = $this->itemCommentManager->update(
                        array('b_enabled' => 1),
                        array('pk_i_id' => $id)
                    );
                    osc_run_hook('enable_comment', $id);
                    osc_add_flash_ok_message(_m('The comment has been enabled'), 'admin');
                } elseif ($value === 'DISABLE') {
                    $iUpdated = $this->itemCommentManager->update(
                        array('b_enabled' => 0),
                        array('pk_i_id' => $id)
                    );
                    osc_run_hook('disable_comment', $id);
                    osc_add_flash_ok_message(_m('The comment has been disabled'), 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=comments');
                break;
            case ('comment_edit'):
                $comment = ItemComment::newInstance()->findByPrimaryKey(Params::getParam('id'));

                $this->_exportVariableToView('comment', $comment);
                $this->doView('comments/frm.php');
                break;
            case ('comment_edit_post'):
                osc_csrf_check();

                $msg = '';
                if (!osc_validate_email(Params::getParam('authorEmail'), true)) {
                    $msg .= _m('Email is not correct') . '<br/>';
                }
                if (!osc_validate_text(Params::getParam('body'), 1, true)) {
                    $msg .= _m('Comment is required') . '<br/>';
                }

                if ($msg != '') {
                    osc_add_flash_error_message($msg, 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=comments&action=comment_edit&id='
                        . Params::getParam('id'));
                }

                // Strip markup on write, mirroring the public comment path
                // (ItemActions::add_comment) so stored values stay plain text.
                $this->itemCommentManager->update(
                    array(
                        's_title'        => trim(strip_tags(Params::getParam('title'))),
                        's_body'         => trim(strip_tags(Params::getParam('body'))),
                        's_author_name'  => trim(strip_tags(Params::getParam('authorName'))),
                        's_author_email' => trim(strip_tags(Params::getParam('authorEmail')))
                    ),
                    array(
                        'pk_i_id' => Params::getParam('id')
                    )
                );

                osc_run_hook('edit_comment', Params::getParam('id'));

                osc_add_flash_ok_message(_m('Great! We just updated your comment'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=comments');
                break;
            case ('delete'):
                osc_csrf_check();
                $this->itemCommentManager->deleteByPrimaryKey(Params::getParam('id'));
                osc_add_flash_ok_message(_m('The comment has been deleted'), 'admin');
                osc_run_hook('delete_comment', Params::getParam('id'));
                $this->redirectTo(osc_admin_base_url(true) . '?page=comments');
                break;
            default:
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

                // Table header order by related
                if (Params::getParam('sort') == '') {
                    Params::setParam('sort', 'date');
                }
                if (Params::getParam('direction') == '') {
                    Params::setParam('direction', 'desc');
                }

                $page = (int)Params::getParam('iPage');
                if ($page == 0) {
                    $page = 1;
                }
                Params::setParam('iPage', $page);

                $params = Params::getParamsAsArray();

                $commentsDataTable = new CommentsDataTable();
                $commentsDataTable->table($params);
                $aData = $commentsDataTable->getData();

                if (count($aData['aRows']) == 0 && $page != 1) {
                    $total   = (int)$aData['iTotalDisplayRecords'];
                    $maxPage = ceil($total / (int)$aData['iDisplayLength']);

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
                $this->_exportVariableToView('aRawRows', $commentsDataTable->rawRows());

                $bulk_options = array(
                    array('value' => '', 'data-dialog-content' => '', 'label' => __('Bulk actions')),
                    array(
                        'value'               => 'delete_all',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected comments?'),
                            strtolower(__('Delete'))
                        ),
                        'label'               => __('Delete')
                    ),
                    array(
                        'value'               => 'activate_all',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected comments?'),
                            strtolower(__('Activate'))
                        ),
                        'label'               => __('Activate')
                    ),
                    array(
                        'value'               => 'deactivate_all',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected comments?'),
                            strtolower(__('Deactivate'))
                        ),
                        'label'               => __('Deactivate')
                    ),
                    array(
                        'value'               => 'disable_all',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected comments?'),
                            strtolower(__('Block'))
                        ),
                        'label'               => __('Block')
                    ),
                    array(
                        'value'               => 'enable_all',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected comments?'),
                            strtolower(__('Unblock'))
                        ),
                        'label'               => __('Unblock')
                    )
                );
                $bulk_options = osc_apply_filter('comment_bulk_filter', $bulk_options);
                $this->_exportVariableToView('bulk_options', $bulk_options);

                $this->doView('comments/index.php');
                break;
        }
    }

    //hopefully generic...

    /**
     * @param $commentId
     *
     * @throws \Exception
     */
    public function sendCommentActivated($commentId)
    {
        $aComment = $this->itemCommentManager->findByPrimaryKey($commentId);
        $aItem    = Item::newInstance()->findByPrimaryKey($aComment['fk_i_item_id']);
        View::newInstance()->_exportVariableToView('item', $aItem);

        osc_run_hook('hook_email_comment_validated', $aComment);
    }

}

/* file end: ./oc-admin/CAdminItemComments.php */
