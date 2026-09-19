<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Admin form field primitives.
 *
 * The admin theme has had a component vocabulary for a while, but it lives inside the
 * theme -- so a plugin cannot call it without risking a fatal on an install running a
 * different admin theme, and there was never a primitive for a text input at all. Every
 * settings screen, core and plugin alike, therefore hand-wrote `<input class="…">` against
 * our class names, which is how those class names became an API we never chose to publish.
 *
 * These live in core so a plugin can rely on them existing. They emit the classes the
 * admin theme already styles, plus the `field-*` classes added alongside them, so a screen
 * built from them is indistinguishable from a correct hand-built one.
 *
 * Two things they enforce that hand-written markup cannot:
 *
 *   A suffix is a slot, not concatenation. `'suffix' => __('comments per page')` is a
 *       declared property of the field, so nothing can separate it from its input and the
 *       translator gets two whole strings instead of one sentence split around an opaque
 *       %s they cannot move the field within.
 *   Width comes from the field's type, not the page. Three widths tied to meaning --
 *       field-text (prose), field-num (a number), field-key (a long opaque token) -- plus
 *       intrinsic sizing for choice groups.
 *
 * ESCAPING CONTRACT, the same one parts/ui.php states: every value is escaped by the
 * function that prints it, except keys whose name ends in `_html`. If you add a parameter
 * that must accept markup, name it `*_html` -- the suffix is the whole warning system.
 *
 * LOAD ORDER IS LOAD-BEARING. oc-load.php requires this file *after* the admin theme's
 * functions.php, so a theme shipping its own osc_admin_text() still wins and core only
 * fills the gaps. Move the require up with the other helpers and core silently takes over
 * every theme's copies.
 */

if (!function_exists('osc_admin_field')) {
    /**
     * Render one labelled field. Every other function here is sugar over this one, so this
     * is the only new name a plugin has to depend on.
     *
     * Keys, all optional but `name`:
     *   'type'      => text|email|url|tel|number|color|file|select|textarea|radio|checkbox|secret|custom
     *   'name'      => request/preference key
     *   'label'     => the label. For a checkbox it sits beside the control, so the row's
     *                  own label column comes from 'row_label' instead.
     *   'value'     => current value ('selected' is accepted for select and radio)
     *   'help'      => hint under the field. 'help_html' for a hint carrying markup.
     *   'prefix'    => leading words that belong to the field, e.g. "Break comments into"
     *   'suffix'    => trailing words that belong to the field, e.g. "listings at most"
     *   'width'     => text|num|key|select|full, overriding the width the type implies
     *   'options'   => value => label, for select and radio
     *   'required', 'disabled' => bool
     *   'id'        => defaults to a slug of 'name'
     *   'attrs'     => extra attributes, name => value
     *   'row'       => false to emit the control alone, for a caller composing its own row
     *   'render'    => callable, with 'type' => 'custom': emits into the normal row
     *
     * @param array $spec
     *
     * @return void
     */
    function osc_admin_field(array $spec)
    {
        (new \mindstellar\admin\ui\Field($spec))->render();
    }
}

if (!function_exists('osc_admin_field_control')) {
    /**
     * The control alone, without its row, label or hint. Split out so every field type
     * shares one escaping path and one width decision.
     *
     * @param string $type
     * @param string $id
     * @param array  $spec
     *
     * @return void
     */
    function osc_admin_field_control($type, $id, array $spec)
    {
        \mindstellar\admin\ui\Field::control($type, $id, $spec);
    }
}

if (!function_exists('osc_admin_field_choices')) {
    /**
     * The option list of a radio group. Each option is its own label wrapping its own
     * control, so the whole line is a hit target and no id can drift from its label.
     *
     * An option may be a plain label, or an array carrying 'label' plus any of 'custom_html'
     * (markup rendered inside the label after its text, which is how "Custom: [____]" rows keep
     * the free-text input inside the choice), 'disabled', and 'id' where an existing script
     * already reaches for one.
     *
     * @param string $id
     * @param string $name
     * @param string $value
     * @param array  $spec
     *
     * @return void
     */
    function osc_admin_field_choices($id, $name, $value, array $spec)
    {
        \mindstellar\admin\ui\Field::choices($id, $name, $value, $spec);
    }
}

if (!function_exists('osc_admin_field_class')) {
    /**
     * The width class a field gets. Width follows the field's meaning, not the page it
     * happens to sit on; 'width' overrides it when a screen genuinely needs something else.
     *
     * @param string $type
     * @param array  $spec
     *
     * @return string
     */
    function osc_admin_field_class($type, array $spec)
    {
        return \mindstellar\admin\ui\Field::cssClass($type, $spec);
    }
}

