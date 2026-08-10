<?php

if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Shared admin UI components.
 *
 * Every admin screen was assembling the same shapes by hand -- a page title, a panel, a
 * status pill, a toolbar, an "empty" message -- and each copy drifted a little from the
 * last. The result reads as several applications sharing a stylesheet, which is the exact
 * failure mode the design system exists to prevent.
 *
 * These functions are that vocabulary, written once. They emit the classes the theme
 * already styles, so a screen built from them is indistinguishable from a correct
 * hand-built one, and a screen built by hand is not broken by their existence. Nothing
 * here is required; it is the shortest path to the standard rather than a gate in front
 * of it.
 *
 * ESCAPING CONTRACT. Every value is escaped by the partial that prints it, except keys
 * whose name ends in `_html` and the two documented markup slots below. If you add a
 * parameter that must accept markup, name it `*_html` -- the suffix is the whole warning
 * system, and a raw sink without one is a bug waiting for a caller who assumes otherwise.
 *
 * The two exceptions, both deliberate:
 *   osc_admin_page()'s `help` -- the help-box body is a prose slot; a dozen of them carry
 *       <strong> or <p>, and translators supply markup there too.
 *   osc_admin_definition()'s `value` when the row sets `html => true` -- opt-in per row.
 */

if (!function_exists('osc_admin_page')) {
    /**
     * Declare what this screen is, once, at the top of the file.
     *
     * Every page was writing the same three functions -- a `customPageHeader` emitting an
     * <h1>, an `addHelp` printing a paragraph, a `customPageTitle` filtering the browser
     * title -- and registering each against its hook. Three definitions and three
     * registrations per file, seventy-one times, is how the drift got in: some pages grew
     * a help toggle without help behind it, some ordered their header links differently,
     * some forgot the title filter entirely.
     *
     * Keys:
     *   'section' => string|callable  The area name shown as the page <h1> (Users,
     *                        Listings, ...). A callable is resolved when the header
     *                        renders, which is what a title passing through a plugin
     *                        filter needs -- at registration time the plugin has not
     *                        added its filter yet.
     *   'title'   => string  Browser-title prefix, rendered as "Prefix » Site title".
     *   'help'    => string|callable  Help-box body. Its presence is what puts the "?"
     *                        in the header -- the toggle can no longer exist without it.
     *   'actions' => array   Header icon actions; see osc_admin_page_header().
     *
     * Registers against the same hooks as before, so a plugin adding its own
     * `admin_page_header` or `help_box` callback still runs alongside this one.
     *
     * @param array $opts
     */
    function osc_admin_page(array $opts)
    {
        $section = $opts['section'] ?? '';
        $actions = $opts['actions'] ?? array();
        $help    = $opts['help'] ?? null;

        if ($section !== '' || $actions !== array()) {
            osc_add_hook('admin_page_header', static function () use ($section, $actions, $help) {
                osc_admin_page_header($section, array(
                    'actions' => $actions,
                    'help'    => $help !== null,
                ));
            });
        }

        if ($help !== null) {
            osc_add_hook('help_box', static function () use ($help) {
                if (is_callable($help)) {
                    $help();

                    return;
                }
                echo '<p>' . $help . '</p>';
            });
        }

        if (!empty($opts['title'])) {
            $prefix = (string) $opts['title'];
            osc_add_filter('admin_title', static function ($title) use ($prefix) {
                return sprintf(__('%1$s &raquo; %2$s'), $prefix, $title);
            });
        }
    }
}

