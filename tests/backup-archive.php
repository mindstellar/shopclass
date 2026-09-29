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
 * The backup zip: written in steps that survive a stop, read back by ZipArchive, zip64
 * records past 4 GB, the file walk (links out of the site skipped, links inside followed,
 * the backups folder left out), and extraction that writes only under oc-content.
 *
 * No database. Usage:  php tests/backup-archive.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\backup\BackupArchive;
use mindstellar\backup\FileWalker;
use mindstellar\backup\ZipWriter;

$base = sys_get_temp_dir() . '/osc_backup_archive_' . getmypid();
@mkdir($base, 0777, true);
register_shutdown_function(static function () use ($base) {
    exec('rm -rf ' . escapeshellarg($base));
});

harness_section('Write and read back');

$text = str_repeat("line of text; it compresses\n", 20000);
$jpeg = random_bytes(300000);
file_put_contents($base . '/a.txt', $text);
file_put_contents($base . '/b.jpg', $jpeg);

$zip = new ZipWriter($base . '/one.zip');
$first = $zip->addFile('files/oc-content/a.txt', $base . '/a.txt');
$zip->finish();
pin('a text file is deflated', true, $first['compressed'] < $first['size'] / 10);
pin('...and the size is its own', strlen($text), $first['size']);

$zip = new ZipWriter($base . '/two.zip');
$zip->addFile('files/oc-content/a.txt', $base . '/a.txt');
$state = $zip->state();
$zip->close();
// A run that dies halfway leaves bytes after the last entry it recorded.
file_put_contents($base . '/two.zip', 'HALF-WRITTEN ENTRY', FILE_APPEND);
file_put_contents($base . '/two.zip.cdir', 'HALF', FILE_APPEND);
$zip  = new ZipWriter($base . '/two.zip', $state);
$pic  = $zip->addFile('files/oc-content/b.jpg', $base . '/b.jpg', true);
$zip->addString('manifest.json', '{"format":1}');
$zip->finish();
pin('a photo is stored, not deflated', $pic['size'], $pic['compressed']);
pin('...with its SHA-256 when asked', hash('sha256', $jpeg), $pic['sha256']);

$read = new ZipArchive();
pin('the resumed archive opens with a consistency check', true, $read->open($base . '/two.zip', ZipArchive::CHECKCONS));
pin('...holding exactly the three entries', 3, $read->numFiles);
pin('...the text intact', $text, $read->getFromName('files/oc-content/a.txt'));
pin('...the photo intact', $jpeg, $read->getFromName('files/oc-content/b.jpg'));
pin('...and the manifest last', 'manifest.json', $read->getNameIndex(2));
$read->close();
pin('the central directory file is removed', false, is_file($base . '/two.zip.cdir'));
pin('the archive is readable by its owner only', '600', substr(sprintf('%o', fileperms($base . '/two.zip')), -3));

harness_section('zip64 past 4 GB');

$big    = 5 * 1024 * 1024 * 1024;
$record = ZipWriter::centralRecord('files/oc-content/huge.mp4', 0, 0, 33, 123, $big, $big, $big + 7);
$fixed  = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen', $record);
pin('the central record marks both sizes as zip64', array(0xFFFFFFFF, 0xFFFFFFFF), array($fixed['csize'], $fixed['usize']));
$extra = unpack('vtag/vlen/Pusize/Pcsize/Poffset', substr($record, 46 + $fixed['namelen']));
pin('...and carries them, with the offset, in the zip64 field', array(1, 24, $big, $big, $big + 7), array_values($extra));
$end = ZipWriter::endRecords(70000, $big, $big + 1);
$z64 = unpack('Vsig/Psize/vmade/vneed/Vdisk/Vcd/Pentries/Ptotal/Pcdsize/Pcdoffset', $end);
pin('the zip64 end record counts past 65,535 entries', array(0x06064b50, 70000, $big, $big + 1), array($z64['sig'], $z64['total'], $z64['cdsize'], $z64['cdoffset']));
$eocd = unpack('Vsig/vdisk/vcd/vhere/vtotal/Vsize/Voffset', substr($end, 56 + 20));
pin('...and the classic record points to it', array(0x06054b50, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF), array($eocd['sig'], $eocd['total'], $eocd['size'], $eocd['offset']));

harness_section('The file walk');

$site    = $base . '/site';
$content = $site . '/oc-content';
$outside = $base . '/elsewhere';
foreach (array('uploads/2', 'uploads/purifier-html', 'uploads/temp', 'downloads/backups', 'downloads/oc-temp', 'plugins/p/.git', 'plugins/a-c', 'plugins/a') as $dir) {
    @mkdir($content . '/' . $dir, 0777, true);
}
@mkdir($site . '/shared', 0777, true);
@mkdir($outside, 0777, true);
foreach (array('uploads/2/1.jpg', 'uploads/purifier-html/c.ser', 'uploads/temp/t.jpg', 'downloads/backups/old.zip',
    'downloads/oc-temp/x', 'plugins/p/.git/HEAD', 'plugins/p/index.php', 'plugins/a-c/x.php', 'plugins/a/b.php', 'debug.log') as $file) {
    file_put_contents($content . '/' . $file, $file);
}
file_put_contents($site . '/shared/inside.txt', 'in');
file_put_contents($outside . '/secret.txt', 'out');
symlink($outside, $content . '/themes');
symlink($site . '/shared', $content . '/languages');
symlink($outside . '/secret.txt', $content . '/linked.txt');
symlink($content, $content . '/plugins/loop');

