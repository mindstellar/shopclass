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
    'section' => __('Pages'),
    'title'   => __('Pages'),
    'help'    => __('With Shopclass you can create static pages on which information can be stored, '
                    . 'such as "About Us" or "Info" pages. From here you can create, edit or delete your site\'s static pages.'),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=pages&amp;action=add',
            'title' => __('Create page'),
        ),
    ),
));

//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        function orderPage(id, dir) {
            var proc = document.getElementById('datatables_list_processing');
            if (proc) { proc.style.display = ''; }
            fetch("<?php echo osc_admin_base_url(true)?>?page=ajax&action=order_pages&id=" + id + "&order=" + dir + "&<?php echo osc_csrf_token_url(); ?>", {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function () {
                window.location.reload();
            });
        }

        function order_up(id) { orderPage(id, 'up'); }
        function order_down(id) { orderPage(id, 'down'); }

    </script>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

$aData     = __get('aData');
$aRawRows  = __get('aRawRows');
$sort      = Params::getParam('sort');
$direction = Params::getParam('direction');

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];

osc_current_admin_theme_path('parts/header.php');
?>
    <?php osc_admin_page_head(__('Manage pages')); ?>
    <div class="relative">
        <div id="pages-toolbar" class="table-toolbar">
        </div>
        <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="pages"/>
            <?php osc_admin_bulk_actions(array('options' => __get('bulk_options'))); ?>
            <div class="table-contains-actions">
                <table class="table" cellpadding="0" cellspacing="0">
                    <thead>
                    <tr>
                        <?php foreach ($columns as $k => $v) {
                            if ($direction === 'desc') {
                                echo '<th class="col-' . $k . ' ' . ($sort === $k ? ('sorting_desc') : '') . '">' . $v . '</th>';
                            } else {
                                echo '<th class="col-' . $k . ' ' . ($sort === $k ? ('sorting_asc') : '') . '">' . $v . '</th>';
                            }
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
                        <?php osc_admin_table_empty(count($columns), array(
                            'icon'   => 'bi-file-earmark-text',
                            'title'  => __('No pages yet'),
                            'text'   => __('Static pages like "About Us" or "Info" live here.'),
                            'action' => array(
                                'label'   => __('Create page'),
                                'url'     => osc_admin_base_url(true) . '?page=pages&amp;action=add',
                                'variant' => 'primary',
                            ),
                        )); ?>
                    <?php } ?>
                    </tbody>
                </table>
                <div id="table-row-actions"></div> <!-- used for table actions -->
            </div>
        </form>
    </div>
<?php
osc_admin_pagination($aData);
?>
    <?php osc_admin_confirm_dialog(array(
        'id'         => 'deleteModal',
        'method'     => 'get',
        'fields'     => array('page' => 'pages', 'action' => 'delete', 'id' => ''),
        'title'      => __('Delete page'),
        'text'       => __('This permanently removes the page and any link to it in the footer.'),
        'confirm'    => __('Delete'),
        'confirm_id' => 'deleteSubmit',
    )); ?>
<?php osc_admin_bulk_confirm_dialog(); ?>
    <script>
        function delete_dialog(id) {
            var deleteModal = document.getElementById("deleteModal");
            var input = deleteModal.querySelector("input[name='id']");
            if (input) { input.value = id; }
            deleteModal.showModal();
            return false;
        }
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>