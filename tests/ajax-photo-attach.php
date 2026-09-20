<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Which ajax-uploaded photos a post may attach.
 *
 * The names arrive in `ajax_photos[]` and become paths under `uploads/temp/`. They were taken
 * at face value: any name that pointed at a readable file was attached, and after the post
 * finished `uploadItemResources()` unlinks every temp file it was handed. So a name reaching
 * outside the folder, or naming a file somebody else staged, meant attaching and then deleting
 * a file the poster never uploaded.
 *
 * Two things now have to hold: the name is a bare filename, and it was staged under this
 * form's own upload token.
 *
 * DB-free: the staging table is stood in.  Usage: php tests/ajax-photo-attach.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

$tmpDir = sys_get_temp_dir() . '/ajax-attach-' . getmypid() . '/';
@mkdir($tmpDir . 'uploads/temp/', 0777, true);

function osc_content_path()
{
    return $GLOBALS['contentPath'];
}

function osc_upload_token()
{
    return $GLOBALS['token'];
}

function osc_max_images_per_item()
{
    return $GLOBALS['maxImages'] ?? 4;
}

$GLOBALS['contentPath'] = $tmpDir;
$GLOBALS['token']       = 'this-form';
$GLOBALS['maxImages']   = 4;

/** Stands in for the staging table: file => the token it was uploaded under. */
class ItemTmpUpload
{
    public static array $staged = array();

    public static function newInstance(): self
    {
        return new self();
    }

    public function belongsToToken($token, $file)
    {
        return ((string)$token !== '' && (string)$file !== '')
            && (self::$staged[(string)$file] ?? null) === (string)$token;
    }
}

/**
 * The guard as ItemActions applies it, reading the same source so the two cannot drift.
 *
 * @param array $ajaxPhotos
 *
 * @return array the names that would be attached
 */
function attach(array $ajaxPhotos): array
{
    $photos = array('name' => array(), 'type' => array(), 'tmp_name' => array(), 'error' => array(), 'size' => array());
    $aItem  = array('photos' => $photos);

    $src = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/actions/ItemActions.php');
    if (!preg_match('/\n        if \(is_array\(\$ajax_photos\).*?\n        \}\n/s', $src, $m)) {
        fwrite(STDERR, "Could not find the attach block in ItemActions.php\n");
        exit(1);
    }
    $ajax_photos = $ajaxPhotos;
    eval($m[0]);

    return $aItem['photos']['name'];
}

/** Put a file in uploads/temp/ and say who staged it. */
$stage = static function (string $name, ?string $token) use ($tmpDir): void {
    file_put_contents($tmpDir . 'uploads/temp/' . $name, 'x');
    if ($token !== null) {
        ItemTmpUpload::$staged[$name] = $token;
    }
};

$stage('mine.jpg', 'this-form');
$stage('someone-elses.jpg', 'another-form');
$stage('never-staged.jpg', null);

// A file outside the folder, of the kind a traversal would reach.
file_put_contents($tmpDir . 'outside.jpg', 'x');

harness_section('What may be attached');

pin('a photo staged under this form is attached', array('mine.jpg'), attach(array('mine.jpg')));
pin('and several are', array('mine.jpg', 'mine.jpg'), attach(array('mine.jpg', 'mine.jpg')));

harness_section('What may not');

// The one that mattered: the file exists and is readable, and was somebody else's upload.
pin('a photo staged under another form is refused', array(), attach(array('someone-elses.jpg')));
pin('a file nobody staged is refused', array(), attach(array('never-staged.jpg')));
pin('a name reaching out of the folder is refused', array(), attach(array('../outside.jpg')));
pin('...including a deeper one', array(), attach(array('../../../../etc/passwd')));
pin('an absolute path is refused', array(), attach(array('/etc/passwd')));
pin('a backslash path is refused', array(), attach(array('..\\outside.jpg')));
pin('an empty name is refused', array(), attach(array('')));
pin('a name that is not there is refused', array(), attach(array('absent.jpg')));
pin('the current directory is not a photo', array(), attach(array('.')));
pin('nor the parent', array(), attach(array('..')));
pin('a null byte in the name is refused', array(), attach(array("mine.jpg\0.php")));
pin('an array where a name should be is refused', array(), attach(array(array('mine.jpg'))));

harness_section('The list cannot be unbounded');

// prepareData() runs before the CSRF check, so the length of this array is chosen by
// an anonymous POST. It costs one lookup per name, so it stops at the site's photo cap.
pin(
    'no more names are considered than the site allows photos',
    array('mine.jpg', 'mine.jpg', 'mine.jpg', 'mine.jpg'),
    attach(array_fill(0, 500, 'mine.jpg'))
);

harness_section('One bad name does not take the good ones with it');

pin(
    'the staged photo is still attached beside a refused one',
    array('mine.jpg'),
    attach(array('../outside.jpg', 'mine.jpg', 'someone-elses.jpg'))
);

array_map('unlink', glob($tmpDir . 'uploads/temp/*'));
@unlink($tmpDir . 'outside.jpg');
@rmdir($tmpDir . 'uploads/temp');
@rmdir($tmpDir . 'uploads');
@rmdir($tmpDir);

exit(harness_result());
