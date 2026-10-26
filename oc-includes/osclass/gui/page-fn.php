<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Tone lookups, the print-once stylesheet emitter and the markup helpers (fields,
 * panels, empty lines, button rows) for core-rendered pages.
 *
 * Lives beside page.php rather than in a helper because page.php also renders
 * the boot-failure screens. osc_die() runs from oc-load.php before the theme
 * helpers are required, so the shell has to bring its own functions rather than
 * assume any are defined. Both this file and hTheme.php require_once it.
 */

if (!function_exists('osc_gui_tone_accent')) {
    /**
     * Accent colour for a page tone.
     *
     * @param string $tone One of info, warning, danger or success; unknown tones fall back to danger
     *
     * @return string
     */
    function osc_gui_tone_accent(string $tone): string
    {
        $map = array(
            'info'    => '#0b7269',
            'warning' => '#7a6716',
            'danger'  => '#c22826',
            'success' => '#1d7d3e',
        );

        return $map[$tone] ?? $map['danger'];
    }
}

if (!function_exists('osc_gui_tone_band')) {
    /**
     * Band (tinted strip) colour for a page tone.
     *
     * @param string $tone One of info, warning, danger or success; unknown tones fall back to danger
     *
     * @return string
     */
    function osc_gui_tone_band(string $tone): string
    {
        $map = array(
            'info'    => '#e6f6f4',
            'warning' => '#fdf4d2',
            'danger'  => '#ffe9e5',
            'success' => '#e4f8e7',
        );

        return $map[$tone] ?? $map['danger'];
    }
}

if (!function_exists('osc_gui_tone_icon')) {
    /**
     * Inline SVG paths for a page tone, already coloured.
     *
     * @param string $tone One of info, warning, danger or success; unknown tones fall back to danger
     *
     * @return string
     */
    function osc_gui_tone_icon(string $tone): string
    {
        $accent = osc_gui_tone_accent($tone);
        $alert  = '<path d="M12 8v5" stroke="%C" stroke-width="2" stroke-linecap="round"/>'
                  . '<circle cx="12" cy="16.5" r="1.25" fill="%C"/>'
                  . '<path d="M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.7 3.86a2 2 0 0 0-3.42 0Z"'
                  . ' stroke="%C" stroke-width="1.6"/>';
        $icons  = array(
            // wrench -- maintenance and other neutral notices
            'info'    => '<path d="M14.7 6.3a4 4 0 0 1-5.4 5.2l-4.6 4.6a1.5 1.5 0 0 1-2.1-2.1l4.6-4.6a4 4 0 0 1'
                         . ' 5.2-5.4l-2.3 2.3 1.4 1.4 2.3-2.3q.5 .4 .9 1Z" stroke="%C" stroke-width="1.6" fill="none"'
                         . ' stroke-linejoin="round"/>',
            'warning' => $alert,
            'danger'  => $alert,
            'success' => '<circle cx="12" cy="12" r="9" stroke="%C" stroke-width="1.6"/>'
                         . '<path d="m8 12 2.5 2.5L16 9" stroke="%C" stroke-width="2" fill="none"'
                         . ' stroke-linecap="round" stroke-linejoin="round"/>',
        );

        return str_replace('%C', $accent, $icons[$tone] ?? $icons['danger']);
    }
}

if (!function_exists('osc_gui_kept')) {
    /**
     * What the visitor typed into a field before a failed submit, else $default.
     *
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    function osc_gui_kept($key, $default = '')
    {
        $kept = (string)Session::getInstance()->_getForm($key);

        return $kept !== '' ? $kept : (string)$default;
    }
}

if (!function_exists('osc_gui_print_style')) {
    /**
     * Print the shared stylesheet for core-rendered pages, once per request.
     *
     * Called from page.php inside <head> on the standalone path, and registered
     * on the 'header' hook when a core partial renders inside theme chrome (all
     * bundled themes run that hook inside <head>). osc_gui_view() calls it again
     * before the content in case a theme never runs the hook; the guard makes
     * the second call a no-op.
     *
     * @param string $tone Tone whose accent and band colours the stylesheet is built with
     */
    function osc_gui_print_style(string $tone = 'info'): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;

        $accent = osc_gui_tone_accent($tone);
        $band   = osc_gui_tone_band($tone);

        require ABS_PATH . 'oc-includes/osclass/gui/page-style.php';
    }
}

