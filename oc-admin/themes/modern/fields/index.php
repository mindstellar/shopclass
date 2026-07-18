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

osc_enqueue_script('sortablejs');

$fields     = __get('fields');
$categories = __get('categories');
$selected   = __get('default_selected');

function addHelp()
{
    echo '<p>'
         . __('Create new fields for users to fill out when they publish a listing. '
              . 'You can require extra  information such as the number of bedrooms in real estate listings or '
              . 'fuel type in car listings, for example.'
              . 'Reorder fields by dragging and dropping. ')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Custom fields'); ?>
        <a href="#" class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           aria-label="<?php echo osc_esc_html(__('Help')); ?>"></a>
        <a href="#" class="ms-1 float-end" id="add-button"
           title="<?php echo osc_esc_html(__('Add custom field')); ?>"
           aria-label="<?php echo osc_esc_html(__('Add custom field')); ?>"><i class="bi bi-plus-circle-fill"></i></a>
    </h1>
    <?php
}

if (!function_exists('cfields_type_label')) {
    /**
     * Human label for a custom-field storage type.
     *
     * @param string $type
     *
     * @return string
     */
    function cfields_type_label($type)
    {
        $labels = array(
            'TEXT'          => __('Text'),
            'TEXTAREA'      => __('Text area'),
            'DROPDOWN'      => __('Dropdown'),
            'RADIO'         => __('Radio'),
            'CHECKBOX'      => __('Checkbox'),
            'URL'           => __('URL'),
            'DATE'          => __('Date'),
            'DATEINTERVAL'  => __('Date range'),
            'NUMBER'        => __('Number'),
        );
        $type = (string) $type;

        return $labels[$type] ?? $type;
    }
}


osc_add_hook('admin_page_header', 'customPageHeader');
//customize Head
function customHead()
{
    $csrf_token = osc_csrf_token_url(); ?>
    <script type="text/javascript">

        // Inject fetched HTML and run any <script> it carries (innerHTML alone
        // does not execute scripts; the edit iframe wires itself in one).
        function oscInjectHtml(container, html) {
            container.innerHTML = html;
            container.querySelectorAll('script').forEach(function (old) {
                var s = document.createElement('script');
                if (old.src) { s.src = old.src; } else { s.textContent = old.textContent; }
                old.parentNode.replaceChild(s, old);
            });
        }

        function show_iframe(class_name, id) {
            var container = document.querySelector('.' + class_name);
            if (!document.querySelector('.content_list_' + id + ' .custom-field-frame')) {
                document.querySelectorAll('.custom-field-frame').forEach(function (el) { el.remove(); });
                var url = '<?php echo osc_admin_base_url(true); ?>?page=ajax&action=field_categories_iframe&<?php echo $csrf_token; ?>&id=' + id;
                fetch(url, { credentials: 'same-origin' })
                    .then(function (r) { return r.text(); })
                    .then(function (html) { if (container) { oscInjectHtml(container, html); } });
            } else {
                document.querySelectorAll('.custom-field-frame').forEach(function (el) { el.remove(); });
            }
            return false;
        }

        // check all the categories in a subtree
        function checkAll(id, check) {
            var root = document.getElementById(id);
            if (root) {
                root.querySelectorAll('input[type=checkbox]').forEach(function (cb) { cb.checked = check; });
            }
        }

        function checkCat(id, check) {
            var root = document.getElementById('cat' + id);
            if (root) {
                root.querySelectorAll('input[type=checkbox]').forEach(function (cb) { cb.checked = check; });
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('#add-button, .add-button').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=add_field&<?php echo $csrf_token; ?>', { credentials: 'same-origin' })
                        .then(function (r) { return r.text(); })
                        .then(function (res) {
                            var ret;
                            try { ret = JSON.parse(res); } catch (err) { ret = (new Function('return (' + res + ')'))(); }
                            if (ret && ret.error == 0) {
                                // Build the row as DOM so the server field name is set via
                                // textContent and can never inject markup.
                                var li = document.createElement('li');
                                li.id = 'list_' + ret.field_id;
                                li.className = 'field_li';
                                li.setAttribute('data-field-id', ret.field_id);
                                li.innerHTML = `
                                <div class="cfield-div">
                                    <span class="cfield-handle handle" title="<?php echo osc_esc_js(__('Drag to reorder')); ?>" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
                                    <span class="cfield-name" id="quick_edit_${ret.field_id}"></span>
                                    <span class="cfield-type"><?php echo osc_esc_js(__('Text')); ?></span>
                                    <div class="cfield-actions ms-auto">
                                        <button type="button" class="cfield-action"
                                           onclick="show_iframe('content_list_${ret.field_id}','${ret.field_id}'); return false;"
                                           title="<?php echo osc_esc_js(__('Edit')); ?>"><i class="bi bi-pencil-fill" aria-hidden="true"></i></button>
                                        <button type="button" class="cfield-action cfield-action-danger"
                                           onclick="delete_field('${ret.field_id}'); return false;" title="<?php echo osc_esc_js(__('Delete')); ?>"><i class="bi bi-trash-fill" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                                <div class="edit content_list_${ret.field_id}"></div>`;
                                li.querySelector('.cfield-name').textContent = ret.field_name;
                                var empty = document.getElementById('fields-empty');
                                if (empty) { empty.remove(); }
                                document.getElementById('ul_fields').appendChild(li);
                                show_iframe('content_list_' + ret.field_id, ret.field_id);
                            } else {
                                setJsMessage('error', '<?php echo osc_esc_js(__('Custom field could not be added')); ?>');
                            }
                        });
                });
            });
        });
    </script>
    <?php
}