if (!function_exists('osc_admin_page_header')) {
    /**
     * The page <h1> and the icon actions that belong beside it.
     *
     * Actions are emitted in one order everywhere: what the page is for first (add,
     * upload), then where to configure it, then help last. Before this, each screen chose
     * its own order, so the "?" moved between the left and right of the cluster depending
     * on which page you were on.
     *
     * Each action: icon (bootstrap-icon name), url, title, and optionally onclick or
     * attrs (associative, escaped). The title is both the tooltip and the accessible name
     * -- an icon with neither is a button that a screen reader announces as nothing.
     * `attrs` is how a header action keeps the id its page's JavaScript binds to.
     *
     * @param string $section
     * @param array  $opts 'actions' => array, 'help' => bool
     */
    function osc_admin_page_header($section, array $opts = array())
    {
        if (is_callable($section)) {
            $section = $section();
        } ?>
        <h1><?php echo osc_esc_html((string) $section); ?>
            <?php foreach (($opts['actions'] ?? array()) as $action) {
                // `label` is what osc_admin_action_button() calls the visible text, so a
                // caller who builds an action spec there and reuses it here would
                // otherwise render an icon with no accessible name at all.
                $label = (string) ($action['title'] ?? $action['label'] ?? '');
                $attrs = '';
                foreach (($action['attrs'] ?? array()) as $name => $value) {
                    $attrs .= ' ' . osc_esc_html($name) . '="' . osc_esc_html($value) . '"';
                } ?>
                <a href="<?php echo osc_esc_html($action['url'] ?? '#'); ?>"
                   <?php if (!empty($action['onclick'])) { ?>onclick="<?php echo osc_esc_html($action['onclick']); ?>"<?php } ?>
                   title="<?php echo osc_esc_html($label); ?>"
                   aria-label="<?php echo osc_esc_html($label); ?>"<?php echo $attrs; ?>>
                    <i class="bi <?php echo osc_esc_html($action['icon'] ?? 'bi-circle'); ?>" aria-hidden="true"></i>
                </a>
            <?php } ?>
            <?php if (!empty($opts['help'])) { ?>
                <a class="bi bi-question-circle" data-bs-target="#help-box" data-bs-toggle="collapse"
                   href="#help-box" title="<?php echo osc_esc_html(__('Help')); ?>"
                   aria-label="<?php echo osc_esc_html(__('Help')); ?>"></a>
            <?php } ?>
        </h1>
        <?php
    }
}

