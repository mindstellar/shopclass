<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_admin_page(array(
    'section' => __('Listings'),
    'title'   => __('Comments'),
    'help'    => __('Manage the comments that users publish on the listings on your site.'
                    . ' You can also edit, delete, activate or block comments.'),
    'actions' => array(
        array(
            'icon'  => 'bi-gear-fill',
            'url'   => osc_admin_base_url(true) . '?page=settings&amp;action=comments',
            'title' => __('Settings'),
        ),
    ),
));

$aData     = __get('aData');
$aRawRows  = __get('aRawRows');
$sort      = Params::getParam('sort');
$direction = Params::getParam('direction');

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];

osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_page_head(__('Comments')); ?>
<div class="relative">
    <div id="listing-toolbar">
        <div class="float-right">
            <?php if (Params::getParam('showAll') !== 'off') { ?>
                <a href="<?php echo osc_admin_base_url(true) . '?page=comments&showAll=off'; ?>"
                   class="btn btn-sm btn-dim"><?php _e('Hidden comments'); ?></a>
            <?php } else { ?>
                <a href="<?php echo osc_admin_base_url(true) . '?page=comments'; ?>"
                   class="btn btn-sm btn-dim"><?php _e('All comments'); ?></a>
            <?php } ?>
        </div>
    </div>
    <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post"
          data-dialog-open="false">
        <input type="hidden" name="page" value="comments"/>
        <input type="hidden" name="action" value="bulk_actions"/>
        <?php osc_admin_bulk_actions(array('name' => 'bulk_actions', 'options' => __get('bulk_options'))); ?>
        <div class="table-contains-actions">
            <table class="table" cellpadding="0" cellspacing="0">
                <thead>
                <tr>
                    <?php foreach ($columns as $k => $v) {
                        if ($direction === 'desc') {
                            echo '<th class="col-' . $k . ' ' . ($sort == $k ? ('sorting_desc') : '') . '">' . $v . '</th>';
                        } else {
                            echo '<th class="col-' . $k . ' ' . ($sort == $k ? ('sorting_asc') : '') . '">' . $v . '</th>';
                        }
                    } ?>
                </tr>
                </thead>
                <tbody>
                <?php if (count($rows) > 0) { ?>
                    <?php foreach ($rows as $key => $row) { ?>
                        <tr class="<?php echo implode(
                            ' ',
                            osc_apply_filter('datatable_comment_class', array(), $aRawRows[$key], $row)
                        ); ?>">
                            <?php foreach ($row as $k => $v) { ?>
                                <?php // Status becomes a badge. Wrapped here in the theme, not in the DataTable, so the
                                          // `comments_processing_row` filter still hands plugins the plain word.?>
                                <td class="col-<?php echo $k; ?>" data-col-name="<?php echo ucfirst($k); ?>"><?php
                                        echo $k === 'status' ? '<span class="osc-status">' . $v . '</span>' : $v;
                                ?></td>
                            <?php } ?>
                        </tr>
                    <?php } ?>
                <?php } else {
                    osc_admin_table_empty(count($columns), array(
                        'icon'  => 'bi-chat-left-text',
                        'title' => __('No comments to moderate'),
                        'text'  => __('Comments visitors leave on listings appear here for approval.'),
                    ));
                } ?>
                </tbody>
            </table>
            <div id="table-row-actions"></div><!-- used for table actions -->
        </div>
    </form>
</div>
<?php
osc_admin_pagination($aData);
?>
<?php osc_admin_confirm_dialog(array(
    'id'      => 'deleteModal',
    'method'  => 'get',
    'fields'  => array('page' => 'comments', 'action' => 'delete', 'id' => ''),
    'title'   => __('Delete comment'),
    'text'    => __('This permanently deletes the comment from the listing. This cannot be undone.'),
    'confirm' => __('Delete'),
)); ?>
<?php osc_admin_bulk_confirm_dialog(); ?>
<script>
    function delete_dialog(id) {
        var deleteModal = document.getElementById('deleteModal');
        var input = deleteModal.querySelector('input[name="id[]"], input[name="id"]');
        if (input) { input.value = id; }
        deleteModal.showModal();
        return false;
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
