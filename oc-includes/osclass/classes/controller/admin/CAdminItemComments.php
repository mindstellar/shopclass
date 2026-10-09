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
 * Class CAdminItemComments
 */
use mindstellar\admin\BulkAction;
use mindstellar\admin\ListPaging;
use mindstellar\moderation\CommentModeration;
use mindstellar\validation\InvalidException;

class CAdminItemComments extends AdminSecBaseModel
{
    use \mindstellar\base\ActionMap;

    /** Each action and the method that answers it; any other action goes to comments(). */
    private const ACTIONS = array(
        'bulk_actions'      => 'bulkActions',
        'status'            => 'setStatus',
        'comment_edit'      => 'editForm',
        'comment_edit_post' => 'editPost',
        'delete'            => 'deleteComment',
    );

    private ItemComment $itemCommentManager;

    private CommentModeration $moderation;

    /**
     * Take the comment manager for this request.
     */
    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->itemCommentManager = ItemComment::getInstance();
        $this->moderation         = new CommentModeration($this->itemCommentManager);
        osc_run_hook('init_admin_comments');
    }

    //Business Layer...

    /**
     * Dispatch the requested comments action: bulk actions, a single status change, the
     * edit form and its save, a delete, otherwise the paginated list.
     *
     * @return false|null false when the status action was given nothing usable to act on
     */
    public function doModel()
    {
        parent::doModel();

        $method = $this->actionMethod('comments');

        return $this->$method() === false ? false : null;
    }

    /**
     * Apply a bulk action to the selected comments.
     */
    private function bulkActions(): void
    {
        osc_csrf_check();
        $moderation = $this->moderation;
        switch (Params::getParam('bulk_actions')) {
            case ('delete_all'):
                BulkAction::apply(
                    static fn ($id) => $moderation->delete((int) $id),
                    '%d comment has been deleted',
                    '%d comments have been deleted'
                );
                break;
            case ('activate_all'):
                BulkAction::apply(
                    static fn ($id) => $moderation->activate((int) $id),
                    '%d comment has been approved',
                    '%d comments have been approved'
                );
                break;
            case ('deactivate_all'):
                BulkAction::apply(
                    static fn ($id) => $moderation->deactivate((int) $id),
                    '%d comment has been disapproved',
                    '%d comments have been disapproved'
                );
                break;
            case ('enable_all'):
                BulkAction::apply(
                    static fn ($id) => $moderation->enable((int) $id),
                    '%d comment has been unblocked',
                    '%d comments have been unblocked'
                );
                break;
            case ('disable_all'):
                BulkAction::apply(
                    static fn ($id) => $moderation->disable((int) $id),
                    '%d comment has been blocked',
                    '%d comments have been blocked'
                );
                break;
            default:
                // Guarded on a selection, as the whole switch used to be: a plugin
                // listening here has never been handed an empty one.
                if (Params::getParam('bulk_actions') != '' && Params::getParam('id')) {
                    osc_run_hook('item_bulk_' . Params::getParam('bulk_actions'), Params::getParam('id'));
                }
                break;
        }
        $this->redirectTo(osc_admin_base_url(true) . '?page=comments');
    }

    /**
     * Activate, deactivate, enable or disable one comment.
     *
     * @return false|null false when there was nothing usable to act on
     */
    private function setStatus(): ?bool
    {
        osc_csrf_check();
        $id    = Params::getParam('id');
        $value = Params::getParam('value');

        if (!$id) {
            return false;
        }
        $id = (int)$id;
        if (!in_array($value, array('ACTIVE', 'INACTIVE', 'ENABLE', 'DISABLE'))) {
            return false;
        }

        if ($value === 'ACTIVE') {
            $this->moderation->activate($id);
            osc_add_flash_ok_message(_m('The comment has been approved'), 'admin');
        } elseif ($value === 'INACTIVE') {
            $this->moderation->deactivate($id);
            osc_add_flash_ok_message(_m('The comment has been disapproved'), 'admin');
        } elseif ($value === 'ENABLE') {
            $this->moderation->enable($id);
            osc_add_flash_ok_message(_m('The comment has been enabled'), 'admin');
        } elseif ($value === 'DISABLE') {
            $this->moderation->disable($id);
            osc_add_flash_ok_message(_m('The comment has been disabled'), 'admin');
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=comments');

        return null;
    }

    /**
     * The comment editor.
     */
    private function editForm(): void
    {
        $comment = ItemComment::getInstance()->findByPrimaryKey(Params::getParam('id'));

        $this->_exportVariableToView('comment', $comment);
        $this->doView('comments/frm.php');
    }

    /**
     * Save the comment editor.
     */
    private function editPost(): void
    {
        osc_csrf_check();
        $id = Params::getParamInt('id');
        try {
            $this->moderation->edit($id, array(
                'title'        => Params::getParamString('title'),
                'body'         => Params::getParamString('body'),
                'author_name'  => Params::getParamString('authorName'),
                'author_email' => Params::getParamString('authorEmail'),
            ));
        } catch (InvalidException $e) {
            osc_add_flash_error_message(implode('<br/>', array_column($e->errors(), 'message')), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=comments&action=comment_edit&id=' . $id);
        }

        osc_add_flash_ok_message(_m('Great! We just updated your comment'), 'admin');
        $this->redirectTo(osc_admin_base_url(true) . '?page=comments');
    }

    /**
     * Delete one comment.
     */
    private function deleteComment(): void
    {
        osc_csrf_check();
        $this->moderation->delete(Params::getParamInt('id'));
        osc_add_flash_ok_message(_m('The comment has been deleted'), 'admin');
        $this->redirectTo(osc_admin_base_url(true) . '?page=comments');
    }

    /**
     * The comments table.
     */
    private function comments(): void
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

        $commentsDataTable = new CommentsDataTable();
        $commentsDataTable->table($params);
        $aData = $commentsDataTable->getData();

        $pastEnd = ListPaging::pastEnd($aData, (int) $page);
        if ($pastEnd !== null) {
            $this->redirectTo($pastEnd);
        }

        $this->_exportVariableToView('aData', $aData);
        $this->_exportVariableToView('aRawRows', $commentsDataTable->rawRows());
        $this->_exportVariableToView('withFilters', $commentsDataTable->withFilters);
        $this->_exportVariableToView('iDisplayLength', Params::getParam('iDisplayLength'));

        $bulk_options = BulkAction::options(
            array(
                'delete_all' => __('Delete'),
                'activate_all' => __('Activate'),
                'deactivate_all' => __('Deactivate'),
                'disable_all' => __('Block'),
                'enable_all' => __('Unblock')
            ),
            __('Are you sure you want to %s the selected comments?')
        );
        $bulk_options = osc_apply_filter('comment_bulk_filter', $bulk_options);
        $this->_exportVariableToView('bulk_options', $bulk_options);

        $this->doView('comments/index.php');
    }
    //hopefully generic...

    /**
     * Fire the hook that emails the comment's author once their comment goes live.
     *
     * @param int|string $commentId
     *
     * @return void
     */
    public function sendCommentActivated($commentId)
    {
        $this->moderation->notifyAuthor((int) $commentId);
    }

}

/* file end: ./oc-admin/CAdminItemComments.php */
