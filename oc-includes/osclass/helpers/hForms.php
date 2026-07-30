<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Forms platform helpers (FORMS.md). A form (a t_meta_group row) is a reusable set
 * of fields that can be placed anywhere — on item categories (the publish/search
 * flow, unchanged) or, via the core.form widget, into any widget area: a static
 * page, a page-builder canvas, a sidebar.
 *
 * Phase 2 is render-only: osc_render_form() paints a form's fields (with sections,
 * conditional logic and cascading, sharing the item form's per-field render), and
 * the core.form widget makes it placeable. Submissions land in Phase 3, so the
 * submit button is disabled until then.
 */

/**
 * Render a form's fields as a standalone <form> anywhere. Reuses the item form's
 * per-field render (FieldForm::renderFieldList) so a field looks and behaves
 * identically to the publish form — conditional logic, cascades and field-type
 * rendering all come along.
 *
 * @param int    $formId
 * @param string $contextType where it is placed ('page' | 'widget' | plugin type)
 * @param int    $contextId   the context's id (page id / widget row id)
 *
 * @return void
 */
function osc_render_form($formId, $contextType = 'widget', $contextId = 0)
{
    $formId = (int)$formId;
    if ($formId <= 0) {
        return;
    }
    $form = FieldGroup::newInstance()->findByPrimaryKey($formId);
    if (empty($form)) {
        return;
    }
    $fields = osc_form_fields($formId, $contextType, $contextId);
    if (empty($fields)) {
        return;
    }

    osc_run_hook('form_render', $form, $contextType, $contextId);

    echo '<form class="osc-form" method="post" action="' . osc_esc_html(osc_base_url(true)) . '">';
    echo '<input type="hidden" name="page" value="form" />';
    echo '<input type="hidden" name="action" value="submit" />';
    echo '<input type="hidden" name="osc_form_id" value="' . $formId . '" />';
    echo '<input type="hidden" name="osc_form_context" value="'
        . osc_esc_html($contextType . ':' . (int)$contextId) . '" />';

    // Honeypot: a field kept off-screen and empty for humans; a bot that fills it
    // marks the submission as spam server-side.
    echo '<div class="osc-form-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;">';
    echo '<label>' . osc_esc_html(__('Leave this field empty'))
        . '<input type="text" name="osc_hp" tabindex="-1" autocomplete="off" value="" /></label>';
    echo '</div>';

    FieldForm::renderFieldList($fields, 'meta_list osc-form-fields');

    echo '<div class="osc-form-actions">';
    echo '<button type="submit" class="btn btn-primary">' . osc_esc_html(__('Submit')) . '</button>';
    echo '</div>';
    echo '</form>';
}


/**
 * Options for the core.form widget's form picker: forms flagged "available as a
 * block" (s_meta.placeable). Returned as [{value,label}] and resolved lazily by
 * the widget config renderer, so newly-created forms appear without re-registering
 * the widget type. An item-only form full of item-meta fields is deliberately not
 * listed here.
 *
 * @return array
 */
function osc_form_widget_options()
{
    $out = array(array('value' => '', 'label' => __('— Select a form —')));
    foreach (FieldGroup::newInstance()->listAll() as $form) {
        $meta = (isset($form['s_meta']) && $form['s_meta'] !== '')
            ? json_decode($form['s_meta'], true) : array();
        if (is_array($meta) && !empty($meta['placeable'])) {
            $out[] = array('value' => (string)$form['pk_i_id'], 'label' => $form['s_name']);
        }
    }

    return $out;
}


/**
 * A form's fields for a placement context — its stored fields (Field::findByGroup)
 * after the `form_fields` filter, so plugins may add/remove/reorder per context.
 * Used by BOTH the render (osc_render_form) and the submit route (CWebForm), so
 * what is rendered and what is validated/stored can never diverge.
 *
 * A plugin adding a field must supply a real t_meta_fields row (its value is stored
 * under fk_i_field_id, which has a FK to t_meta_fields); removal/reorder are free.
 *
 * @param int    $formId
 * @param string $contextType
 * @param int    $contextId
 *
 * @return array
 */
