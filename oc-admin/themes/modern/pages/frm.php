<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
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

osc_enqueue_script('tiny_mce');
osc_enqueue_script('admin-editor');

$page      = __get('page');
$templates = __get('templates');
// Registered page templates (id => spec). Hide super_admin-gated templates from
// moderators, mirroring the widget picker's capability check.
$registeredTemplates = __get('registeredTemplates');
if (!is_array($registeredTemplates)) {
    $registeredTemplates = array();
}
$registeredTemplates = array_filter($registeredTemplates, static function ($spec) {
    return !(($spec['capability'] ?? 'admin') === 'super_admin' && osc_is_moderator());
});
$meta = array();
if (isset($page['s_meta'])) {
    $meta = json_decode($page['s_meta'], true);
}

$template_selected = (isset($meta['template']) && $meta['template'] != '') ? $meta['template'] : 'default';

// Page-builder state. A page whose saved template is a builder template composes
// its body from widget blocks stored at page.{id}; the canvas needs a saved id
// for the location, so it appears only when editing an existing page.
$pb_saved_template = isset($meta['template']) ? (string)$meta['template'] : '';
$pb_saved_spec     = ($pb_saved_template !== '') ? osc_page_template($pb_saved_template) : null;
$pb_page_id        = isset($page['pk_i_id']) ? (int)$page['pk_i_id'] : 0;
$pb_is_builder     = ($pb_saved_spec !== null && !empty($pb_saved_spec['builder']) && $pb_page_id > 0);
$pb_location       = $pb_is_builder ? ('page.' . $pb_page_id) : '';

/**
 * One value from the page form's add/edit copy, keyed by name.
 *
 * @param string $return One of 'edit', 'title', 'action_frm' or 'btn_text'
 *
 * @return bool|string 'edit' is a bool, the rest are strings
 */
function pageFrmText($return = 'title')
{
    $page = __get('page');
    $text = array();
    if (isset($page['pk_i_id'])) {
        $text['edit']       = true;
        $text['title']      = __('Edit page');
        $text['action_frm'] = 'edit_post';
        // Blocks save on their own; this button saves the page's title, text and settings.
        $text['btn_text']   = __('Save changes');
    } else {
        $text['edit']       = false;
        $text['title']      = __('Add page');
        $text['action_frm'] = 'add_post';
        $text['btn_text']   = __('Add page');
    }

    return $text[$return];
}

/**
 * Filter callback for `admin_title`: prefix the browser title with the form's title.
 *
 * @param string $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf('%s &raquo; %s', pageFrmText('title'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

/**
 * Add the content column offset used by the other admin editors.
 *
 * @return string
 */
function pageFrmRenderOffset()
{
    return 'row-offset';
}

osc_add_filter('render-wrapper', 'pageFrmRenderOffset');

// The editor's content fields: one value per locale, with what was typed winning over
// what is stored, so a rejected save comes back with the submission still in it.
$pageErrors   = __get('editorErrors');
$pageErrors   = is_array($pageErrors) ? $pageErrors : array();
$pageLocales  = array();
$pageTitles   = array();
$pageBodies   = array();
$pageSubmitted = Session::getInstance()->_getForm('aFieldsDescription');
// What was typed wins here too, the way PageForm::internal_name_input_text() reads it.
$pageInternalName = $page['s_internal_name'] ?? '';
if (Session::getInstance()->_getForm('s_internal_name') != '') {
    $pageInternalName = Session::getInstance()->_getForm('s_internal_name');
}
foreach (\mindstellar\admin\ui\Field::siteLocales() as $pageLocale) {
    $code                = $pageLocale['pk_c_code'];
    $pageLocales[$code]  = $pageLocale['s_name'];
    // Named before the filter runs, because the hook reference is generated from these
    // call sites and a plugin author should read what it is given, not how it was found.
    $title               = $pageSubmitted[$code]['s_title'] ?? $page['locale'][$code]['s_title'] ?? '';
    $description         = $pageSubmitted[$code]['s_text'] ?? $page['locale'][$code]['s_text'] ?? '';
    $pageTitles[$code]   = osc_apply_filter('admin_page_title', $title, $page, $pageLocale);
    $pageBodies[$code]   = osc_apply_filter('admin_page_description', $description, $page, $pageLocale);
}

