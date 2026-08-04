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
     *  2 - zip is empty
     *  -1 : file could not be created (or error reading the file from the zip)
     * @throws \Exception
     */
    public function unzipFile($file, $to)
    {
        if ($this->isPathValid($to)) {
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
     * check given path validity, it shouldn't start with ../
     *
     * @param $path
     *
     * @return bool
     */
    private function isPathValid($path)
    {
        return !(strpos($path, '../') !== 0 || strpos($path, "..\\") !== 0);
    }

    /**
     * We assume that the $to path is correct and can be written. It unzips an archive using the PclZip library.
     *
     * @param string $zipFile Full path of the zip file
     * @param string $to      Full path where it is going to be unzipped
     *
     * @return int
     *  0 - destination folder not writable (or not exist and cannot be created)
     *  1 - everything was OK
     *  2 - zip is empty
     *  -1 : file could not be created (or error reading the file from the zip)
     * @throws \Exception
     */
    private function unzipFileZipArchive($zipFile, $to)
    {
        $zip     = new ZipArchive();
        $zipOpen = $zip->open($zipFile, 4);

        if ($zipOpen !== true) {
            return 2;
        }
        // The zip is empty
        if ($zip->numFiles === 0) {
            return 2;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $file = $zip->statIndex($i);

            if (!$file) {
                return -1;
            }

            if (strpos($file['name'], '__MACOSX/') === 0) {
                continue;
            }
            if (strpos($file['name'], '../') !== false) {
                continue;
            }

            if (substr($file['name'], -1) === '/') {
                $this->FileSystem->mkdir($concurrentDirectory = $to . '/' . $file['name'], 0755);
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                return -1;
            }

            $this->FileSystem->writeToFile($to . '/' . $file['name'], $content);
        }

        $zip->close();

        return 1;
    }

    /**
     * We assume that the $to path is correct and can be written. It unzips an archive using the PclZip library.
     *
     * @param string $zip_file Full path of the zip file
     * @param string $to       Full path where it is going to be unzipped
     *
     * @return int
     *  0 - destination folder not writable (or not exist and cannot be created)
     *  1 - everything was OK
     *  2 - zip is empty
     *  -1 : file could not be created (or error reading the file from the zip)
     * @throws \Exception
     */
    private function unzipFilePclZip($zip_file, $to)
    {
        $archive = new PclZip($zip_file);
        $files   = $archive->extract(PCLZIP_OPT_EXTRACT_AS_STRING);
        if (($files) === 0) {
            return 2;
        }

        // check if the zip is not empty
        if (count($files) === 0) {
            return 2;
        }

        // Extract the files from the zip
        foreach ($files as $file) {
            if (strpos($file['filename'], '__MACOSX/') === 0) {
                continue;
            }
            if (strpos($file['filename'], '../') !== false) {
                continue;
            }

            if ($file['folder']) {
                $this->FileSystem->mkdir($concurrentDirectory = $to . '/' . $file['filename'], 0755);
                continue;
            }

            $this->FileSystem->writeToFile($to . '/' . $file['filename'], $file['content']);
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
        if ($this->isPathValid($archive_folder) || $this->isPathValid($archive_name)) {
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