function osc_form_fields($formId, $contextType = 'widget', $contextId = 0)
{
    $fields = Field::newInstance()->findByGroup((int)$formId);
    $form   = FieldGroup::newInstance()->findByPrimaryKey((int)$formId);
    $fields = osc_apply_filter('form_fields', $fields, $form, $contextType, $contextId);

    return is_array($fields) ? $fields : array();
}


/**
 * Register a form placement-context type so its submissions read sensibly in the
 * admin. Thin wrapper over FormContextRegistry::register().
 *
 * @param string $type
 * @param array  $spec
 *
 * @return void
 */
function osc_register_form_context($type, $spec)
{
    \mindstellar\forms\FormContextRegistry::instance()->register($type, $spec);
}


/**
 * Describe a submission's placement context for the admin: ['label' => string,
 * 'url' => ?string]. Never throws.
 *
 * @param string $type
 * @param int    $id
 *
 * @return array
 */
function osc_form_context_display($type, $id)
{
    return \mindstellar\forms\FormContextRegistry::instance()->describe((string)$type, (int)$id);
}


/**
 * Derive a placement context (type, id) from the t_widget row a form is rendered
 * in. A page-builder block lives at location "page.<id>"; anything else is keyed
 * by the widget row id. Phase 3 stamps this pair onto each submission.
 *
 * @param array|null $widgetRow
 *
 * @return array [string $type, int $id]
 */
function osc_form_context_from_widget($widgetRow)
{
    if (is_array($widgetRow)) {
        $location = isset($widgetRow['s_location']) ? (string)$widgetRow['s_location'] : '';
        if (strpos($location, 'page.') === 0) {
            return array('page', (int)substr($location, 5));
        }
        if (!empty($widgetRow['pk_i_id'])) {
            return array('widget', (int)$widgetRow['pk_i_id']);
        }
    }

    return array('widget', 0);
}


/*
 * Core "Form" widget — places a custom form (Listings → Custom forms) into any
 * widget area: a static page, a page-builder canvas, a sidebar. Registered
 * unconditionally so the block always exists once a form is flagged placeable. The
 * form picker lists only placeable forms and is resolved lazily (callable options)
 * so a form created after this registration still appears. Render is delegated to
 * osc_render_form(); an unset/removed form id renders nothing (widget-safety).
 */
osc_register_widget('core.form', array(
    'label'       => 'Form',
    'description' => 'Place a custom form (built in Listings → Custom forms) on this page.',
    'fields'      => array(
        array('name' => 'form_id', 'label' => 'Form', 'type' => 'select', 'options' => 'osc_form_widget_options'),
        array('name' => 'success_text', 'label' => 'Success message', 'type' => 'text'),
    ),
    'render'      => static function ($config, $widgetRow = null) {
        $formId = isset($config['form_id']) ? (int)$config['form_id'] : 0;
        if ($formId <= 0) {
            return;
        }
        list($contextType, $contextId) = osc_form_context_from_widget($widgetRow);
        osc_render_form($formId, $contextType, $contextId);
    },
));


/*
 * Core placement contexts. 'page' resolves to the static page's title + public
 * URL so a page-placed form's submissions link back to where they came from;
 * 'widget' is the generic fallback for a form dropped in a plain widget area.
 * Plugins register their own contexts via osc_register_form_context().
 */
osc_register_form_context('page', array(
    'label'   => 'Page',
    'resolve' => static function ($id) {
        $id     = (int)$id;
        $locale = osc_current_user_locale();
        $page   = Page::newInstance()->findByPrimaryKey($id, $locale);
        $title  = '';
        if (!empty($page['locale'][$locale]['s_title'])) {
            $title = $page['locale'][$locale]['s_title'];
        } elseif (!empty($page['locale'])) {
            $first = reset($page['locale']);
            $title = $first['s_title'] ?? '';
        }
        if ($title === '') {
            $title = 'Page #' . $id;
        }

        return array('label' => $title, 'url' => osc_base_url(true) . '?page=page&id=' . $id);
    },
));

osc_register_form_context('widget', array(
    'label' => 'Widget',
));
