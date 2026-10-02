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
 * Pins what saving an email template writes: every posted locale's subject and message,
 * nothing at all when no locale has a subject, and never the internal name -- core finds
 * a template to send by that name.
 *
 * DB-free: the page store records its calls instead of writing.  Usage:  php tests/admin-email-save.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

/** Stands in for the controller's parent, which needs a logged-in admin to build. */
class AdminSecBaseModel
{
}

/** Records every write, so a pin can say exactly what a save touched. */
class Page
{
    public array $calls = array();

    public function updateDescription($id, $locale, $title, $text)
    {
        $this->calls[] = array('updateDescription', $id, $locale, $title, $text);
    }

    public function updateInternalName($id, $name)
    {
        $this->calls[] = array('updateInternalName', $id, $name);
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminEmails.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

harness_section('reading the posted form');

pin(
    'each locale gets its subject and message',
    array(
        'en_US' => array('s_title' => 'Hi', 's_text' => '<p>Hello</p>'),
        'hi_IN' => array('s_title' => '', 's_text' => ''),
    ),
    CAdminEmails::descriptions(array(
        'page'            => 'emails',
        'id'              => '4',
        's_internal_name' => 'renamed',
        'en_US#s_title'   => 'Hi',
        'en_US#s_text'    => '<p>Hello</p>',
        'hi_IN#s_title'   => '',
        'hi_IN#s_text'    => '',
    ))
);
pin(
    'an array smuggled in under a locale field is dropped',
    array(),
    CAdminEmails::descriptions(array('en_US#s_title' => array('x')))
);

harness_section('saving');

$pages = new Page();
$saved = CAdminEmails::save(4, array(
    'en_US' => array('s_title' => 'Hi', 's_text' => '<p>Hello</p>'),
    'hi_IN' => array('s_title' => '', 's_text' => ''),
), $pages);
pin('a template with one subject saves', true, $saved);
pin(
    'every posted locale is written, the empty one too',
    array(
        array('updateDescription', 4, 'en_US', 'Hi', '<p>Hello</p>'),
        array('updateDescription', 4, 'hi_IN', '', ''),
    ),
    $pages->calls
);

$pages = new Page();
pin(
    'a template with no subject in any locale is refused',
    false,
    CAdminEmails::save(4, array(
        'en_US' => array('s_title' => '', 's_text' => '<p>Hello</p>'),
        'hi_IN' => array('s_title' => '', 's_text' => ''),
    ), $pages)
);
pin('and nothing is written', array(), $pages->calls);

$pages = new Page();
CAdminEmails::save(4, array('en_US' => array('s_title' => 'Hi')), $pages);
pin(
    'a locale posted without a message is written with an empty one',
    array(array('updateDescription', 4, 'en_US', 'Hi', '')),
    $pages->calls
);

$pages = new Page();
CAdminEmails::save(4, CAdminEmails::descriptions(array(
    's_internal_name' => 'renamed',
    'en_US#s_title'   => 'Hi',
    'en_US#s_text'    => 'Body',
)), $pages);
pin(
    'a posted internal name is never written',
    array(),
    array_values(array_filter($pages->calls, static fn ($call) => $call[0] === 'updateInternalName'))
);

exit(harness_result());
