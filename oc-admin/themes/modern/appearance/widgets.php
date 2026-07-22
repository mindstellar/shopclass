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


$info = __get('info');

function addHelp()
{
    echo '<p>' . __("Modify your site's header or footer here.") . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Appearance'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Appearance &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="appearance-page">
    <div class="appearance">
        <h2 class="render-title"><?php _e('Manage Widgets'); ?> </h2>
    </div>
</div>
</div> <!-- -->
<div class="row g-3">
    <?php if (isset($info['locations']) && is_array($info['locations'])) { ?>
        <?php foreach ($info['locations'] as $location) { ?>
            <div class="col-md-6">
                <div class="row-wrapper">
                    <div class="widget-box">
                        <div class="widget-box-title">
                            <h3><?php printf(__('Section: %s'), $location); ?>
                                <a id="add_widget_<?php echo $location; ?>"
                                   href="<?php echo osc_admin_base_url(true); ?>?page=appearance&amp;action=add_widget&amp;location=<?php echo $location; ?>"
                                   class="btn btn-secondary btn-sm float-end"><?php _e('Add HTML widget'); ?></a></h3>
                        </div>
                        <div class="widget-box-content">
                            <?php $widgets = Widget::newInstance()->findByLocation($location); ?>
                            <?php if (count($widgets) > 0) {
                                $countEvent = 1; ?>
                                <div class="widget-reorder-error alert alert-danger py-1 px-2 small d-none" role="alert"></div>
                                <table class="table" cellpadding="0" cellspacing="0">
                                    <tbody class="js-widget-sortable" data-location="<?php echo osc_esc_html($location); ?>">
                                    <?php foreach ($widgets as $w) { ?>
                                        <tr class="widget-row<?php if ($countEvent % 2 == 0) {
                                            echo ' even';
                                           }
                                           if ($countEvent == 1) {
                                               echo ' table-first-row';
                                           } ?>" data-widget-id="<?php echo (int) $w['pk_i_id']; ?>">
                                            <td class="widget-drag-cell">
                                                <span class="widget-drag-handle" draggable="true" tabindex="0" role="button"
                                                      aria-label="<?php echo osc_esc_html(sprintf(__('Reorder widget %s. Drag, or focus and press the up or down arrow keys.'), $w['s_description'])); ?>"><i
                                                            class="bi bi-grip-vertical" aria-hidden="true"></i></span>
                                                <span class="widget-order-buttons">
                                                    <button type="button" class="btn btn-link btn-sm p-0 widget-move-up"
                                                            aria-label="<?php echo osc_esc_html(__('Move widget up')); ?>"><i
                                                                class="bi bi-chevron-up" aria-hidden="true"></i></button>
                                                    <button type="button" class="btn btn-link btn-sm p-0 widget-move-down"
                                                            aria-label="<?php echo osc_esc_html(__('Move widget down')); ?>"><i
                                                                class="bi bi-chevron-down" aria-hidden="true"></i></button>
                                                </span>
                                            </td>
                                            <td><?php echo $w['s_description'] !== ''
                                                    ? osc_esc_html($w['s_description'])
                                                    : sprintf(__('Widget %d'), (int) $w['pk_i_id']); ?></td>
                                            <td class="text-muted"><?php echo '#' . (int) $w['pk_i_id']; ?></td>
                                            <td><?php printf(
                                                    '<a href="%1$s?page=appearance&amp;action=edit_widget&amp;id=%2$s&amp;location=%3$s">'
                                                    . __('Edit') . '</a>', osc_admin_base_url(true), $w['pk_i_id'],
                                                    $location); ?>
                                                <a href="<?php printf('%s?page=appearance&amp;action=delete_widget&amp;id=%d"',
                                                                      osc_admin_base_url(true), $w['pk_i_id']); ?>"
                                                   onclick="return delete_dialog('<?php echo $w['pk_i_id']; ?>');"><?php _e('Delete'); ?></a>
                                            </td>
                                        </tr>
                                        <?php
                                        $countEvent++;
                                    }
                                    ?>
                                    </tbody>
                                </table>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php } ?>
    <?php } else { ?>
        <div class="col-md-6">
            <div class="row-wrapper">
                <div class="widget-box">
                    <div class="widget-box-title"><h3><?php _e('Current theme does not support widgets'); ?></h3></div>
                    <div class="widget-box-content">
                        <?php _e('Current theme does not support widgets'); ?>
                    </div>
                </div>
            </div>
        </div>
    <?php } ?>
</div>
</div>
</div>
<dialog id="deleteModal" class="osc-dialog osc-dialog-danger">
    <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
        <input type="hidden" name="page" value="appearance"/>
        <input type="hidden" name="action" value="delete_widget"/>
        <input type="hidden" name="id" value=""/>
        <div class="osc-dialog-body">
            <p class="osc-dialog-title">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo __('Delete widget'); ?>
            </p>
            <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this widget?'); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="deleteSubmit" class="btn btn-danger btn-sm" type="submit"><?php echo __('Delete'); ?></button>
        </div>
    </form>
</dialog>
<script type="text/javascript">
    function delete_dialog(id) {
        var deleteModal = document.getElementById('deleteModal');
        var input = deleteModal.querySelector("input[name='id']");
        if (input) { input.value = id; }
        deleteModal.showModal();
        return false;
    }