if (!function_exists('osc_admin_field_help')) {
    /**
     * The hint under a field, in the same help-box the admin already uses.
     *
     * @param array $spec
     *
     * @return void
     */
    function osc_admin_field_help(array $spec)
    {
        \mindstellar\admin\ui\Field::help($spec);
    }
}

if (!function_exists('osc_admin_field_id')) {
    /**
     * A field's DOM id: its own, or one derived from its name so the label is clickable
     * without every caller having to invent one.
     *
     * @param array $spec
     *
     * @return string
     */
    function osc_admin_field_id(array $spec)
    {
        return \mindstellar\admin\ui\Field::idFor($spec);
    }
}

if (!function_exists('osc_admin_field_attrs')) {
    /**
     * Extra attributes as an escaped string. A value of true renders the attribute alone
     * (`readonly`), false and null drop it.
     *
     * @param array $attrs name => value
     *
     * @return string
     */
    function osc_admin_field_attrs(array $attrs)
    {
        return \mindstellar\admin\ui\Field::attrsString($attrs);
    }
}

if (!function_exists('osc_admin_field_row')) {
    /**
     * One labelled row holding several fields.
     *
     * osc_admin_field() draws its own row, which covers a label with a control beside it.
     * It does not cover the shape most settings screens are actually made of -- one label
     * against a stack of related checkboxes ("Default comment settings", "Notifications",
     * "Optional fields") -- and every one of those was still opening `<div class="form-row">`
     * and its two inner divs by hand.
     *
     * Each entry is an ordinary field spec. A callable is invoked instead, for the row that
     * has to drop to markup for one of its parts without hand-writing the row around it.
     *
     * @param string $label  The row's label. Empty for a row that continues the one above.
     * @param array  $fields Field specs, or callables emitting into the controls column.
     * @param array  $opts   'for' => id the label points at
     *
     * @return void
     */
    function osc_admin_field_row($label, array $fields, array $opts = array())
    {
        osc_admin_form_row_open($label, $opts);

        foreach ($fields as $field) {
            if (is_callable($field)) {
                $field();
                continue;
            }
            $field['row'] = false;
            if (($field['type'] ?? 'text') === 'checkbox') {
                osc_admin_checkbox($field);
                continue;
            }
            osc_admin_field($field);
        }

        osc_admin_form_row_close();
    }
}

if (!function_exists('osc_admin_form_open')) {
    /**
     * Open an admin form: the element, the hidden route fields it posts to, and the
     * wrappers the row layout needs.
     *
     * The fields on a settings screen were primitives already; everything around them was
     * not. Every screen wrote its own `<form>`, its own `page`/`action` hidden inputs and
     * its own `<fieldset><div class="form-horizontal">` -- 93 forms and 158 hidden route
     * fields across the admin, each a chance to post to the wrong action or to lose the
     * layout wrapper and with it the label column.
     *
     * Keys:
     *   'action' => string  The `action` this form posts to. Emitted as a hidden field.
     *   'page'   => string  The `page` it posts to; defaults to the current one, which is
     *                       almost always right -- a form usually posts back to its own
     *                       screen.
     *   'url'    => string  The form's action attribute; defaults to the admin base URL.
     *   'method' => string  Defaults to post. A GET form is never given a CSRF token.
     *   'fields' => array   Extra hidden fields, name => value.
     *   'name', 'id', 'class' => string
     *   'upload' => bool    multipart/form-data, for a form carrying a file field.
     *   'horizontal' => bool  Wrap in fieldset + .form-horizontal (default true). False for
     *                       a stacked form in a dialog or a panel.
     *   'csrf'   => bool    False marks the form `nocsrf`, which is only ever right for a
     *                       form that changes nothing.
     *
     * @param array $opts
     *
     * @return void
     */
    function osc_admin_form_open(array $opts = array())
    {
        \mindstellar\admin\ui\Form::open($opts);
    }
}

if (!function_exists('osc_admin_form_close')) {
    /**
     * Close what osc_admin_form_open() opened, optionally with the submit row.
     *
     * Pass an array -- empty for the plain "Save changes" every settings screen ends on --
     * to emit osc_admin_form_actions() inside the wrappers, where the row's own indent
     * lines it up with the controls above it. Pass nothing for a form whose buttons are
     * somewhere else.
     *
     * @param array|null $actions
     * @param array      $opts 'horizontal' => false when the form was opened that way
     *
     * @return void
     */
    function osc_admin_form_close($actions = null, array $opts = array())
    {
        \mindstellar\admin\ui\Form::close($actions, $opts);
    }
}

