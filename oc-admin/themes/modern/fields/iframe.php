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

$field      = __get('field');
$categories = __get('categories');
$selected   = __get('selected');
?>
<!-- custom field frame -->
<div id="edit-custom-field-frame" class="card custom-field-frame">
    <div class="form-horizontal">
        <form id="nedit_field_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="ajax" />
            <input type="hidden" name="action" value="field_categories_post" />
            <?php FieldForm::primary_input_hidden($field); ?>
            <h3 class="card-header"><?php _e('Edit custom field'); ?></h3>
            <fieldset>
                <div class="card-body">
                    <div class="form-row">
                        <?php FieldForm::multiLangTitle($field); ?>
                    </div>
                    <div class="col-md-6">
                        <div class="form-row" id="div_field_options">
                            <div class="form-label"><?php _e('Options'); ?></div>
                            <div class="form-controls">
                                <?php FieldForm::options_input_text($field); ?>
                                <p class="help-inline"><?php _e('Separate options with commas'); ?></p>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-label"><?php _e('Type'); ?></div>
                            <div class="form-controls"><?php FieldForm::type_select($field); ?></div>
                        </div>
                        <div class="form-row">
                            <div class="form-label"></div>
                            <div class="form-controls"><label><?php FieldForm::required_checkbox($field); ?>
                                    <span><?php _e('This field is required'); ?></span></label></div>
                        </div>
                        <div class="form-row">
                            <div><?php _e('Select the categories where you want to apply this attribute:'); ?></div>
                            <div class="separate-top">
                                <div class="form-label">
                                    <a href="javascript:void(0);" onclick="checkAll('cat_tree', true); return false;"><?php _e('Check all'); ?></a>
                                    &middot;
                                    <a href="javascript:void(0);" onclick="checkAll('cat_tree', false); return false;"><?php _e('Uncheck all'); ?></a>
                                </div>
                                <div class="form-controls">
                                    <ul id="cat_tree">
                                        <?php CategoryForm::categories_tree($categories, $selected); ?>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div id="advanced_fields_iframe" class="custom-field-shrink">
                            <span class="icon-more"></span><?php _e('Advanced options'); ?>
                        </div>
                        <div id="more-options_iframe" class="input-line">
                            <div class="form-row" id="div_field_options">
                                <div class="form-label"><?php _e('Identifier name'); ?></div>
                                <div class="form-controls">
                                    <input type="text" class="form-control" name="field_slug" value="<?php echo $field['s_slug']; ?>" />
                                    <p class="help-inline"><?php _e('Only alphanumeric characters are allowed [a-z0-9_-]'); ?></p>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label"></div>
                                <div class="form-controls">
                                    <label><?php FieldForm::searchable_checkbox($field); ?><?php
                                                                                            _e('Tick to allow searches by this field'); ?></label>
                                </div>
                            </div>
                            <div class="form-row" id="field_newtab" style="display: none;">
                                <div class="form-label"></div>
                                <div class="form-controls">
                                    <label><?php FieldForm::newtab_checkbox($field); ?><?php
                                                                                        _e('Tick to open links in new tab'); ?></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer form-actions">
                    <input type="submit" id="cfield_save" value="<?php echo osc_esc_html(__('Save changes')); ?>" class="btn btn-submit" />
                    <input type="button" value="<?php echo osc_esc_html(__('Cancel')); ?>" class="btn btn-dim" onclick="$('#edit-custom-field-frame').remove();" />
                </div>
            </fieldset>
        </form>
    </div>
</div>
<!-- /custom field frame -->
<script type="text/javascript">
    $("#cat_tree").treeview({
        animated: "fast",
        collapsed: true
    });
    var typeInput = $('select[name="field_type"]');
    var optionsDiv = $('#div_field_options');
    var optionsInput = optionsDiv.find('input[name="s_options"]')
    var defaultLocale = '<?php echo osc_current_admin_locale(); ?>';
    var metaNameInputs = $('input[name^="meta_s_name"][name$="]"]');
    var message = '';

    typeInput.change(function() {
        if ($(this).prop('value') === 'DROPDOWN' || $(this).prop('value') === 'RADIO') {
            optionsDiv.show();
        } else {
            optionsDiv.hide();
        }

        ($(this).prop('value') === 'URL') ? $('#field_newtab').show(): $('#field_newtab').hide();
    });

    typeInput.change();

    $('#edit-custom-field-frame form').submit(function() {
        // meta_s_name with default locale is required
        if (metaNameInputs.filter('[name="meta_s_name[' + defaultLocale + ']"]').val() === '') {
            message += '<?php echo osc_esc_js(__('Name for default locale is required.')); ?>';
        }
        if (typeInput.prop('value') === 'DROPDOWN' || typeInput.prop('value') === 'RADIO') {
            // s_options input must be filled
            if (optionsInput.val() === '') {
                message += '<?php echo osc_esc_js(__('Options are required.')); ?>';
            }
        } else {
            // clear all options input values
            optionsInput.val('');
        }
        // if message is not '' then set jsMessage and return false
        if (message !== '') {
            $(".jsMessage").fadeIn('fast');
            $(".jsMessage p").html(message);
            return false;
        }

        $.ajax({
            type: 'POST',
            url: '<?php echo osc_admin_base_url(true); ?>',
            data: $(this).serialize(),
            // Mostramos un mensaje con la respuesta de PHP
            success: function(data) {
                var ret = eval("(" + data + ")");

                var message = "";
                if (ret.error) {
                    message += ret.error;
                }
                if (ret.ok) {
                    $('#settings_form').fadeOut('fast', function() {
                        $('#settings_form').remove();
                    });
                    message += ret.ok;
                    $('#quick_edit_' + ret.field_id).html(ret.text);
                }

                $(".jsMessage").fadeIn('fast');
                $(".jsMessage p").html(message);
                $('div.content_list_<?php echo $field['pk_i_id']; ?>').html('');
            },
            error: function() {
                $(".jsMessage").fadeIn('fast');
                $(".jsMessage p").html('<?php echo osc_esc_js(__('Ajax error, try again.')); ?>');
            }

        })
        return false;
    });

    $('#advanced_fields_iframe').bind('click', function() {
        $('#more-options_iframe').toggle();
        if ($(this).hasClass('custom-field-shrink')) {
            $(this).removeClass('custom-field-shrink');
            $(this).addClass('custom-field-expanded');
        } else {
            $(this).addClass('custom-field-shrink');
            $(this).removeClass('custom-field-expanded');
        }
    });
    $('#more-options_iframe').hide();
</script>