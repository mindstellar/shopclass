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
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
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
    <dialog id="deleteModal" class="osc-dialog osc-dialog-danger">
        <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="media"/>
            <input type="hidden" name="action" value="delete"/>
            <input type="hidden" name="id[]" value=""/>
            <div class="osc-dialog-body">
                <p class="osc-dialog-title">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php _e('Delete media'); ?>
                </p>
                <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this media file?'); ?></p>
            </div>
            <div class="osc-dialog-actions">
                <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                <button id="deleteSubmit" class="btn btn-danger btn-sm" type="submit"><?php echo __('Delete'); ?></button>
            </div>
        </form>
    </dialog>
    <dialog id="bulkActionsModal" class="osc-dialog osc-dialog-danger">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title"><?php _e('Bulk actions'); ?></p>
            <p class="osc-dialog-text"></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="bulkActionsSubmit" onclick="bulkActionsSubmit()" type="button" class="btn btn-danger btn-sm"><?php echo osc_esc_html(__('Delete')); ?></button>
        </div>
    </dialog>
    <script>
        function delete_dialog(id) {
            var deleteModal = document.getElementById("deleteModal");
            var input = deleteModal.querySelector("input[name='id[]'], input[name='id']");
            if (input) { input.value = id; }
            deleteModal.showModal();
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