<?php
/**
 * Created by Mindstellar Community.
 * User: navjottomer
 * Date: 08/05/20
 * Time: 2:43 PM
 * License is provided in root directory.
 */

namespace mindstellar\utility;

use PclZip;
use ZipArchive;

/**
 * Class Zip
 *
 * @package mindstellar\utility
 */
class Zip
{
    /**
     * Reject an archive containing more entries than this.
     */
    private const MAX_ENTRIES = 20000;

    /**
     * Reject a single entry whose uncompressed size exceeds this many bytes (100 MiB).
     */
    private const MAX_ENTRY_UNCOMPRESSED_BYTES = 104857600;

    /**
     * Reject an archive whose combined uncompressed size exceeds this many bytes (300 MiB).
     */
    private const MAX_TOTAL_UNCOMPRESSED_BYTES = 314572800;

    /**
     * Reject an entry whose uncompressed size is more than this many times its compressed size.
     */
    private const MAX_COMPRESSION_RATIO = 200;

    /**
     * Entries smaller than this are exempt from the ratio check; small files legitimately
     * compress at high ratios (e.g. a repetitive JSON/CSS file) and would otherwise false-positive.
     */
    private const RATIO_CHECK_MIN_UNCOMPRESSED_BYTES = 4096;

    /**
     * @var \mindstellar\utility\FileSystem
     */
    private $FileSystem;

    public function __construct()
    {
        $this->FileSystem = new FileSystem();
    }

    /**
     * Unzip's a specified ZIP file to a location
     *
     * @param string $file Full path of the zip file
     * @param string $to   Full path where it is going to be unzipped
     *
     * @return int
     *  0 - destination folder not writable (or not exist and cannot be created)
     *  1 - everything was OK
     *  2 - zip is empty, invalid, or contains an unsafe entry (path traversal, symlink,
     *      or an entry over the size/ratio limits) — the whole archive is rejected
     *  -1 : file could not be created (or error reading the file from the zip)
     * @throws \Exception
     */
    public function unzipFile($file, $to)
    {
        if (!$this->isPathValid($to)) {
            return 0;
        }

        if (!file_exists($to) && !is_dir($to)) {
            $this->FileSystem->mkdir($to, 0766);

            $this->FileSystem->chmod($to, 0755);
        }

        if (!is_writable($to)) {
            return 0;
        }

        if (class_exists('ZipArchive')) {
            return $this->unzipFileZipArchive($file, $to);
        }

        // if ZipArchive class doesn't exist, we use PclZip
        return $this->unzipFilePclZip($file, $to);
    }

    /**
     * Coarse sanity check on a path supplied by our own calling code (never on zip entry
     * names — those are validated per-entry against the resolved destination realpath).
     *
     * @param $path
     *
     * @return bool true when the path contains no traversal segment
     */
    private function isPathValid($path)
    {
        $normalized = str_replace('\\', '/', (string) $path);

        return $normalized !== ''
            && strpos($normalized, "\0") === false
            && $normalized !== '..'
            && strpos($normalized, '../') === false
            && strpos($normalized, '/..') === false;
    }