if (!function_exists('osc_admin_settings_form')) {
    /**
     * The whole form of a declared page (osc_register_settings_page()): the element, the
     * hidden route, every declared field with the value it should show, and the submit row.
     *
     * A screen whose chrome is its own -- a section, a title that changes between add and
     * edit -- keeps that chrome in its view and calls this for the form, so a declared page
     * is drawn one way wherever it is drawn from.
     *
     * @param array|string $page The page spec, or the id of a registered page.
     * @param array        $opts 'values'  => values to show, keyed by field name;
     *                           'route'   => hidden fields, 'page' and 'action' among them,
     *                                        defaulting to the generic settings controller's;
     *                           'actions' => the submit row, as osc_admin_form_actions()
     *                                        takes it;
     *                           'name', 'url' => attributes of the form element;
     *                           'upload'  => true to post multipart/form-data.
     *
     * @return void
     */
    function osc_admin_settings_form($page, array $opts = array())
    {
        \mindstellar\admin\ui\SettingsForm::render(
            is_array($page) ? $page : (array)osc_settings_page($page),
            $opts['values'] ?? array(),
            $opts
        );
    }
}

if (!function_exists('osc_admin_form_section')) {
    /**
     * A titled group of fields within a screen, with an optional explanatory paragraph.
     *
     * Sections were headings written by hand: 29 `<h3 class="render-title">` in the admin,
     * plus screens calling osc_admin_page_head() a second time for a section, which gives a
     * page two competing `<h2>`s. This is one heading level for one meaning -- the page has
     * a head, a section is inside it.
     *
     * @param string $title
     * @param array  $opts 'intro' => paragraph under the heading, 'intro_html' for markup,
     *                     'spaced' => true to separate it from the block above,
     *                     'level' => 2 to render the section as a page head instead.
     *
     * @return void
     */
    function osc_admin_form_section($title, array $opts = array())
    {
        \mindstellar\admin\ui\Form::section($title, $opts);
    }
}

if (!function_exists('osc_admin_action_section')) {
    /**
     * A titled block of actions — the "do a thing" panel (connection test, queue, migration,
     * cleanup, maintenance) that is not a label|control form. Renders a heading, an optional
     * intro, optional body content, and each action as a button with its own help line.
     *
     * @param array $opts 'title', 'intro'/'intro_html', 'body_html' (before the actions),
     *                    'footer_html' (after them), and 'actions' — a list where each is:
     *                    'label', 'variant', 'icon', 'attrs', 'help'/'help_html', plus one of
     *                    'confirm' => '#dialog-id' (opens a confirm dialog that submits itself),
     *                    'action' [+ 'page'/'fields'/'name'] (its own submit form),
     *                    or 'url' (a link).
     *
     * @return void
     */
    function osc_admin_action_section(array $opts = array())
    {
        \mindstellar\admin\ui\Form::actionSection($opts);
    }
}

if (!function_exists('osc_admin_text')) {
    /**
     * Single-line text. Keys: the shared set, plus 'placeholder', 'width', 'prefix', 'suffix'.
     *
     * A caller may name any of the single-line types the renderer draws the same way --
     * 'email', 'url', 'tel' -- and get that input type. Anything else is a text box: this
     * helper is the one that draws a line of text and does not become a <select> because a
     * caller said so. The list is the renderer's own, so the helper cannot promise a type
     * that would be quietly downgraded a layer down.
     *
     * @param array $opts
     *
     * @return void
     */
    function osc_admin_text(array $opts)
    {
        $type = (string)($opts['type'] ?? 'text');
        $opts['type'] = in_array($type, array('text', 'email', 'url', 'tel'), true) ? $type : 'text';
        osc_admin_field($opts);
    }
}

if (!function_exists('osc_admin_number')) {
    /**
     * A number, at number width. Keys: the shared set, plus 'min', 'max', 'step', 'prefix',
     * 'suffix'.
     *
     * @param array $opts
     *
     * @return void
     */
    function osc_admin_number(array $opts)
    {
        $opts['type'] = 'number';
        osc_admin_field($opts);
    }
}

if (!function_exists('osc_admin_select')) {
    /**
     * A dropdown. Keys: the shared set, plus 'options', 'selected', 'placeholder'.
     *
     * @param array $opts
     *
     * @return void
     */
    function osc_admin_select(array $opts)
    {
        $opts['type'] = 'select';
        osc_admin_field($opts);
    }
}