$walker = new FileWalker($content, $site);
$files  = array_keys(iterator_to_array($walker->files()));
pin('only real content is walked, in order', array(
    'languages/inside.txt',
    'plugins/a/b.php',
    'plugins/a-c/x.php',
    'plugins/p/index.php',
    'uploads/2/1.jpg',
), $files);
pin('links out of the site are skipped and named', array('linked.txt', 'themes'), (static function (array $s): array {
    sort($s);

    return $s;
})($walker->skipped()));
pin('a walk resumes after the file it stopped on', array('plugins/p/index.php', 'uploads/2/1.jpg'), array_keys(iterator_to_array((new FileWalker($content, $site))->files('plugins/a-c/x.php'))));
pin('...a folder name sorts before a longer name it starts', -1, FileWalker::compare('plugins/a/b.php', 'plugins/a-c/x.php'));
check('the backups folder is always left out', FileWalker::isExcluded('downloads/backups', true) && FileWalker::isExcluded('downloads/backups/x.zip', false));
check('...a name that only starts like it is not', !FileWalker::isExcluded('downloads/backups-old/x.zip', false));
pin('a count walk agrees with the walk', array('count' => 5, 'bytes' => 2 + strlen('plugins/a/b.php') + strlen('plugins/a-c/x.php') + strlen('plugins/p/index.php') + strlen('uploads/2/1.jpg'), 'complete' => true), (new FileWalker($content, $site))->count(microtime(true) + 60));

harness_section('Extract');

$real  = realpath($content);
$sreal = realpath($site);
foreach (array(
    '../evil.php'                          => 'a parent path',
    '/etc/cron.d/x'                        => 'an absolute path',
    'C:/Windows/x'                         => 'a drive letter',
    'files/oc-content/../../escape.php'    => 'a path that climbs out',
    'files/oc-content/uploads/../x.php'    => 'a `..` segment anywhere',
    'other/x.php'                          => 'anything not under files/oc-content/',
    'files/config.php'                     => 'the site folder',
    'files/oc-content/themes/secret.txt'   => 'a path through a link out of the site',
    'files/oc-content/linked.txt'          => 'a link itself',
    'files/oc-content/uploads/2'           => 'a folder',
) as $name => $what) {
    pin("refused: $what", null, BackupArchive::target($name, $real, $sreal));
}
pin('allowed: a file under oc-content', $real . '/uploads/2/new.jpg', BackupArchive::target('files/oc-content/uploads/2/new.jpg', $real, $sreal));
pin('allowed: through a link that stays in the site', $real . '/languages/new.txt', BackupArchive::target('files/oc-content/languages/new.txt', $real, $sreal));

$crafted = new ZipArchive();
$crafted->open($base . '/crafted.zip', ZipArchive::CREATE);
$crafted->addFromString('manifest.json', '{}');
$crafted->addFromString('database.sql', 'SELECT 1;');
$crafted->addFromString('files/oc-content/uploads/2/1.jpg', 'NEW');
$crafted->addFromString('files/oc-content/uploads/fresh/f.txt', 'fresh');
$crafted->addFromString('../evil.php', 'x');
$crafted->addFromString('files/oc-content/themes/secret.txt', 'overwritten');
$crafted->addFromString('files/oc-content/sym', 'target');
$crafted->setExternalAttributesName('files/oc-content/sym', ZipArchive::OPSYS_UNIX, (0120777 << 16));
$crafted->close();
chmod($content . '/uploads/2/1.jpg', 0640);

$archive = new BackupArchive($base . '/crafted.zip');
pin('it counts the entries under files/oc-content/', 4, $archive->fileCount());
$r = $archive->extract($real, $sreal, 0, 100, microtime(true) + 20);
$archive->close();
pin('two files written, three refused', array('written' => 2, 'refused' => 3, 'done' => true), array('written' => $r['written'], 'refused' => $r['refused'], 'done' => $r['done']));
pin('an existing file is replaced', 'NEW', file_get_contents($content . '/uploads/2/1.jpg'));
pin('...keeping its mode', '640', substr(sprintf('%o', fileperms($content . '/uploads/2/1.jpg')), -3));
pin('a new folder is made', 'fresh', file_get_contents($content . '/uploads/fresh/f.txt'));
pin('nothing lands outside the site', 'out', file_get_contents($outside . '/secret.txt'));
pin('no symlink is made from an entry', false, is_link($content . '/sym') || file_exists($content . '/sym'));
pin('files not in the backup are left in place', 'plugins/a/b.php', file_get_contents($content . '/plugins/a/b.php'));

$archive = new BackupArchive($base . '/crafted.zip');
$step    = $archive->extract($real, $sreal, 0, 3, microtime(true) + 20);
$archive->close();
pin('a batch stops at its size and says where to go on', array(3, false), array($step['next'], $step['done']));

exit(harness_result());

/* file end: ./tests/backup-archive.php */
