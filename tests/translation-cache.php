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
 * Pins Translation::_load()'s compiled-catalogue cache.
 *
 * The cache is only worth having if it is invisible: the same strings come back
 * whether the catalogue was parsed or included, a replaced language pack is picked
 * up without anything having to invalidate it, and an install that cannot write to
 * uploads still translates.  Usage: php tests/translation-cache.php
 */

define('ABS_PATH', __DIR__ . '/../');

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$tmp = sys_get_temp_dir() . '/osc-tcache-' . getmypid();
@mkdir($tmp . '/uploads', 0777, true);

// Translation::_load() writes beside osc_uploads_path(); point that at the scratch dir.
$GLOBALS['__uploads'] = $tmp . '/uploads/';
function osc_uploads_path()
{
    return $GLOBALS['__uploads'];
}

require_once __DIR__ . '/../oc-includes/osclass/classes/Translation.php';

/** Compile a .po to .mo through the same library the language build uses. */
function make_mo($poBody, $path)
{
    file_put_contents($path . '.po', $poBody);
    $t = Gettext\Translations::fromPoFile($path . '.po');
    $t->toMoFile($path);
}

$po = <<<'EOT'
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\n"

msgid "Apple"
msgstr "Pomme"

msgid "%d item"
msgid_plural "%d items"
msgstr[0] "%d article"
msgstr[1] "%d articles"
EOT;

$mo = $tmp . '/core.mo';
make_mo($po, $mo);

/** A Translation with no catalogues loaded, so _load() can be driven directly. */
function fresh_translation()
{
    $r = new ReflectionClass('Translation');
    $t = $r->newInstanceWithoutConstructor();
    $p = $r->getProperty('translator');
    $p->setAccessible(true);
    $p->setValue($t, new Gettext\Translator());

    return $t;
}

function cache_files($tmp)
{
    return glob($tmp . '/uploads/translations-cache/*.cache') ?: array();
}

harness_section('first load compiles, second load reads the compiled copy');
$t1 = fresh_translation();
$t1->_load($mo, 'core');
pin('cache file written', 1, count(cache_files($tmp)));
pin('singular translated', 'Pomme', $t1->_get()->dgettext('core', 'Apple'));

$compiled = cache_files($tmp)[0];
// Prove the second load really reads the cache: change what the cache says and the
// translator must follow it, which it can only do by reading the file. The
// replacement is the same length as the original so the serialised length prefix
// in front of it stays correct.
file_put_contents($compiled, str_replace('Pomme', 'CACHE', file_get_contents($compiled)));
$t2 = fresh_translation();
$t2->_load($mo, 'core');
pin('second load came from cache', 'CACHE', $t2->_get()->dgettext('core', 'Apple'));

harness_section('plural forms survive the round trip');
$t3 = fresh_translation();
$t3->_load($mo, 'core');
pin('plural n=1', '%d article', $t3->_get()->dngettext('core', '%d item', '%d items', 1));
pin('plural n=5', '%d articles', $t3->_get()->dngettext('core', '%d item', '%d items', 5));

harness_section('a replaced language pack is picked up');
// Same path, new contents -- the case a mtime/size-keyed cache has to notice.
sleep(1); // filemtime has one-second resolution
make_mo(str_replace('Pomme', 'Apfel', $po), $mo);
$t4 = fresh_translation();
$t4->_load($mo, 'core');
pin('new catalogue wins', 'Apfel', $t4->_get()->dgettext('core', 'Apple'));
pin('a second cache entry exists', 2, count(cache_files($tmp)));

harness_section('a corrupt cache entry does not break translation');
$current = null;
foreach (cache_files($tmp) as $f) {
    if (strpos(file_get_contents($f), 'Apfel') !== false) {
        $current = $f;
    }
}
file_put_contents($current, 'this is not serialised data');
$t5 = fresh_translation();
$t5->_load($mo, 'core');
pin('falls back to parsing the .mo', 'Apfel', $t5->_get()->dgettext('core', 'Apple'));

// A truncated write -- the shape a half-finished cache write would leave behind.
file_put_contents($current, substr(serialize(array('messages' => array())), 0, 12));
$t7 = fresh_translation();
$t7->_load($mo, 'core');
pin('truncated entry falls back too', 'Apfel', $t7->_get()->dgettext('core', 'Apple'));

harness_section('an install that cannot cache still translates');
$GLOBALS['__uploads'] = '';           // no uploads path resolved yet
$t6 = fresh_translation();
$t6->_load($mo, 'core');
pin('translates with caching unavailable', 'Apfel', $t6->_get()->dgettext('core', 'Apple'));
$GLOBALS['__uploads'] = $tmp . '/uploads/';

harness_section('a missing catalogue is still reported');
pin('missing file returns false', false, fresh_translation()->_load($tmp . '/nope.mo', 'core'));

// tidy up
array_map('unlink', cache_files($tmp));
@rmdir($tmp . '/uploads/translations-cache');
@rmdir($tmp . '/uploads');
@unlink($mo);
@unlink($mo . '.po');
@rmdir($tmp);

exit(harness_result());