if (!function_exists('osc_admin_textarea')) {
    /**
     * Multi-line text. Keys: the shared set, plus 'rows', 'monospace'.
     *
     * @param array $opts
     *
     * @return void
     */
    function osc_admin_textarea(array $opts)
    {
        $opts['type'] = 'textarea';
        osc_admin_field($opts);
    }
}

if (!function_exists('osc_admin_radio_group')) {
    /**
     * A choice list. Keys: the shared set, plus 'options', 'selected'. An option may be a
     * string label or array('label' => …, 'custom_html' => …).
     *
     * @param array $opts
     *
     * @return void
     */
    function osc_admin_radio_group(array $opts)
    {
        $opts['type'] = 'radio';
        osc_admin_field($opts);
    }
}

if (!function_exists('osc_admin_secret')) {
    /**
     * An API key or password. Keys: the shared set, plus 'reveal', 'masked'.
     *
     * @param array $opts
     *
     * @return void
     */
    function osc_admin_secret(array $opts)
    {
        $opts['type'] = 'secret';
        osc_admin_field($opts);
    }
}

/*
 * Fallbacks for the theme components the primitives above build on. The active admin
 * theme defines these already and loads first, so on a stock install nothing below runs --
 * they exist so a plugin calling osc_admin_field() still renders on a theme that ships
 * neither -- and core's own declared-settings-page view calls them too, which would fatal
 * on such a theme. Markup is identical to themes/modern/parts/ui.php by design; the class
 * names are consumed directly by third-party plugins and cannot drift between the two copies.
 */

if (!function_exists('osc_admin_page_head')) {
    /**
     * The heading a screen opens on.
     *
     * @param string $title
     * @param array  $actions Action specs rendered to its right
     * @param array  $opts    'class' => extra classes on the heading
     *
     * @return void
     */
    function osc_admin_page_head($title, array $actions = array(), array $opts = array())
    {
        $class = 'render-title' . (!empty($opts['class']) ? ' ' . $opts['class'] : '');

        if ($actions === array()) {
            echo '<h2 class="' . osc_esc_html($class) . '">' . osc_esc_html($title) . '</h2>';

            return;
        }

        echo '<div class="page-head"><h2 class="' . osc_esc_html($class) . '">'
            . osc_esc_html($title) . '</h2><div class="page-head-actions">';
        foreach ($actions as $action) {
            osc_admin_action_button($action);
        }
        echo '</div></div>';
    }
}

if (!function_exists('osc_admin_action_button')) {
    /**
     * One action, as a link or a button.
     *
     * Keys: label, url, variant (primary|secondary|danger|dim), icon, title, attrs, type.
     *
     * @param array $action
     *
     * @return void
     */
    function osc_admin_action_button(array $action)
    {
        $variant   = $action['variant'] ?? 'secondary';
        $classes   = 'btn btn-sm btn-' . ($variant === 'primary' ? 'submit' : $variant);
        $attrs     = osc_admin_field_attrs($action['attrs'] ?? array());
        if (!empty($action['title'])) {
            $attrs .= ' title="' . osc_esc_html($action['title']) . '"';
        }

        $inner = '';
        if (!empty($action['icon'])) {
            $inner .= '<i class="bi ' . osc_esc_html($action['icon']) . '" aria-hidden="true"></i> ';
        }
        $inner .= osc_esc_html($action['label'] ?? '');

        if (!empty($action['url'])) {
            echo '<a class="' . osc_esc_html($classes) . '" href="' . osc_esc_html($action['url']) . '"'
                . $attrs . '>' . $inner . '</a>';

            return;
        }

        echo '<button type="' . osc_esc_html($action['type'] ?? 'button') . '"'
            . ' class="' . osc_esc_html($classes) . '"' . $attrs . '>' . $inner . '</button>';
    }
}

