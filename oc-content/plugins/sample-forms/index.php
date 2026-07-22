<?php
/*
Plugin Name: Sample Forms
Plugin URI: https://github.com/mindstellar/shopclass
Description: Reference plugin showing how to extend the Forms platform: register a placement context, add/remove fields per context, veto a submission (spam), amend validation, and react to a stored submission (notification stand-in).
Version: 1.0.0
Author: Mindstellar Community
Author URI: https://mindstellar.com
Short Name: sample-forms
*/

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Install callback. This plugin only registers a context and wires hooks/filters
 * at load time — nothing to install (no tables, preferences, or admin pages).
 *
 * @return void
 */
function sample_forms_install()
{
}

osc_register_plugin(osc_plugin_path(__FILE__), 'sample_forms_install');


/*
 * 1) Register a placement context. When a plugin renders a form in its own surface
 *    with osc_render_form($formId, 'sample_forms.embed', $someId), its submissions
 *    show this label (and link) in Listings -> Form submissions instead of a raw
 *    "sample_forms.embed #id". Guarded so dropping this folder on an older core is
 *    a no-op, not a fatal.
 */
if (function_exists('osc_register_form_context')) {
    osc_register_form_context('sample_forms.embed', array(
        'label'   => 'Sample embed',
        'resolve' => static function ($id) {
            return array(
                'label' => 'Sample embed #' . (int) $id,
                'url'   => null,
            );
        },
    ));
}


/*
 * 2) Add / remove / reorder a placed form's fields per context. The filter runs for
 *    both render and submit (osc_form_fields), so the two never diverge. Removal and
 *    reordering are free; ADDING a field means supplying a real t_meta_fields row,
 *    because a value is stored under fk_i_field_id (FK to t_meta_fields).
 *
 *    This example is inert on a normal form — it only reverses field order for a
 *    form whose slug is literally "sample-reversed" — so it demonstrates the API
 *    without disturbing real forms.
 */
osc_add_filter('form_fields', 'sample_forms_reorder_fields', 10, 4);
function sample_forms_reorder_fields($fields, $form, $contextType, $contextId)
{
    if (is_array($form) && ($form['s_slug'] ?? '') === 'sample-reversed' && is_array($fields)) {
        return array_reverse($fields);
    }

    return $fields;
}


/*
 * 3) Veto a submission — a minimal spam filter. Returning a non-empty string rejects
 *    the submission and shows that message; returning the unchanged value accepts it.
 */
osc_add_filter('form_submit_veto', 'sample_forms_spam_veto', 10, 5);
function sample_forms_spam_veto($veto, $form, $values, $contextType, $contextId)
{
    if ($veto !== '') {
        return $veto; // already vetoed by an earlier filter
    }
    $banned = array('viagra', 'casino', 'loan-offer');
    foreach ((array) $values as $value) {
        if (!is_string($value)) {
            continue;
        }
        foreach ($banned as $word) {
            if (stripos($value, $word) !== false) {
                return __('Your submission was flagged as spam.', 'sample-forms');
            }
        }
    }

    return $veto;
}


/*
 * 4) Amend the validation errors. Here: require a message-like field to be at least
 *    10 characters when present, on top of the core validation. Purely illustrative.
 */
osc_add_filter('form_validation_errors', 'sample_forms_extra_validation', 10, 5);
function sample_forms_extra_validation($errors, $form, $values, $contextType, $contextId)
{
    // (no-op by default; a real plugin would inspect $values and push messages)
    return $errors;
}


/*
 * 5) React to a stored submission — the notification hook. A real plugin would send
 *    an email, push to a CRM, or fire a webhook here. This demo just logs, proving
 *    the submission id and values arrive after a successful, validated store.
 */
osc_add_hook('form_submitted', 'sample_forms_on_submitted', 10, 5);
function sample_forms_on_submitted($submissionId, $form, $values, $contextType, $contextId)
{
    if (defined('OSC_DEBUG') && OSC_DEBUG) {
        error_log(sprintf(
            'sample-forms: submission #%d for form "%s" (%s #%d) with %d value(s)',
            (int) $submissionId,
            is_array($form) ? (string) ($form['s_name'] ?? '') : '',
            (string) $contextType,
            (int) $contextId,
            is_array($values) ? count($values) : 0
        ));
    }
}

/* file end: ./oc-content/plugins/sample-forms/index.php */
