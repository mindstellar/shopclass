<?php
if (!defined('ABS_PATH')) {
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
 * Tone lookups and the print-once stylesheet emitter for core-rendered pages.
 *
 * Lives beside page.php rather than in a helper because page.php also renders
 * the boot-failure screens. osc_die() runs from oc-load.php before the theme
 * helpers are required, so the shell has to bring its own functions rather than
 * assume any are defined. Both this file and hTheme.php require_once it.
 */

if (!function_exists('osc_gui_tone_accent')) {
    /**
     * Accent colour for a page tone.
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

if (!function_exists('osc_gui_print_style')) {
    /**
     * Print the shared stylesheet for core-rendered pages, once per request.
     *
     * Called from page.php inside <head> on the standalone path, and registered
     * on the 'header' hook when a core partial renders inside theme chrome (all
     * bundled themes run that hook inside <head>). osc_gui_view() calls it again
     * before the content in case a theme never runs the hook; the guard makes
     * the second call a no-op.
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
