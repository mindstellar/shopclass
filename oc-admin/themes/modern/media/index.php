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
         . __('Manage the images that users have uploaded along with their listings. '
              . 'You can delete them without deleting the whole listing if the image is inappropriate or doesn’t match the listing.')
         . '</p>';
}

osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Manage Media'); ?>
        <a href="<?php echo osc_admin_base_url(true) . '?page=settings&action=media'; ?>"
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
    return sprintf(__('Media &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

$aData     = View::newInstance()->_get('aData');
$aRawRows  = View::newInstance()->_get('aRawRows');
$sort      = Params::getParam('sort');
$direction = Params::getParam('direction');

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <div class="relative">
        <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="media"/>
            <input type="hidden" name="action" value="bulk_actions"/>
            <div id="bulk-actions">
                <div class="input-group input-group-sm">
                    <?php osc_print_bulk_actions(
                        'bulk_actions',
                        'bulk_actions',
                        __get('bulk_options'),
                        'select-box-extra'
                    ); ?>
                    <input type="submit" id="bulk_apply" class="btn btn-primary" value="<?php echo osc_esc_html(__('Apply')); ?>"/>
                </div>
            </div>
            <table class="table" cellpadding="0" cellspacing="0">
                <thead>
                <tr>
                    <?php
                    $create_table_head = static function ($direction, $sort, $class, $value) {
                        if (($direction !== 'desc')) {
                            $direction = 'asc';
                        }
                        if ($sort === $class) {
                            echo '<th class="col-' . $class . ' ' . 'sorting_' . $direction . '">' . $value . '</th>';
                        } else {
                            echo '<th class="col-' . $class . ' ' . '">' . $value . '</th>';
                        }
                    };
                    foreach ($columns as $k => $v) {
                        $create_table_head($direction, $sort, $k, $v);
                    } ?>
                </tr>
                </thead>
                <tbody>
                <?php if (count($rows) > 0) { ?>
                    <?php foreach ($rows as $key => $row) { ?>
                        <tr>
                            <?php foreach ($row as $k => $v) { ?>
                                <td class="col-<?php echo $k; ?>" data-col-name="<?php echo ucfirst($k); ?>"><?php echo $v; ?></td>
                            <?php } ?>
                        </tr>
                    <?php } ?>
                <?php } else { ?>
                    <tr>
                        <td colspan="5" class="text-center">
                            <p><?php _e('No data available in table'); ?></p>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </form>
    </div>
<?php
function showingResults()
{
    $aData = __get('aData');
    echo '<ul class="showing-results"><li><span>' . osc_pagination_showing(
            (Params::getParam('iPage') - 1)
            * $aData['iDisplayLength'] + 1,
            ((Params::getParam('iPage') - 1) * $aData['iDisplayLength']) + count($aData['aRows']),
            $aData['iTotalDisplayRecords'],
            $aData['iTotalRecords']
        ) . '</span></li></ul>';
}


osc_add_hook('before_show_pagination_admin', 'showingResults');
osc_show_pagination_admin($aData);
?>
    <form id="deleteModal" method="get" action="<?php echo osc_admin_base_url(true); ?>"
          class="modal fade static">
        <input type="hidden" name="page" value="media"/>
        <input type="hidden" name="action" value="delete"/>
        <input type="hidden" name="id[]" value=""/>
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php _e('Delete media'); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php _e('Are you sure you want to delete this media file?'); ?>
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
            var deleteModal = document.getElementById("deleteModal")
            deleteModal.querySelector("input[name='id[]']").value = id;
            (new bootstrap.Modal(document.getElementById("deleteModal"))).toggle()
            return false;
        }
    </script>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var checkAll = document.getElementById('check_all');
            if (checkAll) {
                checkAll.addEventListener('change', function () {
                    document.querySelectorAll('.col-bulkactions input').forEach(function (cb) {
                        cb.checked = checkAll.checked;
                    });
                });
            }
        });
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>