if (!function_exists('osc_gui_listing_list')) {
    /**
     * Print the current `items` loop as core's listing list, unless a theme or plugin
     * returns its own markup from the listing_list_html filter.
     *
     * @param string $context dashboard, user_items, public_profile or alert
     * @param bool   $owned   show the owner's status badges and actions
     */
    function osc_gui_listing_list(string $context, bool $owned): void
    {
        $html = osc_apply_filter('listing_list_html', null, (array) View::getInstance()->_get('items'), $context);
        if (is_string($html)) {
            echo $html;

            return;
        }

        $rowOwned   = $owned;
        $rowContext = $context;
        echo '<ul class="oe-list">';
        while (osc_has_items()) {
            require ABS_PATH . 'oc-includes/osclass/gui/account/parts/item-row.php';
        }
        echo '</ul>';
    }
}

if (!function_exists('osc_gui_print_confirm_script')) {
    /**
     * Ask before following a link marked data-osc-confirm. Printed once per request.
     */
    function osc_gui_print_confirm_script(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;

        echo '<script>document.addEventListener("click",function(e){'
            . 'var a=e.target.closest&&e.target.closest("[data-osc-confirm]");'
            . 'if(a&&!window.confirm(a.getAttribute("data-osc-confirm"))){e.preventDefault();}});</script>';
    }
}

if (!function_exists('osc_gui_print_pager')) {
    /**
     * Print core's pager for the current item list, only when it leads to another page.
     */
    function osc_gui_print_pager(): void
    {
        $pager = osc_pagination_items();
        if (strpos($pager, '<a') !== false) {
            echo '<nav class="oe-pager" aria-label="' . osc_esc_html(_m('Pages')) . '">' . $pager . '</nav>';
        }
    }
}

if (!function_exists('osc_gui_print_dialog_script')) {
    /**
     * Turn each dialog[data-osc-dialog] into a modal: [data-osc-dialog-open="id"] opens
     * it, [data-osc-dialog-close] closes it. Without the script a dialog shows in place.
     */
    function osc_gui_print_dialog_script(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;

        echo '<script>(function(){var ds=document.querySelectorAll("dialog[data-osc-dialog]");'
            . 'if(!ds.length||!ds[0].showModal){return;}'
            . 'ds.forEach(function(d){d.setAttribute("data-osc-dialog-ready","");'
            . 'if(d.hasAttribute("data-osc-dialog-show")){d.showModal();}'
            . 'd.addEventListener("click",function(e){if(e.target===d){d.close();}});});'
            . 'document.addEventListener("click",function(e){'
            . 'var o=e.target.closest("[data-osc-dialog-open]");'
            . 'if(o){var d=document.getElementById(o.getAttribute("data-osc-dialog-open"));'
            . 'if(d&&d.hasAttribute("data-osc-dialog-ready")){e.preventDefault();d.showModal();}return;}'
            . 'var c=e.target.closest("[data-osc-dialog-close]");if(c&&c.closest("dialog")){c.closest("dialog").close();}});'
            . '})();</script>';
    }
}

if (!function_exists('osc_gui_field')) {
    /**
     * Print a form row: label, input, then an optional hint and error. The hint gets the id
     * "{$for}-hint" and the error "{$for}-err", so the input can point at them with aria-describedby.
     *
     * @param string          $for   id of the input the label is for
     * @param string          $label plain text, escaped here
     * @param string|\Closure $input the input's HTML, printed as given, or a closure that prints it
     * @param string          $hint  plain text, escaped here
     * @param string          $error plain text, escaped here
     * @param string          $class extra classes on the row
     *
     * @since 7.1.0
     */
    function osc_gui_field(string $for, string $label, string|\Closure $input, string $hint = '', string $error = '', string $class = ''): void
    {
        echo '<div class="oe-field' . ($class !== '' ? ' ' . osc_esc_html($class) : '') . '">' . "\n"
            . '<label class="oe-label" for="' . osc_esc_html($for) . '">' . osc_esc_html($label) . '</label>' . "\n";
        if ($input instanceof \Closure) {
            $input();
        } else {
            echo $input;
        }
        echo "\n";
        if ($hint !== '') {
            echo '<span class="oe-hint" id="' . osc_esc_html($for) . '-hint">' . osc_esc_html($hint) . '</span>' . "\n";
        }
        if ($error !== '') {
            echo '<span class="oe-hint oe-field-error" id="' . osc_esc_html($for) . '-err">' . osc_esc_html($error) . '</span>' . "\n";
        }
        echo '</div>';
    }
}

