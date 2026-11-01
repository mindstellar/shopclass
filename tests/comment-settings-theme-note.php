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
 * The Comments settings screen notes that the theme shows no comments only when the theme
 * declared osc_add_theme_support('comments', false). DB-free. Usage: php tests/comment-settings-theme-note.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\admin\form\CommentSettingsScreen;
use mindstellar\theme\ThemeSupports;

$supports = ThemeSupports::getInstance();

harness_section('theme comments support');

$supports->reset();
check('undeclared shows no note', !CommentSettingsScreen::themeHidesComments());

$supports->add('comments');
check('true shows no note', !CommentSettingsScreen::themeHidesComments());

$supports->reset();
$supports->add('comments', false);
check('false shows the note', CommentSettingsScreen::themeHidesComments());

$view = file_get_contents(ABS_PATH . 'oc-admin/themes/modern/settings/comments.php');
check('the screen calls it', strpos($view, 'CommentSettingsScreen::themeHidesComments()') !== false);

exit(harness_result());
