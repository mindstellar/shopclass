<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
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

function addHelp()
{
    echo '<p>'
         . __('Manage the comments that users publish on the listings on your site.'
              . ' You can also edit, delete, activate or block comments.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Listing'); ?>
        <a href="<?php echo osc_admin_base_url(true) . '?page=settings&action=comments'; ?>"
           class="ms-1 text-dark float-end" title="<?php _e('Settings'); ?>"><i class="bi bi-gear-fill"></i></a>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


osc_add_hook('admin_page_header', 'customPageHeader');

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Comments &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

$aData     = __get('aData');
$aRawRows  = __get('aRawRows');
$sort      = Params::getParam('sort');
$direction = Params::getParam('direction');

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];

osc_current_admin_theme_path('parts/header.php'); ?>
<h2 class="render-title"><?php _e('Comments'); ?></h2>
<div class="relative">
    <div id="listing-toolbar">
        <div class="float-right">
            <?php if (Params::getParam('showAll') !== 'off') { ?>
                <a href="<?php echo osc_admin_base_url(true) . '?page=comments&showAll=off'; ?>"
                   class="btn btn-sm btn-red"><?php _e('Hidden comments'); ?></a>
            <?php } else { ?>
                <a href="<?php echo osc_admin_base_url(true) . '?page=comments'; ?>"
                   class="btn btn-sm btn-primary"><?php _e('All comments'); ?></a>
            <?php } ?>
        </div>
    </div>
    <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post"
          data-dialog-open="false">
        <input type="hidden" name="page" value="comments"/>
        <input type="hidden" name="action" value="bulk_actions"/>
        <div id="bulk-actions">
            <div class="input-group input-group-sm">
                <?php osc_print_bulk_actions('bulk_actions', 'bulk_actions', __get('bulk_options'),
                                             'select-box-extra'); ?>
                <input type="submit" id="bulk_apply" class="btn btn-primary" value="<?php echo osc_esc_html(__('Apply')); ?>"/>
            </div>
        </div>
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
                        <tr class="<?php echo implode(' ',
                                                      osc_apply_filter('datatable_comment_class', array(), $aRawRows[$key], $row)); ?>">
                            <?php foreach ($row as $k => $v) { ?>
                                <?php // Status becomes a badge. Wrapped here in the theme, not in the DataTable, so the
                                      // `comments_processing_row` filter still hands plugins the plain word. ?>
                                <td class="col-<?php echo $k; ?>" data-col-name="<?php echo ucfirst($k); ?>"><?php
                                    echo $k === 'status' ? '<span class="osc-status">' . $v . '</span>' : $v;
                                ?></td>
                            <?php } ?>
                        </tr>
                    <?php } ?>
                <?php } else { ?>
                    <tr>
                        <td colspan="6" class="text-center">
                            <p><?php _e('No data available in table'); ?></p>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <div id="table-row-actions"></div><!-- used for table actions -->
        </div>
    </form>
</div>
<?php
function showingResults()
{
    $aData = __get('aData');
    echo '<ul class="showing-results"><li><span>'
         . osc_pagination_showing((Params::getParam('iPage') - 1)
                                  * $aData['iDisplayLength'] + 1,
                                  ((Params::getParam('iPage') - 1) * $aData['iDisplayLength'])
                                  + count($aData['aRows']),
                                  $aData['iTotalDisplayRecords'], $aData['iTotalRecords'])
         . '</span></li></ul>';
}


osc_add_hook('before_show_pagination_admin', 'showingResults');
osc_show_pagination_admin($aData);
?>
<form id="deleteModal" method="get" action="<?php echo osc_admin_base_url(true); ?>"
      class="modal fade static">
    <input type="hidden" name="page" value="comments"/>
    <input type="hidden" name="action" value="delete"/>
    <input type="hidden" name="id" value=""/>
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <?php echo __('Delete comment'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php _e('Are you sure you want to delete this comment?'); ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('Cancel'); ?></button>
                <button id="deleteSubmit" class="btn btn-sm btn-red" type="submit">
                    <?php echo __('Delete'); ?>
                </button>
            </div>
        </div>
    </div>
</form>
<div id="bulkActionsModal" class="modal fade static" tabindex="-1" aria-labelledby="bulkActionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkActionsModalLabel"><?php _e('Bulk actions'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('Cancel'); ?></button>
                <button id="bulkActionsSubmit" onclick="bulkActionsSubmit()"
                        class="btn btn-sm btn-red"><?php echo osc_esc_html(__('Delete')); ?></button>
            </div>
        </div>
    </div>
</div>
<script>
    function delete_dialog(id) {
        var deleteModal = document.getElementById('deleteModal')
        deleteModal.querySelector('input[name=\'id[]\']').value = id;
        (new bootstrap.Modal(document.getElementById('deleteModal'))).toggle()
        return false;
    }
</script>
<script type="text/javascript">
    $(document).ready(function () {
        // check_all bulkactions
        $("#check_all").change(function () {
            var isChecked = $(this).prop("checked");
            $('.col-bulkactions input').each(function () {
                if (isChecked == 1) {
                    this.checked = true;
                } else {
                    this.checked = false;
                }
            });
        });
    });
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