</script>
<style>
    .widget-drag-cell {
        width: 1%;
        white-space: nowrap;
        vertical-align: middle;
    }

    .widget-drag-handle {
        display: inline-flex;
        align-items: center;
        cursor: grab;
        padding: 0.25rem;
        border-radius: 0.25rem;
    }

    .widget-drag-handle:focus-visible {
        outline: 2px solid var(--bs-primary);
        outline-offset: 1px;
    }

    .widget-order-buttons {
        display: inline-flex;
        flex-direction: column;
        vertical-align: middle;
        margin-left: 0.25rem;
    }

    .widget-order-buttons .btn {
        line-height: 1;
    }

    .widget-row.widget-row-dragging {
        opacity: 0.5;
    }
</style>
<script type="text/javascript">
    (function () {
        'use strict';

        var reorderUrl = <?php
            echo json_encode(
                osc_admin_base_url(true) . '?page=appearance&action=reorder_widgets_post&' . osc_csrf_token_url()
            );
        ?>;
        var errorMessage = <?php echo json_encode(__('Could not save the new widget order. Reloading the page.')); ?>;

        function widgetIdsOf(tbody) {
            return Array.prototype.map.call(
                tbody.querySelectorAll('tr[data-widget-id]'),
                function (tr) { return tr.getAttribute('data-widget-id'); }
            );
        }

        function errorBoxFor(tbody) {
            var content = tbody.closest('.widget-box-content');
            return content ? content.querySelector('.widget-reorder-error') : null;
        }

        function showError(tbody) {
            var box = errorBoxFor(tbody);
            if (box) {
                box.textContent = errorMessage;
                box.classList.remove('d-none');
            }
            window.setTimeout(function () { window.location.reload(); }, 1500);
        }

        function clearError(tbody) {
            var box = errorBoxFor(tbody);
            if (box) {
                box.classList.add('d-none');
                box.textContent = '';
            }
        }

        function commitOrder(tbody) {
            var body = new URLSearchParams();
            body.set('location', tbody.getAttribute('data-location') || '');
            widgetIdsOf(tbody).forEach(function (id) { body.append('ids[]', id); });

            fetch(reorderUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function (response) {
                return response.json();
            }).then(function (json) {
                if (json && !json.error) {
                    clearError(tbody);
                } else {
                    showError(tbody);
                }
            }).catch(function () {
                showError(tbody);
            });
        }

        function moveRow(tr, direction) {
            var sibling = direction < 0 ? tr.previousElementSibling : tr.nextElementSibling;
            if (!sibling) { return; }
            var tbody = tr.parentNode;
            if (direction < 0) {
                tbody.insertBefore(tr, sibling);
            } else {
                tbody.insertBefore(sibling, tr);
            }
            var handle = tr.querySelector('.widget-drag-handle');
            if (handle) { handle.focus(); }
            commitOrder(tbody);
        }

        document.querySelectorAll('.js-widget-sortable').forEach(function (tbody) {
            var draggedRow = null;
            var orderAtDragStart = null;

            tbody.querySelectorAll('.widget-drag-handle').forEach(function (handle) {
                handle.addEventListener('dragstart', function (e) {
                    draggedRow = handle.closest('tr');
                    if (!draggedRow) { return; }
                    orderAtDragStart = widgetIdsOf(tbody);
                    e.dataTransfer.effectAllowed = 'move';
                    try {
                        e.dataTransfer.setData('text/plain', draggedRow.getAttribute('data-widget-id') || '');
                    } catch (err) { /* some browsers require setData to succeed silently */ }
                    draggedRow.classList.add('widget-row-dragging');
                });

                // Persisting on dragend (rather than on the target row's drop event) is
                // deliberate: live-reordering during dragover moves rows in the DOM, which
                // can move the hovered row out from under the pointer and cause the browser
                // to end the drag without ever firing drop. dragend always fires once the
                // gesture ends, and by then the DOM already reflects the final order.
                handle.addEventListener('dragend', function () {
                    if (draggedRow) {
                        draggedRow.classList.remove('widget-row-dragging');
                        var newOrder = widgetIdsOf(tbody);
                        if (orderAtDragStart && newOrder.join(',') !== orderAtDragStart.join(',')) {
                            commitOrder(tbody);
                        }
                    }
                    draggedRow = null;
                    orderAtDragStart = null;
                });

                handle.addEventListener('keydown', function (e) {
                    var tr = handle.closest('tr');
                    if (!tr) { return; }
                    if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        moveRow(tr, -1);
                    } else if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        moveRow(tr, 1);
                    }
                });
            });

            tbody.querySelectorAll('tr[data-widget-id]').forEach(function (tr) {
                tr.addEventListener('dragover', function (e) {
                    if (!draggedRow || draggedRow === tr) { return; }
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    var rect = tr.getBoundingClientRect();
                    var before = (e.clientY - rect.top) < rect.height / 2;
                    tbody.insertBefore(draggedRow, before ? tr : tr.nextElementSibling);
                });

                // No commit here — see the dragend handler above for why.
                tr.addEventListener('drop', function (e) { e.preventDefault(); });
            });

            tbody.querySelectorAll('.widget-move-up').forEach(function (btn) {
                btn.addEventListener('click', function () { moveRow(btn.closest('tr'), -1); });
            });
            tbody.querySelectorAll('.widget-move-down').forEach(function (btn) {
                btn.addEventListener('click', function () { moveRow(btn.closest('tr'), 1); });
            });
        });
    })();
</script>
<div class="row">
    <div class="col-12">
        <div class="row-wrapper">
            <?php osc_current_admin_theme_path('parts/footer.php'); ?>