    /**
     * Resolve a raw zip entry name against the destination realpath and confirm it stays
     * contained within it. Strips Windows drive prefixes, normalises backslashes, rejects
     * absolute paths, and collapses "." / ".." segments lexically (the entry's target does
     * not exist yet, so it cannot be realpath()'d) before comparing prefixes.
     *
     * @param string $rawName             Entry name as stored in the archive
     * @param string $destinationRealPath realpath() of the extraction destination, no trailing slash
     *
     * @return string|false absolute target path, or false if the entry is unsafe
     */
    private function resolveEntryTarget(string $rawName, string $destinationRealPath)
    {
        $name = str_replace('\\', '/', $rawName);

        if ($name === '' || strpos($name, "\0") !== false) {
            return false;
        }

        // A Windows drive prefix ("C:/...") smuggles an absolute path into an entry name
        // even when the rest of the string looks relative.
        if (preg_match('#^[a-zA-Z]:/#', $name) === 1) {
            return false;
        }

        if (strpos($name, '/') === 0) {
            return false;
        }

        $safeParts = [];
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (empty($safeParts)) {
                    return false;
                }
                array_pop($safeParts);
                continue;
            }
            $safeParts[] = $segment;
        }

        if (empty($safeParts)) {
            return false;
        }

        $target = $destinationRealPath . '/' . implode('/', $safeParts);

        if (strpos($target, $destinationRealPath . '/') !== 0) {
            return false;
        }

        return $target;
    }

    /**
     * Check a single entry's uncompressed size, running total, and compression ratio
     * against the named caps. Does not mutate $totalUncompressedSoFar; the caller adds
     * the entry's size once it has also passed the path-containment check.
     *
     * @return bool true when the entry is within all limits
     */
    private function entryWithinLimits(int $uncompressedSize, int $compressedSize, int $totalUncompressedSoFar): bool
    {
        if ($uncompressedSize < 0 || $compressedSize < 0) {
            return false;
        }

        if ($uncompressedSize > self::MAX_ENTRY_UNCOMPRESSED_BYTES) {
            return false;
        }

        if (($totalUncompressedSoFar + $uncompressedSize) > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
            return false;
        }

        if ($uncompressedSize > self::RATIO_CHECK_MIN_UNCOMPRESSED_BYTES) {
            if ($compressedSize <= 0 || ($uncompressedSize / $compressedSize) > self::MAX_COMPRESSION_RATIO) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool true when the ZipArchive entry's unix mode marks it as a symlink
     */
    private function isZipArchiveEntrySymlink(ZipArchive $zip, int $index): bool
    {
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }
        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }
        $unixMode = ($attr >> 16) & 0xFFFF;

        return ($unixMode & 0xF000) === 0xA000; // S_IFLNK
    }

    /**
     * We assume that the $to path is correct and can be written. It unzips an archive using
     * the ZipArchive extension.
     *
     * @param string $zipFile Full path of the zip file
     * @param string $to      Full path where it is going to be unzipped
     *
     * @return int see unzipFile()
     * @throws \Exception
     */
    private function unzipFileZipArchive($zipFile, $to)
    {
        $destinationRealPath = realpath($to);
        if ($destinationRealPath === false) {
            return -1;
        }
        $destinationRealPath = rtrim(str_replace('\\', '/', $destinationRealPath), '/');

        $zip     = new ZipArchive();
        $zipOpen = $zip->open($zipFile, ZipArchive::CHECKCONS);

        if ($zipOpen !== true) {
            return 2;
        }
        // The zip is empty
        if ($zip->numFiles === 0) {
            $zip->close();

            return 2;
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            error_log(sprintf(
                'Zip::unzipFile rejected "%s": %d entries exceeds the %d cap.',
                $zipFile,
                $zip->numFiles,
                self::MAX_ENTRIES
            ));
            $zip->close();

            return 2;
        }

        // Pass 1: validate every entry (path containment, symlink, size/ratio) from metadata
        // only — nothing is written yet — so an unsafe entry anywhere in the archive rejects
        // the whole thing instead of leaving the entries before it already on disk.
        $totalUncompressed = 0;
        $targets           = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if (!$stat) {
                $zip->close();

                return -1;
            }

            if (strpos($stat['name'], '__MACOSX/') === 0) {
                continue;
            }

            if ($this->isZipArchiveEntrySymlink($zip, $i)) {
                error_log(sprintf('Zip::unzipFile rejected "%s": entry "%s" is a symlink.', $zipFile, $stat['name']));
                $zip->close();

                return 2;
            }

            if (!$this->entryWithinLimits((int) $stat['size'], (int) $stat['comp_size'], $totalUncompressed)) {
                error_log(sprintf(
                    'Zip::unzipFile rejected "%s": entry "%s" exceeds the size/ratio limits.',
                    $zipFile,
                    $stat['name']
                ));
                $zip->close();

                return 2;
            }

            $target = $this->resolveEntryTarget($stat['name'], $destinationRealPath);
            if ($target === false) {
                error_log(sprintf(
                    'Zip::unzipFile rejected "%s": entry "%s" escapes the destination directory.',
                    $zipFile,
                    $stat['name']
                ));
                $zip->close();

                return 2;
            }

            $totalUncompressed += (int) $stat['size'];
            $targets[$i]       = [$target, substr($stat['name'], -1) === '/'];
        }

        // Pass 2: every entry passed validation, now it's safe to read content and write it.
        foreach ($targets as $i => [$target, $isDir]) {
            if ($isDir) {
                $this->FileSystem->mkdir($target, 0755);
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $zip->close();

                return -1;
            }

            $this->FileSystem->writeToFile($target, $content);
        }

        $zip->close();

        return 1;
    }

    /**
     * We assume that the $to path is correct and can be written. It unzips an archive using
     * the PclZip library.
     *
     * Entries are validated against listContent() metadata before extract() is called, so
     * an oversized or path-unsafe entry is rejected before PclZip decompresses it into memory.
     *
     * @param string $zip_file Full path of the zip file
     * @param string $to       Full path where it is going to be unzipped
     *
     * @return int see unzipFile()
     * @throws \Exception
     */
    private function unzipFilePclZip($zip_file, $to)
    {
        $destinationRealPath = realpath($to);
        if ($destinationRealPath === false) {
            return -1;
        }
        $destinationRealPath = rtrim(str_replace('\\', '/', $destinationRealPath), '/');

        $archive = new PclZip($zip_file);
        $listing = $archive->listContent();

        if ($listing === 0 || empty($listing)) {
            return 2;
        }

        if (count($listing) > self::MAX_ENTRIES) {
            error_log(sprintf(
                'Zip::unzipFile rejected "%s": %d entries exceeds the %d cap.',
                $zip_file,
                count($listing),
                self::MAX_ENTRIES
            ));

            return 2;
        }

        // PclZip's public API (listContent()/extract()) never surfaces the unix mode / external
        // attributes, so a symlink entry cannot be distinguished here; extraction below only
        // ever calls writeToFile()/mkdir(), never symlink(), so a "symlink" entry lands as a
        // plain file holding its link-target text rather than an actual filesystem symlink.
        $totalUncompressed = 0;
        foreach ($listing as $entry) {
            if (strpos($entry['filename'], '__MACOSX/') === 0) {
                continue;
            }

            if (!$this->entryWithinLimits((int) $entry['size'], (int) $entry['compressed_size'], $totalUncompressed)) {
                error_log(sprintf(
                    'Zip::unzipFile rejected "%s": entry "%s" exceeds the size/ratio limits.',
                    $zip_file,
                    $entry['filename']
                ));

                return 2;
            }

            if ($this->resolveEntryTarget($entry['filename'], $destinationRealPath) === false) {
                error_log(sprintf(
                    'Zip::unzipFile rejected "%s": entry "%s" escapes the destination directory.',
                    $zip_file,
                    $entry['filename']
                ));

                return 2;
            }

            $totalUncompressed += (int) $entry['size'];
        }

        // Extract the files from the zip
        $files = $archive->extract(PCLZIP_OPT_EXTRACT_AS_STRING);
        if ($files === 0 || empty($files)) {
            return 2;
        }

        foreach ($files as $file) {
            if (strpos($file['filename'], '__MACOSX/') === 0) {
                continue;
            }

            $target = $this->resolveEntryTarget($file['filename'], $destinationRealPath);
            if ($target === false) {
                // Already validated above against the same destination; a mismatch here
                // means the archive changed between the two reads, so reject it outright.
                return 2;
            }

            if ($file['folder']) {
                $this->FileSystem->mkdir($target, 0755);
                continue;
            }

            $this->FileSystem->writeToFile($target, $file['content']);
        }

        return 1;
    }

    /**
     * Common interface to zip a specified folder to a file using ziparchive or pclzip
     *
     * @param string $archive_folder full path of the folder
     * @param string $archive_name   full path of the destination zip file
     *
     * @return int
     */
    public function zipFolder($archive_folder, $archive_name)
    {
        if (!$this->isPathValid($archive_folder) || !$this->isPathValid($archive_name)) {
            return false;
        }

        if (class_exists('ZipArchive')) {
            return $this->zipFolderZipArchive($archive_folder, $archive_name);
        }

        // if ZipArchive class doesn't exist, we use PclZip
        return $this->zipFolderPclZip($archive_folder, $archive_name);
    }

    /**
     * Zips a specified folder to a file
     *
     * @param string $archive_folder full path of the folder
     * @param string $archive_name   full path of the destination zip file
     *
     * @return bool
     */
    private function zipFolderZipArchive($archive_folder, $archive_name)
    {
        $zip = new ZipArchive();
        if ($zip->open($archive_name, ZipArchive::CREATE) === true) {
            $dir = preg_replace('/[\/]{2,}/', '/', $archive_folder . '/');

            $dirs = array($dir);
            while (count($dirs)) {
                $dir = current($dirs);
                $zip->addEmptyDir(str_replace(ABS_PATH, '', $dir));

                $dh = opendir($dir);
                while (false !== ($_file = readdir($dh))) {
                    if ($_file !== '.' && $_file !== '..' && stripos($_file, 'Osclass_backup.') === false) {
                        if (is_file($dir . $_file)) {
                            $zip->addFile($dir . $_file, str_replace(ABS_PATH, '', $dir . $_file));
                        } elseif (is_dir($dir . $_file)) {
                            $dirs[] = $dir . $_file . '/';
                        }
                    }
                }
                closedir($dh);
                array_shift($dirs);
            }
            $zip->close();

            return true;
        }

        return false;
    }

    /**
     * Zips a specified folder to a file
     *
     * @param string $archive_folder full path of the folder
     * @param string $archive_name   full path of the destination zip file
     *
     * @return bool
     */
    private function zipFolderPclZip($archive_folder, $archive_name)
    {
        $zip = new PclZip($archive_name);
        if ($zip) {
            $dir = preg_replace('/[\/]{2,}/', '/', $archive_folder . '/');

            $v_dir    = osc_base_path();
            $v_remove = $v_dir;

            // To support windows and the C: root you need to add the
            // following 3 lines, should be ignored on linux
            if ($v_dir[1] === ':') {
                $v_remove = substr($v_dir, 2);
            }
            $v_list = $zip->create($dir, PCLZIP_OPT_REMOVE_PATH, $v_remove);

            return !($v_list === 0);
        }

        return false;
    }
}