// Editor images go to the media library (unattached, reusable), so the flow works on
// unsaved pages and the same images are pickable elsewhere.
$pageUploadUrl = adminLibraryUploadUrl();

$pageBackUrl = osc_admin_base_url(true) . '?page=pages';
$pageViewUrl = pageFrmText('edit')
    ? osc_base_url(true) . '?page=page&id=' . $page['pk_i_id']
    : '';

$headActions = array();
if ($pageViewUrl !== '') {
    $headActions[] = array(
        'label' => __('View page'),
        'url'   => $pageViewUrl,
        'icon'  => 'bi-box-arrow-up-right',
        'attrs' => array('target' => '_blank', 'rel' => 'noopener'),
    );
}
$headActions[] = array('label' => __('Back to pages'), 'url' => $pageBackUrl, 'variant' => 'dim');

osc_admin_page(array(
    'section' => __('Pages'),
    'heading' => (string) pageFrmText('title'),
    'buttons' => $headActions,
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="adminPageForm">
    <?php
    // The mode class sets the initial view; the template select toggles it live (see the
    // mode script + .page-mode-* CSS). The title always shows; the text editor and the
    // widget canvas swap.
    osc_admin_editor_open(array(
        'id'           => 'item-form',
        'class'        => 'page-editor',
        'page'         => 'pages',
        'action'       => pageFrmText('action_frm'),
        'main_id'      => 'left-side',
        'main_class'   => 'page-mode-' . ($pb_is_builder ? 'builder' : 'classic'),
        'errors'       => $pageErrors,
        'error_labels' => array(
            's_title'         => __('Title'),
            's_text'          => __('Body'),
            's_internal_name' => __('Internal name'),
        ),
        'error_ids'    => array(
            's_title'         => osc_admin_field_id(array('name' => 's_title')),
            's_text'          => osc_admin_field_id(array('name' => 's_text')),
            's_internal_name' => 's_internal_name',
        ),
    ));

    PageForm::primary_input_hidden($page);

    // The posted names are the ones the save has always read; only what draws them is new.
    // No 'required': the rule is that one locale carries a title, not that this one does,
    // and the browser would refuse to submit a page titled in another language.
    osc_admin_field(array(
        'type'           => 'text',
        'name'           => 's_title',
        'translate_name' => '%s#s_title',
        'label'          => __('Title'),
        'layout'         => 'stacked',
        'translate'      => true,
        'locales'        => $pageLocales,
        'value'          => $pageTitles,
        'error'          => $pageErrors['s_title'] ?? '',
        'class'          => 'osc-editor-title',
        'placeholder'    => __('Enter title here'),
    ));

    // A page saved with a builder template shows its blocks as a summary; they are edited
    // beside a live preview on their own screen.
    $pageBlocks = __get('pageBlocks');
    if ($pb_is_builder && is_array($pageBlocks)) {
        $blockCount = (int) $pageBlocks['count'];
        osc_admin_panel_open(__('Blocks'), array(
            'class'   => 'page-blocks-card js-page-widgets',
            'actions' => array(array(
                'label'   => __('Edit blocks'),
                'icon'    => 'bi-pencil',
                'variant' => 'primary',
                'url'     => $pageBlocks['edit_url'],
                'attrs'   => array('id' => 'page-blocks-edit'),
            )),
        ));
        if (!empty($pageBlocks['draft'])) { ?>
            <p class="page-blocks-draft" id="page-blocks-draft" role="status">
                <?php _e('You have unpublished block changes. Open Edit blocks to publish or discard them.'); ?>
            </p>
        <?php }
        if ($blockCount > 0) { ?>
            <div class="page-blocks-summary">
                <p class="page-blocks-line">
                    <strong><?php echo osc_esc_html(sprintf(_n('%d block', '%d blocks', $blockCount), $blockCount)); ?>:</strong>
                    <?php echo osc_esc_html(implode(', ', array_map(static function ($b) {
                        return (string) $b['type'];
                    }, $pageBlocks['blocks']))); ?>
                </p>
                <ul class="page-blocks-chips">
                    <?php foreach ($pageBlocks['blocks'] as $b) { ?>
                        <li>
                            <i class="bi bi-<?php echo osc_esc_html((string) $b['icon'] !== '' ? (string) $b['icon'] : 'filetype-html'); ?>" aria-hidden="true"></i>
                            <span><?php echo osc_esc_html((string) $b['name']); ?></span>
                            <?php if (!empty($b['hidden'])) { ?>
                                <em>(<?php _e('hidden'); ?>)</em>
                            <?php } ?>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        <?php } else { ?>
            <p class="page-blocks-line page-blocks-none"><?php _e('No blocks yet.'); ?></p>
        <?php } ?>
        <p class="page-field-hint">
            <?php _e('Blocks are edited with a live preview. Changes go live when you publish.'); ?>
        </p>
        <?php
        osc_admin_panel_close();
    } else {
        // Shown when the template select is switched to a builder template but the page is
        // not yet saved as one.
        osc_admin_panel_open(__('Blocks'), array('class' => 'page-blocks-card js-page-widgets-hint')); ?>
        <p class="page-field-hint">
            <?php _e('Blocks are edited after you save the page with the Page builder template.'); ?>
        </p>
        <?php
        osc_admin_panel_close();
    }
    // In builder mode the text is edited in the block editor; the hidden editor still posts it.
    echo '<div class="multilang-description">';
    echo '<p class="form-text js-page-text-hint">';
    if ($pb_is_builder && is_array($pageBlocks)) {
        echo osc_esc_html(__('The page text is edited in the block editor, in a Page text block.'))
            . ' <a href="' . osc_esc_html($pageBlocks['edit_url']) . '">' . osc_esc_html(__('Edit blocks')) . '</a>';
    } else {
        echo osc_esc_html(__('With the Page builder, the page text is edited in the block editor after you save.'));
    }
    echo '</p>';
    echo '<div class="js-page-text-editor">';
    osc_admin_field(array(
        'type'           => 'richtext',
        'name'           => 's_text',
        'translate_name' => '%s#s_text',
        'label'          => __('Body'),
        'layout'         => 'stacked',
        'translate'      => true,
        // The title's strip above switches this field too; a second strip could disagree with it.
        'tabs'           => false,
        'locales'        => $pageLocales,
        'value'          => $pageBodies,
        'error'          => $pageErrors['s_text'] ?? '',
        'preset'         => 'full',
        'height'         => 460,
        'media'          => true,
        'upload_url'     => $pageUploadUrl,
    ));
    echo '</div></div>';

    // Plugin fields render full-width here, as they did before the rail existed.
    osc_run_hook('page_meta');

    osc_admin_editor_rail(array('id' => 'right-side'));

    // The pill says the state; the rows say the dates behind it, so neither repeats the other.
    $publishRows = array();
    if (!empty($page['dt_pub_date'])) {
        $publishRows[] = array('label' => __('Added'), 'value' => osc_format_date($page['dt_pub_date']));
    }
    if (!empty($page['dt_mod_date'])) {
        $publishRows[] = array('label' => __('Last saved'), 'value' => osc_format_date($page['dt_mod_date']));
    }
    osc_admin_publish_panel(array(
        'status' => pageFrmText('edit')
            ? array(array('active', __('Published')))
            : array(array('inactive', __('Not saved yet'))),
        'rows'   => $publishRows,
    ));

    osc_admin_panel_open(__('Page settings'));

    if (count($templates) > 0 || count($registeredTemplates) > 0) {
        $templateOptions = array('default' => __('Default template'));
        foreach ($registeredTemplates as $id => $spec) {
            $templateOptions[$id] = $spec['label'];
        }
        foreach ($templates as $template) {
            $templateOptions[$template] = $template;
        }
        echo '<div class="osc-field">';
        echo '<label class="form-label" for="page_template">' . osc_esc_html(__('Page template')) . '</label>';
        osc_admin_select(array(
            'row'     => false,
            'id'      => 'page_template',
            'name'    => 'meta[template]',
            'options' => $templateOptions,
            'value'   => $template_selected,
        ));
        echo '</div>';
    } ?>

    <div class="page-footer-toggle">
        <?php osc_admin_checkbox(array(
            'name'    => 'b_link',
            'id'      => 'b_link',
            'value'   => '1',
            'checked' => !empty($page['b_link']),
            'label'   => __('Show a link in the footer'),
        )); ?>
    </div>
    <div class="page-footer-toggle">
        <?php osc_admin_checkbox(array(
            'name'    => 'meta[hide_title]',
            'id'      => 'meta_hide_title',
            'value'   => '1',
            'checked' => !empty($meta['hide_title']),
            'label'   => __('Hide the page title'),
            'help'    => __('For a page that opens with its own heading. Screen readers and search engines still get the title.'),
        )); ?>
    </div>

    <?php
    // Open on a rejected save, so the message is not behind a closed summary.
    osc_admin_disclosure_open(__('Advanced'), array(
        'summary_hint' => __('Internal name'),
        'open'         => isset($pageErrors['s_internal_name']),
    ));
    // Drawn here rather than through PageForm::internal_name_input_text(), which has no
    // slot for an error; the posted name and the id are the same either way.
    $pageIndelible = isset($page['b_indelible']) && $page['b_indelible'] == 1;
    osc_admin_field(array(
        'type'   => 'text',
        'id'     => 's_internal_name',
        'name'   => 's_internal_name',
        'label'  => __('Internal name') . ' / ' . __('Slug'),
        'layout' => 'stacked',
        'value'  => $pageInternalName,
        'help'   => __('Used to identify this page. Left empty on a new page, it is built from the title.'),
        'error'  => $pageErrors['s_internal_name'] ?? '',
        // Readonly, never disabled: a disabled control posts nothing, and the save refuses
        // a page whose internal name is empty -- so a system page could not be saved at all.
        'attrs'  => $pageIndelible ? array('readonly' => true) : array(),
    ));
    osc_admin_disclosure_close();

    osc_admin_panel_close();

    osc_admin_editor_close(array(
        array('label' => pageFrmText('btn_text'), 'type' => 'submit', 'variant' => 'primary'),
        array('label' => __('Back to pages'), 'url' => $pageBackUrl, 'variant' => 'dim'),
    )); ?>
</div>
<script>
    // Live-switch the editor to match the chosen page template: a builder template
    // shows the widget canvas, anything else shows the text editor. Server-set
    // .page-mode-* avoids a flash; this only maintains it on change.
    (function () {
        var select = document.getElementById('page_template');
        var main   = document.getElementById('left-side');
        if (!select || !main) { return; }
        var builderIds = <?php
            $pb_builder_ids = array();
foreach ($registeredTemplates as $rid => $rspec) {
    if (!empty($rspec['builder'])) {
        $pb_builder_ids[] = $rid;
    }
}
echo json_encode($pb_builder_ids);
?>;
        function sync() {
            var builder = builderIds.indexOf(select.value) !== -1;
            main.classList.toggle('page-mode-builder', builder);
            main.classList.toggle('page-mode-classic', !builder);
        }
        select.addEventListener('change', sync);
        sync();
    })();
</script>
<?php osc_current_admin_theme_path('parts/media-picker.php'); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