if (!function_exists('osc_gui_panel_open')) {
    /**
     * Open a card, with its title when one is given. Close it with osc_gui_panel_close($tag).
     *
     * @param string $title plain text, escaped here
     * @param string $class extra classes on the card
     * @param string $id
     * @param string $tag   section, div or li
     *
     * @since 7.1.0
     */
    function osc_gui_panel_open(string $title = '', string $class = '', string $id = '', string $tag = 'section'): void
    {
        $tag = in_array($tag, array('section', 'div', 'li'), true) ? $tag : 'section';
        echo '<' . $tag . ($id !== '' ? ' id="' . osc_esc_html($id) . '"' : '')
            . ' class="oe-panel' . ($class !== '' ? ' ' . osc_esc_html($class) : '') . '">';
        if ($title !== '') {
            echo "\n" . '<h2 class="oe-panel__title">' . osc_esc_html($title) . '</h2>';
        }
        echo "\n";
    }
}

if (!function_exists('osc_gui_panel_close')) {
    /**
     * Close a card osc_gui_panel_open() opened.
     *
     * @param string $tag the tag it was opened with: section, div or li
     *
     * @since 7.1.0
     */
    function osc_gui_panel_close(string $tag = 'section'): void
    {
        echo '</' . (in_array($tag, array('section', 'div', 'li'), true) ? $tag : 'section') . '>';
    }
}

if (!function_exists('osc_gui_empty')) {
    /**
     * Print the quiet line shown where a list has nothing in it.
     *
     * @param string $message plain text, escaped here
     * @param string $class   extra classes
     *
     * @since 7.1.0
     */
    function osc_gui_empty(string $message, string $class = ''): void
    {
        echo '<p class="oe-empty' . ($class !== '' ? ' ' . osc_esc_html($class) : '') . '">' . osc_esc_html($message) . '</p>';
    }
}

if (!function_exists('osc_gui_actions')) {
    /**
     * Print a row of buttons. Each entry: label, href (a link; without it a submit button),
     * style (primary, secondary, danger or link; primary by default), class (extra classes)
     * and attrs (name => value, escaped here).
     *
     * @param array<int, array<string, mixed>> $buttons
     * @param string                           $class extra classes on the row
     *
     * @since 7.1.0
     */
    function osc_gui_actions(array $buttons, string $class = ''): void
    {
        $styles = array(
            'primary'   => 'oe-btn oe-btn--primary',
            'secondary' => 'oe-btn oe-secondary',
            'danger'    => 'oe-btn oe-btn-danger',
            'link'      => '',
        );
        echo '<div class="oe-actions' . ($class !== '' ? ' ' . osc_esc_html($class) : '') . '">' . "\n";
        foreach ($buttons as $button) {
            $classes = trim(($styles[$button['style'] ?? 'primary'] ?? $styles['primary']) . ' ' . (string) ($button['class'] ?? ''));
            $attrs   = $classes !== '' ? ' class="' . osc_esc_html($classes) . '"' : '';
            $attrs  .= isset($button['href']) ? ' href="' . osc_esc_html(osc_sanitize_url((string) $button['href'])) . '"' : ' type="submit"';
            foreach ((array) ($button['attrs'] ?? array()) as $name => $value) {
                $attrs .= ' ' . osc_esc_html((string) $name) . '="' . osc_esc_html((string) $value) . '"';
            }
            $tag = isset($button['href']) ? 'a' : 'button';
            echo '<' . $tag . $attrs . '>' . osc_esc_html((string) $button['label']) . '</' . $tag . '>' . "\n";
        }
        echo '</div>';
    }
}