if (!function_exists('osc_admin_page_head')) {
    /**
     * The page title, and the actions that belong to the page as a whole.
     *
     * With no actions this emits exactly the `<h2 class="render-title">` every screen
     * already uses, so adopting it changes no pixels.
     *
     * @param string $title
     * @param array  $actions Action specs; see osc_admin_action_button()
     * @param array  $opts    'class' => extra classes on the <h2>; 'actions_html' =>
     *                        callable printing a control that is not a button (a segmented
     *                        toggle, a select), used instead of $actions
     */
    function osc_admin_page_head($title, array $actions = array(), array $opts = array())
    {
        $class = 'render-title' . (!empty($opts['class']) ? ' ' . $opts['class'] : '');

        if ($actions === array() && empty($opts['actions_html'])) {
            echo '<h2 class="' . osc_esc_html($class) . '">' . osc_esc_html($title) . '</h2>';

            return;
        } ?>
        <div class="page-head">
            <h2 class="<?php echo osc_esc_html($class); ?>"><?php echo osc_esc_html($title); ?></h2>
            <div class="page-head-actions">
                <?php foreach ($actions as $action) {
                    osc_admin_action_button($action);
                }
                // Composes with $actions rather than replacing it: a screen with both a
                // button and a segmented control should not silently lose the button.
                if (!empty($opts['actions_html'])) {
                    ($opts['actions_html'])();
                } ?>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('osc_admin_action_button')) {
    /**
     * One action, as a link or a button.
     *
     * Keys: label, url, variant (primary|secondary|danger|dim), icon (bootstrap-icon
     * name), title, attrs (associative, rendered verbatim as escaped attributes).
     *
     * Variant maps to the button vocabulary in DESIGN: one primary per region, secondary
     * for everything routine, danger reserved for genuinely destructive work.
     *
     * @param array $action
     */
    function osc_admin_action_button(array $action)
    {
        $variant = $action['variant'] ?? 'secondary';
        $classes = array('btn', 'btn-sm');
        $classes[] = 'btn-' . ($variant === 'primary' ? 'submit' : $variant);

        $attrs = '';
        foreach (($action['attrs'] ?? array()) as $name => $value) {
            $attrs .= ' ' . osc_esc_html($name) . '="' . osc_esc_html($value) . '"';
        }
        if (!empty($action['title'])) {
            $attrs .= ' title="' . osc_esc_html($action['title']) . '"';
        }

        $inner = '';
        if (!empty($action['icon'])) {
            $inner .= '<i class="bi ' . osc_esc_html($action['icon']) . '" aria-hidden="true"></i> ';
        }
        $inner .= osc_esc_html($action['label'] ?? '');

        if (!empty($action['url'])) {
            echo '<a class="' . implode(' ', $classes) . '" href="' . osc_esc_html($action['url']) . '"'
                 . $attrs . '>' . $inner . '</a>';

            return;
        }

        echo '<button type="' . osc_esc_html($action['type'] ?? 'button') . '" class="'
             . implode(' ', $classes) . '"' . $attrs . '>' . $inner . '</button>';
    }
}

if (!function_exists('osc_admin_link_group')) {
    /**
     * A segmented group of links where exactly one is current -- a range switch, a view
     * switch. Each link: label, url, active (bool).
     *
     * The four statistics screens each rebuilt this loop verbatim, inside their own
     * hand-written two-column grid. The grid is `osc_admin_page_head()`'s job; this is the
     * control that sat in it.
     *
     * @param array $links
     */
    function osc_admin_link_group(array $links)
    {
        echo '<div class="btn-group btn-group-sm" role="group">';
        foreach ($links as $link) {
            $classes = 'btn btn-outline-primary' . (!empty($link['active']) ? ' active' : '');
            echo '<a class="' . $classes . '" href="' . osc_esc_html($link['url'] ?? '#') . '"'
                 . (!empty($link['active']) ? ' aria-current="page"' : '') . '>'
                 . osc_esc_html($link['label'] ?? '') . '</a>';
        }
        echo '</div>';
    }
}

if (!function_exists('osc_admin_panel_open')) {
    /**
     * Open a panel. Flat at rest -- the border and the surface tone carry it, never a shadow.
     *
     * For content that does not frame itself: a form, definition rows, a figure. NOT a
     * table -- `.table` already draws its own frame, so a panel around one nests two
     * borders and sets the section apart from every list screen in the admin, which put
     * their table straight in the flow under an <h3 class="render-title">.
     *
     * The title is an <h3> so a screen reader can navigate the panels on a screen. The
     * stylesheet flattens every heading level inside `.widget-box-title` to the same
     * 18px/normal, so this renders identically to the bare <span> it replaced.
     *
     * Options:
     *   'subtitle' => string  One line under the title, for the thing a header cannot say.
     *   'actions'  => array   Action specs rendered in the header, inline-end.
     *
     * @param string $title Omit for a panel that needs no header
     * @param array  $opts
     */
    function osc_admin_panel_open($title = '', array $opts = array())
    {
        echo '<div class="widget-box">';

        if ($title !== '') { ?>
            <div class="widget-box-title">
                <h3><?php echo osc_esc_html($title); ?></h3>
                <?php if (!empty($opts['actions'])) { ?>
                    <span class="widget-box-title-actions">
                        <?php foreach ($opts['actions'] as $action) {
                            osc_admin_action_button($action);
                        } ?>
                    </span>
                <?php } ?>
            </div>
        <?php }

        echo '<div class="widget-box-content">';

        if (!empty($opts['subtitle'])) {
            echo '<p class="panel-subtitle">' . osc_esc_html($opts['subtitle']) . '</p>';
        }
    }
}

if (!function_exists('osc_admin_panel_close')) {
    function osc_admin_panel_close()
    {
        echo '</div></div>';
    }
}

if (!function_exists('osc_admin_status')) {
    /**
     * A status pill: a tint, a shape, and the word. Never colour alone -- the glyph is
     * a second channel for greyscale and colour-blind readers, and the word is a third.
     *
     * $state selects the tint and silhouette from the $status-badge map in
     * scss/definitions.scss. An unmapped state still renders, in the neutral tint with
     * the fallback ring, so a plugin's own word degrades gracefully instead of vanishing.
     *
     * @param string $state Lower-case state key, e.g. 'paid'
     * @param string $word  The visible word, already translated
     */
    function osc_admin_status($state, $word)
    {
        echo '<span class="osc-status status-' . osc_esc_html($state) . '">'
             . osc_esc_html($word) . '</span>';
    }
}

if (!function_exists('osc_admin_empty')) {
    /**
     * The state a screen is in before it has been used.
     *
     * An empty screen is the one place an admin is most likely to conclude the feature is
     * broken, so this says what the screen is for and offers the action that fills it --
     * never a bare "no results".
     *
     * Keys: icon (bootstrap-icon name), title, text, action (a single action spec).
     *
     * @param array $opts
     */
    function osc_admin_empty(array $opts)
    { ?>
        <div class="osc-empty">
            <?php if (!empty($opts['icon'])) { ?>
                <i class="bi <?php echo osc_esc_html($opts['icon']); ?> osc-empty-icon" aria-hidden="true"></i>
            <?php } ?>
            <p class="osc-empty-title"><?php echo osc_esc_html($opts['title'] ?? ''); ?></p>
            <?php if (!empty($opts['text'])) { ?>
                <p class="osc-empty-text"><?php echo osc_esc_html($opts['text']); ?></p>
            <?php } ?>
            <?php if (!empty($opts['action'])) { ?>
                <div class="osc-empty-action"><?php osc_admin_action_button($opts['action']); ?></div>
            <?php } ?>
        </div>
        <?php
    }
}

if (!function_exists('osc_admin_definition')) {
    /**
     * Label/value rows -- the read-only counterpart to a form.
     *
     * Each row is an array with 'label' and 'value'. A row may set 'html' => true to pass
     * markup through (for a status pill or a link), which is why the default escapes:
     * the unsafe path has to be asked for by name.
     *
     * @param array $rows
     */
    function osc_admin_definition(array $rows)
    { ?>
        <dl class="osc-deflist">
            <?php foreach ($rows as $row) { ?>
                <div class="osc-deflist-row">
                    <dt><?php echo osc_esc_html($row['label'] ?? ''); ?></dt>
                    <dd<?php echo !empty($row['mono']) ? ' class="osc-mono"' : ''; ?>>
                        <?php
                        if (!empty($row['html'])) {
                            echo $row['value'];
                        } else {
                            echo osc_esc_html((string) ($row['value'] ?? ''));
                        } ?>
                    </dd>
                </div>
            <?php } ?>
        </dl>
        <?php
    }
}

if (!function_exists('osc_admin_toolbar_open')) {
    /**
     * The filter/search strip above a table.
     *
     * Deliberately NOT the legacy `.table-toolbar`, which is a float with a hard-coded
     * 44px height: anything taller than one line escapes it, and the float narrows the
     * panel beside it. The listing screens are built around that behaviour, so it stays
     * where it is — but new work gets a toolbar that is simply a row.
     *
     * @param array $opts 'align' => 'start'|'between'|'end' (default 'between')
     */
    function osc_admin_toolbar_open(array $opts = array())
    {
        $align = $opts['align'] ?? 'between';
        echo '<div class="osc-toolbar osc-toolbar-' . osc_esc_html($align) . '">';
    }
}

if (!function_exists('osc_admin_toolbar_close')) {
    function osc_admin_toolbar_close()
    {
        echo '</div>';
    }
}

if (!function_exists('osc_admin_pager')) {
    /**
     * Page through a list.
     *
     * Renders nothing at all when everything fits on one page — a pager under a
     * three-row table is chrome reporting on itself.
     *
     * The range ("1–25 of 240") is stated in words rather than as a strip of numbered
     * links, because the question an admin actually has is "how much of this is there",
     * and a row of page numbers answers a question nobody asked.
     *
     * @param array $opts 'total', 'per_page', 'page' (1-based), 'base_url', 'params'
     */
    function osc_admin_pager(array $opts)
    {
        $total   = max(0, (int) ($opts['total'] ?? 0));
        $perPage = max(1, (int) ($opts['per_page'] ?? 25));
        $page    = max(1, (int) ($opts['page'] ?? 1));
        $pages   = (int) ceil($total / $perPage);

        if ($pages < 2) {
            return;
        }

        $page  = min($page, $pages);
        $first = (($page - 1) * $perPage) + 1;
        $last  = min($total, $page * $perPage);

        $link = static function ($target) use ($opts) {
            $params         = $opts['params'] ?? array();
            $params['pageNum'] = $target;

            return osc_esc_html(($opts['base_url'] ?? '') . '&' . http_build_query($params));
        }; ?>
        <nav class="osc-pager" aria-label="<?php echo osc_esc_html(__('Pagination')); ?>">
            <p class="osc-pager-range">
                <?php printf(
                    osc_esc_html(__('%1$s–%2$s of %3$s')),
                    number_format($first),
                    number_format($last),
                    number_format($total)
                ); ?>
            </p>
            <div class="osc-pager-controls">
                <?php if ($page > 1) { ?>
                    <a class="btn btn-secondary btn-sm" href="<?php echo $link($page - 1); ?>"
                       rel="prev"><?php _e('Previous'); ?></a>
                <?php } else { ?>
                    <span class="btn btn-secondary btn-sm disabled" aria-disabled="true"><?php _e('Previous'); ?></span>
                <?php } ?>
                <?php if ($page < $pages) { ?>
                    <a class="btn btn-secondary btn-sm" href="<?php echo $link($page + 1); ?>"
                       rel="next"><?php _e('Next'); ?></a>
                <?php } else { ?>
                    <span class="btn btn-secondary btn-sm disabled" aria-disabled="true"><?php _e('Next'); ?></span>
                <?php } ?>
            </div>
        </nav>
        <?php
    }
}

if (!function_exists('osc_admin_money')) {
    /**
     * Format a micros amount for display.
     *
     * Money is stored as the value times 1,000,000 (matching t_item.i_price) and is
     * divided only here, at the edge, so no arithmetic anywhere else ever touches a float.
     * The currency code is shown rather than a symbol: an admin reconciling against a
     * gateway needs to know it was EUR, and one glyph does not distinguish the several
     * currencies that use "$".
     *
     * @param int    $micros
     * @param string $currency ISO 4217
     *
     * @return string
     */
    function osc_admin_money($micros, $currency)
    {
        $value = (int) $micros / 1000000;

        return number_format($value, 2, osc_locale_dec_point(), osc_locale_thousands_sep())
               . ' ' . strtoupper((string) $currency);
    }
}

if (!function_exists('osc_admin_form_row_open')) {
    /**
     * One labelled row of a form.
     *
     * `form-controls` is consumed directly by bundled plugins (forty-odd times), so this
     * emits exactly the markup they expect. It exists to stop the label markup drifting,
     * not to replace the class.
     *
     * @param string $label
     * @param array  $opts 'for' => input id, so the label is clickable
     */
    function osc_admin_form_row_open($label = '', array $opts = array())
    {
        echo '<div class="form-row">';

        if ($label !== '') {
            $for = $opts['for'] ?? '';
            echo '<div class="form-label">';
            echo $for !== ''
                ? '<label for="' . osc_esc_html($for) . '">' . osc_esc_html($label) . '</label>'
                : osc_esc_html($label);
            echo '</div>';
        }

        echo '<div class="form-controls">';
    }
}

if (!function_exists('osc_admin_form_row_close')) {
    function osc_admin_form_row_close()
    {
        echo '</div></div>';
    }
}

if (!function_exists('osc_admin_checkbox')) {
    /**
     * A checkbox with its label on one line and its hint underneath.
     *
     * Keys: name, label, checked, value (default '1'), help, help_html, id, attrs.
     *
     * @param array $opts
     */
    function osc_admin_checkbox(array $opts)
    {
        $attrs = '';
        foreach (($opts['attrs'] ?? array()) as $name => $value) {
            $attrs .= ' ' . osc_esc_html($name) . '="' . osc_esc_html($value) . '"';
        } ?>
        <div class="form-label-checkbox">
            <label>
                <input type="checkbox" name="<?php echo osc_esc_html($opts['name'] ?? ''); ?>"
                       <?php if (!empty($opts['id'])) { ?>id="<?php echo osc_esc_html($opts['id']); ?>"<?php } ?>
                       value="<?php echo osc_esc_html($opts['value'] ?? '1'); ?>"
                       <?php echo !empty($opts['checked']) ? 'checked="checked"' : ''; ?><?php echo $attrs; ?> />
                <?php echo osc_esc_html($opts['label'] ?? ''); ?>
            </label>
            <?php if (!empty($opts['help']) || !empty($opts['help_html'])) { ?>
                <div class="help-box"><?php
                    echo !empty($opts['help_html'])
                        ? $opts['help_html']
                        : osc_esc_html((string) $opts['help']); ?></div>
            <?php } ?>
        </div>
        <?php
    }
}

if (!function_exists('osc_admin_form_actions')) {
    /**
     * The submit row at the foot of a form.
     *
     * Emits `<button>` rather than `<input type="submit">`. Both render identically here,
     * but the admin was split roughly evenly between them, and only one can carry an icon
     * or wrap a translated label that outgrows its box.
     *
     * With no arguments this is a lone "Save changes" -- the case that covers most
     * settings screens.
     *
     * @param array $actions Action specs; the first defaults to variant 'primary'
     */
    function osc_admin_form_actions(array $actions = array())
    {
        if ($actions === array()) {
            $actions = array(array('label' => __('Save changes'), 'type' => 'submit', 'variant' => 'primary'));
        }

        echo '<div class="form-actions">';
        foreach ($actions as $i => $action) {
            $action['variant'] = $action['variant'] ?? ($i === 0 ? 'primary' : 'secondary');
            $action['type']    = $action['type'] ?? 'submit';
            osc_admin_action_button($action);
        }
        echo '</div>';
    }
}

if (!function_exists('osc_admin_table_empty')) {
    /**
     * The "nothing here" row inside a table that has already drawn its header.
     *
     * Replaces the bare "No data available in table" the list screens shared, which told
     * an admin nothing about whether the feature was empty, filtered, or broken. Takes the
     * same keys as osc_admin_empty().
     *
     * @param int   $colspan Must match the header, or the row will not span the table
     * @param array $opts
     */
    function osc_admin_table_empty($colspan, array $opts = array())
    {
        echo '<tr class="osc-table-empty-row"><td colspan="' . (int) $colspan . '">';
        osc_admin_empty($opts + array(
            'icon'  => 'bi-inbox',
            'title' => __('Nothing here yet'),
        ));
        echo '</td></tr>';
    }
}

if (!function_exists('osc_admin_bulk_actions')) {
    /**
     * The select-plus-Apply group above a table of selectable rows.
     *
     * Apply used to be `.btn-primary`, which Bootstrap compiles to a fixed light-mode
     * fill and so kept its light colour under `data-bs-theme="dark"`. It is the theme's
     * own primary here, which follows the theme.
     *
     * Keys: name (select name), options (osc_print_bulk_actions format), id, label.
     * A screen whose options carry per-option markup can pass 'options_html' => callable
     * and print its own <select> instead, keeping the group and its button standard.
     *
     * @param array $opts
     */
    function osc_admin_bulk_actions(array $opts)
    { ?>
        <div id="bulk-actions">
            <div class="input-group input-group-sm">
                <label class="visually-hidden" for="<?php echo osc_esc_html($opts['id'] ?? 'bulk_actions'); ?>">
                    <?php echo osc_esc_html($opts['label'] ?? __('Bulk actions')); ?>
                </label>
                <?php if (!empty($opts['options_html'])) {
                    ($opts['options_html'])();
                } else {
                    osc_print_bulk_actions(
                        $opts['id'] ?? 'bulk_actions',
                        $opts['name'] ?? 'action',
                        $opts['options'] ?? array(),
                        'select-box-extra'
                    );
                } ?>
                <button type="submit" id="bulk_apply" class="btn btn-submit"><?php _e('Apply'); ?></button>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('osc_admin_bulk_confirm_dialog')) {
    /**
     * The confirm shown before a bulk action runs.
     *
     * Its body is deliberately empty: `osc.js` copies the selected option's
     * `data-dialog-content` into it, so the question matches the action chosen rather than
     * being a generic "are you sure". That is why this cannot go through
     * osc_admin_confirm_dialog(), which owns its own title and text -- and why nine list
     * screens each carried a byte-identical copy of this markup instead.
     *
     * @param array $opts 'id' (default 'bulkActionsModal'), 'confirm' (button label)
     */
    function osc_admin_bulk_confirm_dialog(array $opts = array())
    { ?>
        <dialog id="<?php echo osc_esc_html($opts['id'] ?? 'bulkActionsModal'); ?>"
                class="osc-dialog osc-dialog-danger">
            <div class="osc-dialog-body">
                <p class="osc-dialog-title"><?php _e('Bulk actions'); ?></p>
                <p class="osc-dialog-text"></p>
            </div>
            <div class="osc-dialog-actions">
                <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                <button type="button" id="bulkActionsSubmit" onclick="bulkActionsSubmit()"
                        class="btn btn-danger btn-sm"><?php echo osc_esc_html($opts['confirm'] ?? __('Delete')); ?></button>
            </div>
        </dialog>
        <?php
    }
}

if (!function_exists('osc_admin_per_page')) {
    /**
     * The "how many rows" select that sits above a list.
     *
     * Submits on change and carries every other GET parameter through as hidden inputs, so
     * changing the page size keeps the filter, sort and search the admin already set. The
     * form is marked `nocsrf`: it is a GET navigation, and the shutdown injector would
     * otherwise put a token in the query string of every link.
     *
     * Keys: 'label' => a printf format taking the count (e.g. "%d listings"),
     *       'options' => int[], 'name' => query parameter (default iDisplayLength),
     *       'current' => the page size actually in effect.
     *
     * Pass `current`. Without it the select falls back to the request parameter, which is
     * absent on a first visit -- so the browser shows the first option while the
     * controller is using its own default, and the control states a number that is not
     * the one on screen.
     *
     * @param array $opts
     */
    function osc_admin_per_page(array $opts = array())
    {
        $name    = $opts['name'] ?? 'iDisplayLength';
        $options = $opts['options'] ?? array(10, 25, 50, 100, 250, 500);
        $label   = $opts['label'] ?? __('%d per page');
        $current = (int) ($opts['current'] ?? Params::getParam($name) ?: 0); ?>
        <form method="get" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>" class="inline nocsrf">
            <?php foreach (Params::getParamsAsArray('get') as $key => $value) {
                if ($key === $name || is_array($value)) {
                    continue;
                } ?>
                <input type="hidden" name="<?php echo osc_esc_html($key); ?>"
                       value="<?php echo osc_esc_html($value); ?>"/>
            <?php } ?>
            <label class="visually-hidden" for="osc-per-page"><?php _e('Rows per page'); ?></label>
            <select id="osc-per-page" name="<?php echo osc_esc_html($name); ?>"
                    class="form-select form-select-sm" onchange="this.form.submit();">
                <?php foreach ($options as $n) { ?>
                    <option value="<?php echo (int) $n; ?>"<?php echo $current === (int) $n ? ' selected' : ''; ?>>
                        <?php printf($label, (int) $n); ?>
                    </option>
                <?php } ?>
            </select>
        </form>
        <?php
    }
}

if (!function_exists('osc_admin_pagination')) {
    /**
     * The range line and page controls under a DataTables-shaped list.
     *
     * Every list screen defined its own `showingResults` closure over the same numbers and
     * hung it on `before_show_pagination_admin`. This computes them from the one array they
     * all already have.
     *
     * Two shapes exist in the admin: rows under `aaData` or under `aRows`, and a filtered
     * count with or without an unfiltered `iTotalRecords` beside it. Both are handled here,
     * because a screen that silently lost its "filtered from N total" line would be
     * reporting a smaller catalogue than it holds.
     *
     * @param array $aData The controller's row set plus its display/total counts
     */
    function osc_admin_pagination(array $aData)
    {
        $perPage  = (int) ($aData['iDisplayLength'] ?? 0);
        $page     = max(1, (int) (Params::getParam('iPage') ?: 1));
        $shown    = count($aData['aRows'] ?? $aData['aaData'] ?? array());
        $filtered = (int) ($aData['iTotalDisplayRecords'] ?? 0);
        // Only widens the sentence when it is genuinely larger than the filtered count.
        $total    = isset($aData['iTotalRecords']) ? (int) $aData['iTotalRecords'] : null;

        osc_add_hook(
            'before_show_pagination_admin',
            static function () use ($perPage, $page, $shown, $filtered, $total) {
                // An empty list has no range to state; the empty row inside the table says it.
                if ($shown === 0) {
                    return;
                }
                $from = (($page - 1) * $perPage) + 1;
                echo '<ul class="showing-results"><li><span>'
                     . osc_pagination_showing($from, $from + $shown - 1, $filtered, $total)
                     . '</span></li></ul>';
            }
        );

        osc_show_pagination_admin($aData);
    }
}

if (!function_exists('osc_admin_confirm_dialog')) {
    /**
     * A modal that asks before something irreversible happens.
     *
     * The title says what will happen and the text says what it affects, because "Are you
     * sure? This is permanent" tells an owner nothing they can act on. Destructive by
     * default -- pass 'tone' => 'plain' for a confirm that only interrupts.
     *
     * No CSRF field is emitted. Csrf::init() registers a shutdown filter that injects the
     * token into every <form> on the page that is not marked `nocsrf`, so writing one here
     * would put two identical pairs of hidden inputs in the same form.
     *
     * Keys: id, title, text, confirm (label), confirm_id, method ('get'|'post'), url,
     * fields (name => value hidden inputs), body_html (extra markup inside the form).
     *
     * `text` is escaped, exactly as osc_admin_empty()'s `text` is. The two used to differ,
     * which meant the same key name in the same file was safe in one call and a raw sink
     * in the next. Pass `text_html` for the rare sentence that needs a <strong>.
     *
     * @param array $opts
     */
    function osc_admin_confirm_dialog(array $opts)
    {
        $danger = ($opts['tone'] ?? 'danger') === 'danger'; ?>
        <dialog id="<?php echo osc_esc_html($opts['id'] ?? 'confirmDialog'); ?>"
                class="osc-dialog<?php echo $danger ? ' osc-dialog-danger' : ''; ?>">
            <form method="<?php echo osc_esc_html($opts['method'] ?? 'post'); ?>"
                  action="<?php echo osc_esc_html($opts['url'] ?? osc_admin_base_url(true)); ?>">
                <?php foreach (($opts['fields'] ?? array()) as $name => $value) { ?>
                    <input type="hidden" name="<?php echo osc_esc_html($name); ?>"
                           value="<?php echo osc_esc_html($value); ?>" />
                <?php } ?>
                <div class="osc-dialog-body">
                    <p class="osc-dialog-title">
                        <?php if ($danger) { ?>
                            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                        <?php } ?>
                        <?php echo osc_esc_html($opts['title'] ?? ''); ?>
                    </p>
                    <?php if (!empty($opts['text']) || !empty($opts['text_html'])) { ?>
                        <p class="osc-dialog-text"><?php
                            echo !empty($opts['text_html'])
                                ? $opts['text_html']
                                : osc_esc_html((string) $opts['text']); ?></p>
                    <?php }
                    if (!empty($opts['body_html'])) {
                        echo $opts['body_html'];
                    } ?>
                </div>
                <div class="osc-dialog-actions">
                    <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                    <button type="submit"
                            <?php if (!empty($opts['confirm_id'])) { ?>id="<?php echo osc_esc_html($opts['confirm_id']); ?>"<?php } ?>
                            class="btn btn-<?php echo $danger ? 'danger' : 'submit'; ?> btn-sm">
                        <?php echo osc_esc_html($opts['confirm'] ?? __('Delete')); ?>
                    </button>
                </div>
            </form>
        </dialog>
        <?php
    }
}

/* file end: ./oc-admin/themes/modern/parts/ui.php */