osc_add_hook('admin_header', 'customHead', 10);

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Custom fields &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php');
?>
    <!-- custom fields -->
    <div class="custom-fields">
        <!-- list fields -->
        <div class="list-fields">
            <?php if (count($fields) === 0) { ?>
                <div id="fields-empty" class="cfield-empty">
                    <i class="bi bi-input-cursor-text" aria-hidden="true"></i>
                    <p class="cfield-empty-title"><?php _e('No custom fields yet'); ?></p>
                    <p class="cfield-empty-hint"><?php _e('Add a field to collect extra details when a listing is published — number of bedrooms, fuel type, and so on.'); ?></p>
                    <button type="button" class="btn btn-primary btn-sm add-button"><i class="bi bi-plus-lg" aria-hidden="true"></i> <?php _e('Add custom field'); ?></button>
                </div>
            <?php } ?>
            <ul id="ul_fields" class="sortable">
                <?php foreach ($fields as $field) { ?>
                    <li id="list_<?php echo $field['pk_i_id']; ?>"
                        class="field_li"
                        data-field-id="<?php echo $field['pk_i_id']; ?>">
                        <div class="cfield-div">
                            <span class="cfield-handle handle" title="<?php echo osc_esc_html(__('Drag to reorder')); ?>" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
                            <span class="cfield-name" id="<?php echo 'quick_edit_' . $field['pk_i_id']; ?>"><?php echo osc_esc_html($field['s_name']); ?></span>
                            <span class="cfield-type"><?php echo osc_esc_html(cfields_type_label($field['e_type'])); ?></span>
                            <?php if (!empty($field['b_required'])) { ?>
                                <span class="cfield-flag"><?php _e('Required'); ?></span>
                            <?php } ?>
                            <div class="cfield-actions ms-auto">
                                <button type="button" class="cfield-action"
                                        onclick="show_iframe('content_list_<?php echo $field['pk_i_id']; ?>','<?php echo $field['pk_i_id']; ?>'); return false;"
                                        aria-label="<?php echo osc_esc_html(sprintf(__('Edit %s'), $field['s_name'])); ?>"
                                        title="<?php echo osc_esc_html(__('Edit')); ?>"><i class="bi bi-pencil-fill" aria-hidden="true"></i></button>
                                <button type="button" class="cfield-action cfield-action-danger"
                                        onclick="delete_field('<?php echo $field['pk_i_id']; ?>'); return false;"
                                        aria-label="<?php echo osc_esc_html(sprintf(__('Delete %s'), $field['s_name'])); ?>"
                                        title="<?php echo osc_esc_html(__('Delete')); ?>"><i class="bi bi-trash-fill" aria-hidden="true"></i></button>
                            </div>
                        </div>
                        <div class="edit content_list_<?php echo $field['pk_i_id']; ?>"></div>
                    </li>
                <?php } ?>
            </ul>
        </div>
        <!-- /list fields -->
    </div>
    <!-- /custom fields -->
    <div class="clear"></div>
    <dialog id="deleteModal" class="osc-dialog osc-dialog-danger" data-field-id="">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo __('Delete custom field'); ?>
            </p>
            <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this custom field?'); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="deleteSubmit" type="button" class="btn btn-danger btn-sm"><?php echo __('Delete'); ?></button>
        </div>
    </dialog>
    <script>
        document.getElementById("deleteSubmit").onclick = function() {
            let deleteModal = document.getElementById("deleteModal");
            let fieldId = deleteModal.dataset.fieldId;
            deleteModal.close();
            let url = "<?php
                echo osc_admin_base_url(true); ?>?page=ajax&action=delete_field&<?php echo osc_csrf_token_url();
?>&id=" + fieldId;
            fetch(url, {
                credentials: "same-origin"
            }).then(function(response) {
                    if (!response.ok) {
                        setJsMessage("error", response.statusText);
                    }
                    return response.json()
                })
                .then(function(jsonObj) {
                    if (jsonObj.error) {
                        setJsMessage("error", jsonObj.error);
                    }
                    if (jsonObj.ok) {
                        setJsMessage("ok", jsonObj.ok);
                        document.getElementById('list_' + fieldId).remove()
                    }
                }).catch(function(error) {
                setJsMessage("error", "<?php echo osc_esc_js(__("Ajax error, try again.")); ?>:" + error);
            });
        };

        function delete_field(id) {
            var deleteModal = document.getElementById("deleteModal");
            deleteModal.setAttribute("data-field-id", id);
            deleteModal.showModal();
            return false;
        }

        function orderArray(rootElement) {
            var serialized = [];
            var children = [].slice.call(rootElement.children);
            for (let i = 0; i < children.length; i++) {
                serialized.push(children[i].dataset['fieldId']);
            }
            return serialized
        }

        var orderRoot = document.querySelector('.sortable');
        var oldOrder = orderArray(orderRoot);

        var sortable = new Sortable(document.querySelector('.sortable'), {
            sort: true,
            handle: '.handle',
            ghostClass: 'drag-ghost',
            animation: 150,
            fallbackOnBody: true,
            swapThreshold: 0.10,
            onEnd: function () {
                var newOrder = orderArray(orderRoot);
                if (oldOrder !== newOrder) {
                    var body = new URLSearchParams();
                    body.set('list', JSON.stringify(newOrder));
                    fetch("<?php echo osc_admin_base_url(true) . '?page=ajax&action=fields_order&' . osc_csrf_token_url(); ?>", {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: body
                    }).then(function (r) {
                        return r.text();
                    }).then(function (res) {
                        var ret = JSON.parse(res);
                        if (ret.error) { setJsMessage('error', ret.error); }
                        if (ret.ok) { setJsMessage('ok', ret.ok); }
                    }).catch(function () {
                        setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, please try again.')); ?>');
                    });
                    oldOrder = newOrder;
                }
            }
        });
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>