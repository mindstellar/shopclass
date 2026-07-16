<?php
if (!defined('OC_ADMIN')) {
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

//osc_enqueue_script('jquery-nested');
osc_enqueue_script('sortablejs');
//osc_enqueue_script('tabber');

$categories = __get('categories');

function addHelp()
{
    echo '<p>'
         . __('Add, edit or delete the categories or subcategories in which users can post listings. '
              . 'Reorder sections by dragging and dropping, or nest a subcategory in an expanded category. '
              . '<strong>Be careful</strong>: If you delete a category, all listings associated will also be deleted!')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Categories'); ?>
        <a href="#" class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"></a>
        <a href="<?php echo osc_admin_base_url(true); ?>?page=categories&amp;action=add_post_default&<?php echo osc_csrf_token_url(); ?>"
           class="text-success ms-1 float-end" data-bs-toggle="tooltip" data-bs-placement="bottom" title="<?php _e('Add'); ?>"><i class="bi
           bi-plus-circle-fill"></i></a>
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
    return sprintf(__('Categories &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        function show_iframe(class_name, id) {
            var url;
            if ($('.content_list_' + id + ' .iframe-category').length === 0) {
                $('.iframe-category').remove();
                url = '<?php echo osc_admin_base_url(true); ?>?page=ajax&action=category_edit_iframe&id=' + id;
                $.ajax({
                    url: url,
                    success: function (res) {
                        let element = $('div.' + class_name);
                        element.html(res);
                        element.fadeIn("fast");
                    }
                });
            } else {
                $('.iframe-category').remove();
            }
            return false;
        }

        function enable_cat(id) {
            let url;
            let enabled;
            let jMessage = $(".jsMessage");
            let jMessageP = $(".jsMessage p");
            let category = 'div[category_id=' + id + ']';

            jMessage.fadeIn("fast");
            jMessageP.attr('class', '');
            jMessageP.html('<img height="16" width="16" src="<?php echo osc_current_admin_theme_url('images/loading.gif'); ?>"> <?php echo
            osc_esc_js(__('This action could take a while.')); ?>');

            if ($(category).hasClass('disabled')) {
                enabled = 1;
            } else {
                enabled = 0;
            }

            url = '<?php echo osc_admin_base_url(true); ?>?page=ajax&action=enable_category&<?php echo osc_csrf_token_url(); ?>&id=' + id + '&enabled=' + enabled;
            $.ajax({
                url: url,
                context: document.body,
                success: function (res) {
                    const ret = eval("(" + res + ")");
                    let message = "";

                    if (ret.error) {
                        message += ret.error;
                        jMessageP.attr('class', 'error');
                        jMessageP.html(message);
                    }
                    if (ret.ok) {
                        if (enabled === 0) {
                            $(category).addClass('disabled')
                                .removeClass('enabled')
                                .prop('title', '<?php _e('Enable'); ?>');
                            $(category + ' a i.bi-slash-circle-fill')
                                .removeClass('bi-slash-circle-fill')
                                .addClass('bi-play-circle-fill');

                            for (var i = 0; i < ret.affectedIds.length; i++) {
                                id = ret.affectedIds[i].id;
                                $('div[category_id=' + id + ']').addClass('disabled')
                                    .removeClass('enabled')
                                    .prop('title', '<?php _e('Enable'); ?>');
                                $('div[category_id=' + id + '] a i.bi-slash-circle-fill')
                                    .removeClass('bi-slash-circle-fill')
                                    .addClass('bi-play-circle-fill');
                            }
                        } else {
                            $(category)
                                .removeClass('disabled')
                                .addClass('enabled')
                                .prop('title', '<?php _e('Disable'); ?>');
                            $(category + 'a i.bi-play-circle-fill')
                                .addClass('bi-slash-circle-fill')
                                .removeClass('bi bi-play-circle-fill');

                            for (var i = 0; i < ret.affectedIds.length; i++) {
                                id = ret.affectedIds[i].id;
                                $('div[category_id=' + id + ']')
                                    .removeClass('disabled')
                                    .addClass('enabled')
                                    .prop('title', '<?php _e('Disable'); ?>');
                                $('div[category_id=' + id + '] a i.bi-play-circle-fill')
                                    .addClass('bi-slash-circle-fill')
                                    .removeClass('bi bi-play-circle-fill');
                            }
                        }

                        message += ret.ok;
                        jMessageP.attr('class', 'ok');
                    }

                    jMessage.show();
                    jMessageP.html(message);
                },
                error: function () {
                    jMessage.show();
                    jMessageP.attr('class', '');
                    jMessageP.html("<?php echo osc_esc_js(__('Ajax error, try again.')); ?>");
                }
            });
        }
    </script>
    <?php
}


osc_add_hook('admin_header', 'customHead', 10);

/**
 * @param $category
 */
function drawCategory($category)
{
    if (count($category['categories']) > 0) {
        $has_subcategories = true;
    } else {
        $has_subcategories = false;
    } ?>
    <li data-category-Id="<?php echo $category['pk_i_id']; ?>" id="list_<?php echo $category['pk_i_id']; ?>"
        class="category_li <?php echo($category['b_enabled'] ? 'enabled' : 'disabled'); ?> ">
        <div class="<?php echo($category['b_enabled'] ? 'enabled' : 'disabled'); ?>" category_id="<?php echo $category['pk_i_id'];
        ?>">
            <div class="category-row">
                <div class="px-2 border-end handle"><i class="align-middle bi bi-arrows-move" role="button"></i></div>
                <div class="px-2 border-end<?php echo $has_subcategories ? ' collapsed' : ''; ?>" data-bs-toggle="collapse" data-bs-target="
                .subcategories-<?php echo
                $category['pk_i_id']; ?>">
                    <span class="align-middle toggle bi bi-chevron-down"></span>
                </div>
                <div class="px-2 name-cat" id="<?php echo 'quick_edit_' . $category['pk_i_id']; ?>">
                    <span class="align-middle name"><?php echo osc_esc_html($category['s_name']); ?></span>
                </div>
                <div class="px-2 ms-auto btn-group">
                    <a class="btn btn-sm" onclick="show_iframe('content_list_<?php echo $category['pk_i_id']; ?>','<?php echo
                    $category['pk_i_id']; ?>');" title="<?php _e('Edit'); ?>"><i class="bi bi-pencil-fill"></i></a>
                    <a class="btn btn-sm enable"
                       onclick="enable_cat('<?php echo $category['pk_i_id']; ?>')" <?php
                        if ($category['b_enabled']) {
                            echo 'title="' . __('Disable') . '"><i class="bi bi-slash-circle-fill"></i></a>';
                        } else {
                            echo 'title="' . __('Enable') . '"><i class="bi bi-play-circle-fill"></i></a>';
                        } ?> <a class="btn btn-sm" onclick="delete_category(<?php echo $category['pk_i_id']; ?>)"
                            title="<?php _e('Delete'); ?>">
                        <i class="text-danger bi bi-x-circle-fill"></i>
                    </a>
                </div>
            </div>
            <div class="edit content_list_<?php echo $category['pk_i_id']; ?>"></div>
        </div>

        <?php if ($has_subcategories === true) { ?>
            <ul class="sortable subcategory subcategories-<?php echo $category['pk_i_id']; ?> collapse">
                <?php foreach ($category['categories'] as $subcategory) {
                    drawCategory($subcategory);
                } ?>
            </ul>
        <?php } else { ?>
            <ul class="sortable subcategory subcategories-<?php echo $category['pk_i_id']; ?> collapse show"></ul>
        <?php } ?>

    </li>
    <?php
    unset($has_subcategories);
} //End drawCategory
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>

    <!-- right container -->
    <div class="right">
        <!-- categories form -->
        <div class="categories">
            <div class="callout-info callout-block">
                <div class="info"><?php _e('Drag&drop the categories to reorder them the way you like. Click on edit link to edit the 
            category'); ?></div>
                <div><?php echo __('Note: You must expand the category in order to make it a subcategory.'); ?></div>
            </div>
            <div class="list-categories">
                <ul class="sortable">
                    <?php foreach ($categories as $category) {
                        if (count($category['categories']) > 0) {
                            $has_subcategories = true;
                        } else {
                            $has_subcategories = false;
                        }
                        drawCategory($category);
                    } ?>
                </ul>
            </div>
            <div class="clear"></div>
        </div>
        <!-- /categories form -->
    </div>
    <!-- right container -->
    <form id="deleteModal" method="get" action="<?php echo osc_admin_base_url(true); ?>" class="modal fade static">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo __('Delete category'); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php _e('<strong>WARNING</strong>: This will also delete the listings under that category.'
                             . ' This action cannot be undone. Are you sure you want to continue?');
?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('Cancel'); ?></button>
                    <button id="deleteSubmit" data-bs-dismiss="modal" class="btn btn-sm btn-red" type="submit">
                        <?php echo __('Delete'); ?>
                    </button>
                </div>
            </div>
        </div>
    </form>
    <script>
        function delete_category(id) {
            var deleteModal = document.getElementById('deleteModal');
            deleteModal.setAttribute('data-category-id', id);
            (new bootstrap.Modal(document.getElementById('deleteModal'))).toggle();
            return false;
        }

        document.getElementById("deleteSubmit").onclick = function (e) {
            // prevent other events
            e.preventDefault();
            let categoryId = document.getElementById(
                "deleteModal"
            ).dataset.categoryId;
            let url = "<?php
                echo osc_admin_base_url(true); ?>?page=ajax&action=delete_category&<?php echo osc_csrf_token_url();
?>&id=" + categoryId;
            fetch(url, {
                credentials: "same-origin"
            }).then(function (response) {
                    if (!response.ok) {
                        setJsMessage("error", response.statusText);
                    }
                    return response.json()
                })
                .then(function (jsonObj) {
                    if (jsonObj.error) {
                        setJsMessage("error", jsonObj.error);
                    }
                    if (jsonObj.ok) {
                        setJsMessage("ok", jsonObj.ok);
                        document.getElementById('list_' + categoryId).remove()
                    }
                }).catch(function (error) {
                setJsMessage("error", "<?php echo osc_esc_js(__("Ajax error, try again.")); ?>:" + error);
            });
        };

        var nestedCategoriesRoot = document.querySelector('.sortable');

        function serializeNested(rootElement, parent = null) {
            var serialized = [];
            var children = [].slice.call(rootElement.children);
            for (let i = 0; i < children.length; i++) {
                let childElement = children[i].querySelector('.sortable');
                if (childElement) {
                    serialized.push({
                        c: children[i].dataset['categoryId'],
                        p: parent
                    });
                    serialized = serialized.concat(serializeNested(childElement, children[i].dataset['categoryId']));
                } else {
                    serialized.push({
                        c: children[i].dataset['categoryId'],
                        p: parent
                    });
                }
            }
            return serialized
        }

        var oldNestedList = serializeNested(nestedCategoriesRoot);
        // Nested Categories
        var nestedSortables = [].slice.call(document.querySelectorAll('.sortable'));

        // Loop through each nested sortable element
        for (var i = 0; i < nestedSortables.length; i++) {
            var sortable = new Sortable(nestedSortables[i], {
                sort: true,
                handle: '.handle',
                group: 'nested-categories',
                ghostClass: 'drag-ghost',
                animation: 150,
                fallbackOnBody: true,
                swapThreshold: 0.10,
                onEnd: function () {
                    var newNestedList = serializeNested(nestedCategoriesRoot);
                    if (oldNestedList !== newNestedList) {
                        $.ajax({
                            type: 'POST',
                            url: "<?php echo osc_admin_base_url(true) . '?page=ajax&action=categories_order&' . osc_csrf_token_url(); ?>",
                            data: {
                                'list': JSON.stringify(newNestedList)
                            },
                            success: function (res) {
                                var ret = JSON.parse(res);
                                if (ret.error) {
                                    setJsMessage('error', ret.error);
                                }
                                if (ret.ok) {
                                    setJsMessage('ok', ret.ok);
                                }
                            },
                            error: function () {
                                setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, please try again.')); ?>');
                            }
                        });
                        oldNestedList = newNestedList;
                    }
                }
            });
        }
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>