if (!function_exists('osc_admin_form_actions')) {
    /**
     * The submit row at the foot of a form. With no arguments this is a lone "Save
     * changes" -- the case that covers most settings screens.
     *
     * @param array $actions Action specs; the first defaults to variant 'primary'
     * @param array $opts    'dirty' => true for the unsaved-changes status bar
     *
     * @return void
     */
    function osc_admin_form_actions(array $actions = array(), array $opts = array())
    {
        if ($actions === array()) {
            $actions = array(array('label' => __('Save changes'), 'type' => 'submit', 'variant' => 'primary'));
        }

        // Opt-in, so an existing screen's action row is the markup it has always been.
        $dirty = !empty($opts['dirty'])
            ? ' data-osc-dirty-bar data-osc-dirty-one="' . osc_esc_html(__('1 unsaved change')) . '"'
              . ' data-osc-dirty-many="' . osc_esc_html(__('%d unsaved changes')) . '"'
            : '';

        echo '<div class="form-actions"' . $dirty . '>';
        if ($dirty !== '') {
            echo '<p class="form-actions-status" role="status" aria-live="polite"></p>';
        }
        foreach ($actions as $i => $action) {
            $action['variant'] = $action['variant'] ?? ($i === 0 ? 'primary' : 'secondary');
            $action['type']    = $action['type'] ?? 'submit';
            osc_admin_action_button($action);
        }
        echo '</div>';
    }
}

if (!function_exists('osc_admin_form_row_open')) {
    /**
     * One labelled row of a form.
     *
     * @param string $label Empty for a row with no label column of its own.
     * @param array  $opts  'for' => input id, so the label is clickable;
     *                      'id'/'class' => on the row, for a row a script shows and hides;
     *                      'controls_class' => extra classes on the controls column;
     *                      'data' => assoc array rendered as data-* attrs on the row;
     *                      'label_html' => raw markup for the label, instead of the escaped $label;
     *                      'layout' => 'stacked' to stack the label above a full-width control
     *
     * @return void
     */
    function osc_admin_form_row_open($label = '', array $opts = array())
    {
        \mindstellar\admin\ui\Form::rowOpen($label, $opts);
    }
}

if (!function_exists('osc_admin_form_row_close')) {
    /**
     * Close the row osc_admin_form_row_open() opened.
     *
     * @return void
     */
    function osc_admin_form_row_close()
    {
        \mindstellar\admin\ui\Form::rowClose();
    }
}

if (!function_exists('osc_admin_checkbox')) {
    /**
     * A checkbox with its label on one line and its hint underneath.
     *
     * Keys: name, label, checked, value (default '1'), help, help_html, id, attrs.
     * `label_html` is the raw-markup form of `label`, for the label that has to carry a link.
     *
     * @param array $opts
     *
     * @return void
     */
    function osc_admin_checkbox(array $opts)
    {
        echo '<div class="form-label-checkbox"><label>'
            . '<input type="checkbox" name="' . osc_esc_html($opts['name'] ?? '') . '"'
            . (!empty($opts['id']) ? ' id="' . osc_esc_html($opts['id']) . '"' : '')
            . ' value="' . osc_esc_html($opts['value'] ?? '1') . '"'
            . (!empty($opts['checked']) ? ' checked="checked"' : '')
            . osc_admin_field_attrs($opts['attrs'] ?? array()) . ' /> '
            . (!empty($opts['label_html']) ? $opts['label_html'] : osc_esc_html($opts['label'] ?? ''))
            . '</label>';
        osc_admin_field_help($opts);
        echo '</div>';
    }
}

if (!function_exists('osc_admin_tree_picker')) {
    /**
     * A category checkbox tree with "Check all / Uncheck all" toggles, shared by every
     * screen that assigns fields, groups or plugin config to categories.
     *
     * Keys: 'id' (required, the <ul> id and the toggles' target), 'intro', 'categories',
     * 'selected' (passed straight to CategoryForm::categories_tree()), 'wrapper_id'
     * (id on the outer .form-row).
     *
     * @param array $opts
     *
     * @return void
     */
    function osc_admin_tree_picker(array $opts)
    {
        $id = osc_esc_html($opts['id'] ?? '');

        echo '<div class="form-row"'
            . (!empty($opts['wrapper_id']) ? ' id="' . osc_esc_html($opts['wrapper_id']) . '"' : '')
            . '>';
        echo '<div>' . osc_esc_html($opts['intro'] ?? '') . '</div>';
        echo '<div class="separate-top">';
        echo '<div class="form-label">';
        echo '<a href="#" data-tree-toggle="' . $id . '" data-tree-check="1">' . osc_esc_html(__('Check all')) . '</a>';
        echo ' &middot; ';
        echo '<a href="#" data-tree-toggle="' . $id . '" data-tree-check="0">' . osc_esc_html(__('Uncheck all')) . '</a>';
        echo '</div>';
        echo '<div class="form-controls">';
        echo '<ul id="' . $id . '">';
        CategoryForm::categories_tree($opts['categories'] ?? array(), $opts['selected'] ?? array());
        echo '</ul>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}

/* file end: ./oc-includes/osclass/helpers/hAdminUi.php */
