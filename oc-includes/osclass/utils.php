<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\utility\Deprecate;
use mindstellar\utility\FileSystem;
use mindstellar\utility\Utils;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\POP3;
use ReCaptcha\ReCaptcha;

/**
 * check if the item is expired
 *
 * @param string $dt_expiration Datetime the listing expires
 *
 * @return bool
 */
function osc_isExpired($dt_expiration)
{
    $now = date('YmdHis');

    $dt_expiration = str_replace(array(' ', '-', ':'), '', $dt_expiration);

    return !($dt_expiration > $now);
}

/**
 * Remove resources from disk
 *
 * @param int|array<int,int>       $id       Resource id; an array uses its first element
 * @param bool                     $admin    Whether the deletion is being logged as an admin action
 * @param array<string,mixed>|null $resource Resource row the caller already read; looked up when omitted
 *
 * @return false|void False on a demo install, where nothing is deleted
 */
function osc_deleteResource($id, $admin, $resource = null)
{
    if (defined('DEMO')) {
        return false;
    }
    if (is_array($id)) {
        $id = $id[0];
    }
    // $resource lets a caller hand over the row it already read. Deleting a listing
    // removes the resource rows inside a transaction and only unlinks the files once
    // that commits, by which point this could no longer look the row up — and without
    // the row there are no paths to remove and no resource to hand to the hook.
    if (!is_array($resource)) {
        $resource = ItemResource::newInstance()->findByPrimaryKey($id);
    }
    if ($resource !== null) {
        Log::newInstance()->insertLog(
            'item',
            'delete resource',
            $resource['pk_i_id'],
            $id,
            $admin ? 'admin' : 'user',
            $admin ? osc_logged_admin_id() : osc_logged_user_id()
        );

        $backtracel = '';
        foreach (debug_backtrace() as $k => $v) {
            if ($v['function'] === 'include' || $v['function'] === 'include_once' || $v['function'] === 'require_once'
                || $v['function'] === 'require'
            ) {
                $backtracel .= '#' . $k . ' ' . $v['function'] . '(' . $v['args'][0] . ') called@ [' . $v['file'] . ':'
                               . $v['line'] . '] / ';
            } else {
                $backtracel .= '#' . $k . ' ' . $v['function'] . ' called@ [' . $v['file'] . ':' . $v['line'] . '] / ';
            }
        }

        Log::newInstance()->insertLog(
            'item',
            'delete resource backtrace',
            $resource['pk_i_id'],
            $backtracel,
            $admin ? 'admin' : 'user',
            $admin ? osc_logged_admin_id() : osc_logged_user_id()
        );
        // check if resource s_path, pk_i_id and s_extension are set and not empty
        if (($resource['s_storage'] ?? 'local') === 'local'
            && \mindstellar\storage\StorageManager::instance()->remote() === null) {
            try {
                $filesToRemove = [
                    $resource['s_path'] . $resource['pk_i_id'] . '.' . $resource['s_extension'],
                    $resource['s_path'] . $resource['pk_i_id'] . '_original.' . $resource['s_extension'],
                    $resource['s_path'] . $resource['pk_i_id'] . '_thumbnail.' . $resource['s_extension'],
                    $resource['s_path'] . $resource['pk_i_id'] . '_preview.' . $resource['s_extension'],
                ];
                foreach ($filesToRemove as $file) {
                    if (file_exists($file) && !is_dir($file)) {
                        (new mindstellar\utility\FileSystem())->remove($file);
                    }
                }
            } catch (Exception $e) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }
        } else {
            \StorageQueue::newInstance()->enqueue('delete', $resource['s_storage'] ?? 'local', $resource);
        }
        osc_run_hook('delete_resource', $resource);
    }
}

/**
 * Tries to delete the directory recursively.
 *
 * @param string $path
 *
 * @return bool False on a path containing .., a path that is not a directory, or a failed removal
 */
function osc_deleteDir($path)
{
    return (new FileSystem())->deleteDir($path);
}

/**
 * Serialize the data (usefull at plugins activation)
 *
 * @param mixed $data
 *
 * @return mixed The serialized string, or the value unchanged when it is neither array nor object
 */
function osc_serialize($data)
{
    if (!is_serialized($data)) {
        if (is_array($data) || is_object($data)) {
            return serialize($data);
        }
    }

    return $data;
}

/**
 * Unserialize the data (usefull at plugins activation)
 *
 * @param mixed $data
 *
 * @return mixed The unserialized value, false on a malformed payload, or the value unchanged
 */
function osc_unserialize($data)
{
    if (is_serialized($data)) { // don't attempt to unserialize data that wasn't serialized going in
        return @unserialize($data);
    }

    return $data;
}

/**
 * Checks is $data is serialized or not
 *
 * @param mixed $data
 *
 * @return bool False if not serialized and true if it was.
 */
function is_serialized($data)
{
    // if it isn't a string, it isn't serialized
    if (!is_string($data)) {
        return false;
    }
    $data = trim($data);
    if ('N;' === $data) {
        return true;
    }
    if (!preg_match('/^([adObis]):/', $data, $badions)) {
        return false;
    }
    switch ($badions[1]) {
        case 'a':
        case 'O':
        case 's':
            if (preg_match("/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data)) {
                return true;
            }
            break;
        case 'b':
        case 'i':
        case 'd':
            if (preg_match("/^{$badions[1]}:[0-9.E-]+;\$/", $data)) {
                return true;
            }
            break;
    }

    return false;
}

/**
 * VERY BASIC
 * Perform a POST request, so we could launch fake-cron calls and other core-system calls without annoying the user
 *
 * @param string             $url
 * @param array<string,mixed> $_data
 *
 * @return bool|int False on error, or the number of bytes sent.
 */
function osc_doRequest($url, $_data)
{
    return Utils::doRequest($url, $_data);
}

/**
 * Send one email through PHPMailer, using the site's configured mail transport.
 *
 * @param array<string,mixed> $params from, to, to_name, subject, body, alt_body and optional attachment/reply-to keys
 *
 * @return bool False on a demo install or when the send fails
 */
function osc_sendMail($params)
{
    // DO NOT send mail if it's a demo
    if (defined('DEMO')) {
        return false;
    }

    $mail = new PHPMailer(true);
    $mail->clearAddresses();
    $mail->clearAllRecipients();
    $mail->clearAttachments();
    $mail->clearBCCs();
    $mail->clearCCs();
    $mail->clearCustomHeaders();
    $mail->clearReplyTos();

    /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
    $mail = osc_apply_filter('init_send_mail', $mail, $params);

    if (osc_mailserver_pop()) {
        $pop = new POP3();

        $pop3_host = osc_mailserver_host();
        if (array_key_exists('host', $params)) {
            $pop3_host = $params['host'];
        }

        $pop3_port = osc_mailserver_port();
        if (array_key_exists('port', $params)) {
            $pop3_port = $params['port'];
        }

        $pop3_username = osc_mailserver_username();
        if (array_key_exists('username', $params)) {
            $pop3_username = $params['username'];
        }

        $pop3_password = osc_mailserver_password();
        if (array_key_exists('password', $params)) {
            $pop3_password = $params['password'];
        }

        $pop->authorise($pop3_host, $pop3_port, 30, $pop3_username, $pop3_password);
    }

    if (osc_mailserver_auth()) {
        $mail->isSMTP();
        $mail->SMTPAuth = true;
    } elseif (osc_mailserver_pop()) {
        $mail->isSMTP();
    }

    $smtpSecure = osc_mailserver_ssl();
    if (array_key_exists('password', $params)) {
        $smtpSecure = $params['ssl'];
    }
    if ($smtpSecure != '') {
        $mail->SMTPSecure = $smtpSecure;
    }

    $stmpUsername = osc_mailserver_username();
    if (array_key_exists('username', $params)) {
        $stmpUsername = $params['username'];
    }
    if ($stmpUsername != '') {
        $mail->Username = $stmpUsername;
    }

    $smtpPassword = osc_mailserver_password();
    if (array_key_exists('password', $params)) {
        $smtpPassword = $params['password'];
    }
    if ($smtpPassword != '') {
        $mail->Password = $smtpPassword;
    }

    $smtpHost = osc_mailserver_host();
    if (array_key_exists('host', $params)) {
        $smtpHost = $params['host'];
    }
    if ($smtpHost != '') {
        $mail->Host = $smtpHost;
    }

    $smtpPort = osc_mailserver_port();
    if (array_key_exists('port', $params)) {
        $smtpPort = $params['port'];
    }
    if ($smtpPort != '') {
        $mail->Port = $smtpPort;
    }

    $from = osc_mailserver_mail_from();
    if (empty($from)) {
        $from = 'osclass@' . osc_get_domain();
        if (array_key_exists('from', $params)) {
            $from = $params['from'];
        }
    }

    $from_name = osc_mailserver_name_from();
    if (empty($from_name)) {
        $from_name = osc_page_title();
        if (array_key_exists('from_name', $params)) {
            $from_name = $params['from_name'];
        }
    }

    $mail->From     = osc_apply_filter('mail_from', $from, $params);
    $mail->FromName = osc_apply_filter('mail_from_name', $from_name, $params);

    // With exceptions enabled (new PHPMailer(true)) a malformed recipient throws from
    // addAddress()/addBCC()/addReplyTo() before send() is reached, and send() itself
    // throws on a transport failure. Guard the whole dispatch so a bad address or a
    // dead mailserver degrades to a logged warning and a false return, never a 500.
    try {
        $to      = $params['to'];
        $to_name = '';
        if (array_key_exists('to_name', $params)) {
            $to_name = $params['to_name'];
        }

        if (!is_array($to)) {
            $to = array($to => $to_name);
        }

        foreach ($to as $to_email => $to_name) {
            $mail->addAddress($to_email, $to_name);
        }

        if (array_key_exists('add_bcc', $params)) {
            if (!is_array($params['add_bcc']) && $params['add_bcc'] != '') {
                $params['add_bcc'] = array($params['add_bcc']);
            }

            foreach ($params['add_bcc'] as $bcc) {
                $mail->addBCC($bcc);
            }
        }

        if (array_key_exists('reply_to', $params)) {
            $mail->addReplyTo($params['reply_to']);
        }

        $mail->Subject = $params['subject'];
        $mail->Body    = $params['body'];

        if (array_key_exists('attachment', $params)) {
            if (!is_array($params['attachment']) || isset($params['attachment']['path'])) {
                $params['attachment'] = array($params['attachment']);
            }

            foreach ($params['attachment'] as $attachment) {
                if (is_array($attachment)) {
                    if (isset($attachment['path']) && isset($attachment['name'])) {
                        try {
                            $mail->addAttachment($attachment['path'], $attachment['name']);
                        } catch (\PHPMailer\PHPMailer\Exception $e) {
                            continue;
                        }
                    }
                } else {
                    try {
                        $mail->addAttachment($attachment);
                    } catch (\PHPMailer\PHPMailer\Exception $e) {
                        continue;
                    }
                }
            }
        }

        $mail->CharSet = 'utf-8';
        $mail->isHTML();

        $mail = osc_apply_filter('pre_send_mail', $mail, $params);

        // send email!
        $mail->send();
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        trigger_error($e->errorMessage(), E_USER_WARNING);

        return false;
    }

    return true;
}

/**
 * Expand the {PLACEHOLDER} tokens in an email body: the caller's pairs first, then
 * the site-wide ones.
 *
 * @param string                             $text
 * @param array{0:array<int,string>,1:array<int,string>} $params Search list and its replacement list
 *
 * @return string
 */
function osc_mailBeauty($text, $params)
{
    $text   = str_ireplace($params[0], $params[1], $text);
    $kwords = array(
        '{WEB_URL}',
        '{WEB_TITLE}',
        '{WEB_LINK}',
        '{CURRENT_DATE}',
        '{HOUR}',
        '{IP_ADDRESS}'
    );
    $rwords = array(
        osc_base_url(),
        osc_page_title(),
        '<a href="' . osc_base_url() . '">' . osc_page_title() . '</a>',
        date(osc_date_format() ?: 'Y-m-d') . ' ' . date(osc_time_format() ?: 'H:i:s'),
        date(osc_time_format() ?: 'H:i'),
        Params::getServerParam('REMOTE_ADDR')
    );
    $text   = str_ireplace($kwords, $rwords, $text);

    return $text;
}

/**
 * Create a directory, warning instead of throwing when it cannot be created.
 *
 * @param string $dir
 * @param int    $mode
 * @param bool   $recursive Accepted for backwards compatibility; the filesystem helper always recurses
 *
 * @return bool
 */
function osc_mkdir($dir, $mode = 0755, $recursive = true)
{

    try {
        (new FileSystem())->mkdir($dir, $mode);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);

        return false;
    }

    return true;
}

/**
 * Copy a file, warning instead of throwing on failure.
 *
 * @param string $source
 * @param string $dest
 *
 * @return bool
 */
function osc_copy($source, $dest)
{
    try {
        (new FileSystem())->copy($source, $dest);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);

        return false;
    }

    return true;
}

/**
 * Copy a file by reading it whole and writing it out again.
 *
 * @param string $file1 Source path
 * @param string $file2 Destination path
 *
 * @return bool False when the source could not be read
 * @deprecated since 4.0.0
 */
function osc_copyemz($file1, $file2)
{
    $contentx   = @file_get_contents($file1);
    $openedfile = fopen($file2, 'wb');
    fwrite($openedfile, $contentx);
    fclose($openedfile);
    if ($contentx === false) {
        $status = false;
    } else {
        $status = true;
    }

    return $status;
}

/**
 * Dump osclass database into path file
 *
 * @param string $path Directory to write into, with a trailing separator
 * @param string $file Filename to write
 *
 * @return int 1 on success; -1 empty path, -2 no dump handle, -3 no tables, -4 path not writable
 */
function osc_dbdump($path, $file)
{
    if (!is_writable($path)) {
        return -4;
    }
    if ($path == '') {
        return -1;
    }

    //checking connection
    $dump = Dump::newInstance();
    if (!$dump) {
        return -2;
    }

    $path       .= $file;
    $result     = $dump->showTables();
    $fileSystem = new FileSystem();
    if (!$result) {
        $_str = '';
        $_str .= '/* no tables in ' . DB_NAME . ' */';
        $_str .= "\n";

        try {
            $fileSystem->writeToFile($path, $_str, true);
        } catch (Exception $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
        }

        return -3;
    }

    $_str =
        '/* OSCLASS MYSQL Autobackup ('
        . date(osc_date_format() ?: 'Y-m-d')
        . ' '
        . date(osc_time_format() ?: 'H:i:s')
        . ') */' . "\n";

    try {
        $fileSystem->writeToFile($path, $_str, true);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);
    }

    $tables = array();
    foreach ($result as $_table) {
        $tableName          = current($_table);
        $tables[$tableName] = $tableName;
    }

    $tables_order = array(
        't_locale',
        't_country',
        't_currency',
        't_region',
        't_city',
        't_city_area',
        't_widget',
        't_admin',
        't_user',
        't_user_description',
        't_category',
        't_category_description',
        't_category_stats',
        't_item',
        't_item_description',
        't_item_location',
        't_item_stats',
        't_item_stats_daily',
        't_item_resource',
        't_item_comment',
        't_preference',
        't_pages',
        't_pages_description',
        't_plugin_category',
        't_cron',
        't_alerts',
        't_meta_fields',
        't_meta_categories',
        't_item_meta'
    );
    // Backup default Shopclass tables in order, so no problem when importing them back
    foreach ($tables_order as $table) {
        if (array_key_exists(DB_TABLE_PREFIX . $table, $tables)) {
            $dump->table_structure($path, DB_TABLE_PREFIX . $table);
            $dump->table_data($path, DB_TABLE_PREFIX . $table);
            unset($tables[DB_TABLE_PREFIX . $table]);
        }
    }

    // Backup the rest of tables
    foreach ($tables as $table) {
        $dump->table_structure($path, $table);
        $dump->table_data($path, $table);
    }

    return 1;
}

/**
 * Returns true if there is curl on system environment
 *
 * @return bool
 * @deprecated since 4.0.0
 */
function testCurl()
{
    return !(!function_exists('curl_init') || !function_exists('curl_exec'));
}

/**
 * Returns true if there is fsockopen on system environment
 *
 * @return bool
 * @deprecated since 4.0.0
 */
function testFsockopen()
{
    if (!function_exists('fsockopen')) {
        return false;
    }

    return true;
}

/**
 * IF http-chunked-decode not exist implement here
 *
 * @since 3.0
 */
if (!function_exists('http_chunked_decode')) {
    /**
     * dechunk an http 'transfer-encoding: chunked' message
     *
     * @param string $chunk the encoded message
     *
     * @return string the decoded message.  If $chunk wasn't encoded properly it will be returned unmodified.
     */
    function http_chunked_decode($chunk)
    {
        $pos     = 0;
        $len     = strlen($chunk);
        $dechunk = null;
        while (($pos < $len)
               && ($chunkLenHex = substr(
                   $chunk,
                   $pos,
                   ($newlineAt = strpos($chunk, "\n", $pos + 1)) - $pos
               ))) {
            if (!is_hex($chunkLenHex)) {
                trigger_error('Value is not properly chunk encoded', E_USER_WARNING);

                return $chunk;
            }

            $pos      = $newlineAt + 1;
            $chunkLen = hexdec(rtrim($chunkLenHex, "\r\n"));
            $dechunk  .= substr($chunk, $pos, $chunkLen);
            $pos      = strpos($chunk, "\n", $pos + $chunkLen) + 1;
        }

        return $dechunk;
    }
}

/**
 * determine if a string can represent a number in hexadecimal
 *
 * @param string $hex
 *
 * @return boolean true if the string is a hex, otherwise false
 * @since 3.0
 *
 */
function is_hex($hex)
{
    // regex is for weenies
    $hex = strtolower(trim(ltrim($hex, '0')));
    if (empty($hex)) {
        $hex = 0;
    }
    $dec = hexdec($hex);

    return ($hex === dechex($dec));
}

/**
 * Process response and return headers and body
 *
 * @param string $content
 *
 * @return array{headers?:string,body?:string} Empty when the response has no header block
 * @since 3.0
 */
function processResponse($content)
{
    $res     = explode("\r\n\r\n", $content);
    $headers = $res[0];
    $body    = isset($res[1]) ? $res[1] : '';

    if (!is_string($headers)) {
        return array();
    }

    return array('headers' => $headers, 'body' => $body);
}

/**
 * Parse headers and return into array format
 *
 * @param string $headers
 *
 * @return array<string,string> Lower-cased header name => value
 * @deprecated since 4.0.0
 */
function processHeaders($headers)
{
    $headers    = str_replace("\r\n", "\n", $headers);
    $headers    = preg_replace('/\n[ \t]/', ' ', $headers);
    $headers    = explode("\n", $headers);
    $tmpHeaders = $headers;
    $headers    = array();

    foreach ($tmpHeaders as $aux) {
        if (preg_match('/^(.*):\s(.*)$/', $aux, $matches)) {
            $headers[strtolower($matches[1])] = $matches[2];
        }
    }

    return $headers;
}

/**
 * Download file using fsockopen
 *
 * @param string                   $sourceFile
 * @param string|null              $fileout   Destination path; null returns the body instead
 * @param array<string,mixed>|null $post_data POST body; null issues a GET
 *
 * @return bool|string
 * @since      3.0
 * @deprecated since 4.0.0
 */
function download_fsockopen($sourceFile, $fileout = null, $post_data = null)
{
    // parse URL
    $aUrl = parse_url($sourceFile);
    $host = $aUrl['host'];
    if ('localhost' === strtolower($host)) {
        $host = '127.0.0.1';
    }

    $link = $aUrl['path'] . (isset($aUrl['query']) ? '?' . $aUrl['query'] : '');

    if (empty($link)) {
        $link .= '/';
    }

    $fp = @fsockopen($host, 80, $errno, $errstr, 30);
    if (!$fp) {
        return false;
    }

    $ua  = Params::getServerParam('HTTP_USER_AGENT') . ' Shopclass (v.' . OSCLASS_VERSION . ')';
    $out = ($post_data != null && is_array($post_data) ? 'POST' : 'GET') . " $link HTTP/1.1\r\n";
    $out .= "Host: $host\r\n";
    $out .= "User-Agent: $ua\r\n";
    $out .= "Connection: Close\r\n\r\n";
    $out .= "\r\n";
    if ($post_data != null && is_array($post_data)) {
        $out .= http_build_query($post_data);
    }
    fwrite($fp, $out);

    $contents = '';
    while (!feof($fp)) {
        $contents .= fgets($fp, 1024);
    }

    fclose($fp);

    // check redirections ?
    // if (redirections) then do request again
    $aResult = processResponse($contents);
    $headers = processHeaders($aResult['headers']);

    $location = @$headers['location'];
    if (isset($location) && $location != '') {
        $aUrl = parse_url($headers['location']);

        $host = $aUrl['host'];
        if ('localhost' === strtolower($host)) {
            $host = '127.0.0.1';
        }

        $requestPath = $aUrl['path'] . (isset($aUrl['query']) ? '?' . $aUrl['query'] : '');

        if (empty($requestPath)) {
            $requestPath .= '/';
        }

        download_fsockopen($host, $requestPath, $fileout);
    } else {
        $body             = $aResult['body'];
        $transferEncoding = @$headers['transfer-encoding'];
        if ($transferEncoding === 'chunked') {
            $body = http_chunked_decode($aResult['body']);
        }
        if ($fileout != null) {
            $ff = @fopen($fileout, 'wb+');
            if ($ff !== false) {
                fwrite($ff, $body);
                fclose($ff);

                return true;
            }

            return false;
        }

        return $body;
    }

    return false;
}

/**
 * Download a URL to a local path, warning instead of throwing on failure.
 *
 * @param string                   $sourceFile     URL to fetch
 * @param string                   $downloadedFile Destination path
 * @param array<string,mixed>|null $post_data      POST body; null issues a GET
 *
 * @return bool False on a 404, a truncated transfer or a checksum mismatch
 */
function osc_downloadFile($sourceFile, $downloadedFile, $post_data = null)
{
    try {
        // downloadFile() reports a 404, a truncated transfer or a checksum mismatch by
        // returning false, so the return value has to be propagated, not discarded.
        return (bool) (new FileSystem())->downloadFile($sourceFile, $downloadedFile, $post_data);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);

        return false;
    }
}

/**
 * Shopclass file_get_contents implementation
 *
 * @param string                   $url
 * @param array<string,mixed>|null $post_data POST body; null issues a GET
 * @param bool $verify_ssl verify the peer's TLS certificate. Defaults to true so
 *                         every caller authenticates the peer; pass false only at
 *                         a call site that genuinely must talk to a bad cert.
 * @param int  $timeout    total transfer timeout in seconds; 0 leaves no overall
 *                         limit, but getContents() still aborts a stalled transfer.
 *
 * @return bool|string|null
 */
function osc_file_get_contents($url, $post_data = null, $verify_ssl = true, $timeout = 0)
{
    try {
        return (new FileSystem())->getContents($url, $post_data, $verify_ssl, (int)$timeout);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);

        return false;
    }
}

/**
 * Check if we loaded some specific module of apache
 *
 * @param string $mod
 *
 * @return bool
 */
function apache_mod_loaded($mod)
{
    return Utils::apacheModLoaded($mod);
}

/**
 * Change version to param number
 *
 * @param string|null $version
 *
 * @return void
 */
function osc_changeVersionTo($version = null)
{
    Utils::changeOsclassVersionTo($version);
}

/**
 * Whether the in-app self-updater (downloading a package and overwriting core
 * files) is disabled for this installation. Set on immutable deployments — the
 * Docker image sets OSC_DISABLE_SELF_UPDATE=1 — where the running code is baked
 * into an image and a file-writing upgrade would be discarded on the next
 * redeploy while leaving the database ahead of the code. Those installs update
 * by deploying a newer image; the entrypoint's `db:upgrade` migrates the schema.
 *
 * @return bool
 */
function osc_self_update_disabled()
{
    if (defined('OSC_DISABLE_SELF_UPDATE')) {
        return (bool) OSC_DISABLE_SELF_UPDATE;
    }

    return filter_var(getenv('OSC_DISABLE_SELF_UPDATE'), FILTER_VALIDATE_BOOLEAN);
}

/**
 * Whether installing/updating market packages (plugins/themes) is disabled for
 * this installation. Distinct from osc_self_update_disabled(): that flag stops
 * core from overwriting itself on an immutable deployment. Packages are not
 * core — on a deployment where oc-content is a persistent volume, a package
 * write survives a redeploy just fine, so this defaults to enabled and is only
 * set where the site owner has no persistent oc-content to write into.
 *
 * @return bool
 */
function osc_package_installs_disabled()
{
    if (defined('OSC_DISABLE_PACKAGE_INSTALLS')) {
        return (bool) OSC_DISABLE_PACKAGE_INSTALLS;
    }

    return filter_var(getenv('OSC_DISABLE_PACKAGE_INSTALLS'), FILTER_VALIDATE_BOOLEAN);
}

/**
 * Strip backslashes from a string, or from every value of an array, recursively.
 *
 * @param string|array<mixed> $array
 *
 * @return string|array<mixed> Same shape as the input
 */
function strip_slashes_extended($array)
{
    return Utils::stripSlashesExtended($array);
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
 */
function osc_unzip_file($file, $to)
{
    try {
        return (new \mindstellar\utility\Zip())->unzipFile($file, $to);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);

        return 0;
    }
}

/**
 * Common interface to zip a specified folder to a file using ziparchive or pclzip
 *
 * @param string $archive_folder full path of the folder
 * @param string $archive_name   full path of the destination zip file
 *
 * @return int
 */
function osc_zip_folder($archive_folder, $archive_name)
{
    return (new \mindstellar\utility\Zip())->zipFolder($archive_folder, $archive_name);
}

/**
 * Verify the reCAPTCHA token on the current POST request.
 *
 * @return bool False on a non-POST request, a missing token, or a failed verification
 */
function osc_check_recaptcha()
{
    if (strtoupper((string)Params::getServerParam('REQUEST_METHOD', false, false)) !== 'POST') {
        return false;
    }
    // Opaque token: skip HTMLPurifier (same idiom as installer passwords).
    $gReCaptchaResponse = Params::getParamString('g-recaptcha-response', false, false);
    if ($gReCaptchaResponse === '') {
        return false;
    }
    $recaptcha = new ReCaptcha(osc_recaptcha_private_key());
    $resp      = $recaptcha->verify($gReCaptchaResponse, Params::getServerParam('REMOTE_ADDR'));
    if ($resp->isSuccess()) {
        return true;
    }

    return false;
}

/**
 * Verifies the submitted captcha token for the active provider.
 *
 * reCAPTCHA leg delegates to osc_check_recaptcha(). Turnstile leg posts the
 * cf-turnstile-response token to Cloudflare's siteverify endpoint and fails
 * closed on every abnormal path: empty or oversize token, transport error,
 * timeout, non-JSON response, or a body whose success flag is not true.
 *
 * @return bool
 */
function osc_check_captcha()
{
    switch (osc_captcha_provider()) {
        case 'recaptcha':
            return osc_check_recaptcha();
        case 'turnstile':
            if (strtoupper((string)Params::getServerParam('REQUEST_METHOD', false, false)) !== 'POST') {
                return false;
            }
            $token = Params::getParamString('cf-turnstile-response', false, false);
            if ($token === '' || strlen($token) > 2048) {
                return false;
            }
            try {
                $raw = osc_file_get_contents(
                    'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                    array(
                        'secret'   => osc_turnstile_secret_key(),
                        'response' => $token,
                        'remoteip' => Params::getServerParam('REMOTE_ADDR'),
                    ),
                    true,
                    8
                );
            } catch (Exception $e) {
                return false;
            }
            if (!is_string($raw)) {
                return false;
            }
            $json = json_decode($raw, true);

            return is_array($json) && (($json['success'] ?? false) === true);
        default:
            return false;
    }
}

/**
 * replace double slash with single slash
 *
 * @param string $path
 *
 * @return string
 */
function osc_replace_double_slash($path)
{
    return Utils::replaceDoubleSlash($path);
}

/**
 * Walk a directory and report whether the files under it are writable.
 *
 * @param string $dir
 *
 * @return bool False on a path containing .., or on the first unwritable file
 * @deprecated since 4.0.0
 */
function osc_check_dir_writable($dir = ABS_PATH)
{
    if (strpos($dir, '../') !== false || strpos($dir, "..\\") !== false) {
        return false;
    }

    clearstatcache();
    if ($dh = opendir($dir)) {
        while (($file = readdir($dh)) !== false) {
            if ($file !== '.' && $file !== '..') {
                if (is_dir(osc_replace_double_slash($dir . '/' . $file))) {
                    if (osc_replace_double_slash($dir) === (ABS_PATH . 'oc-content/themes')) {
                        if ($file === 'storefront' || $file === 'index.php') {
                            $res = osc_check_dir_writable(osc_replace_double_slash($dir . '/' . $file));
                            if (!$res) {
                                return false;
                            }
                        }
                    } elseif (osc_replace_double_slash($dir) === (ABS_PATH . 'oc-content/plugins')) {
                        if ($file === 'google_maps' || $file === 'google_analytics' || $file === 'index.php') {
                            $res = osc_check_dir_writable(osc_replace_double_slash($dir . '/' . $file));
                            if (!$res) {
                                return false;
                            }
                        }
                    } elseif (osc_replace_double_slash($dir) === (ABS_PATH . 'oc-content/languages')) {
                        if ($file === 'en_US' || $file === 'index.php') {
                            $res = osc_check_dir_writable(osc_replace_double_slash($dir . '/' . $file));
                            if (!$res) {
                                return false;
                            }
                        }
                    } elseif (osc_replace_double_slash($dir) === (ABS_PATH . 'oc-content/downloads')) {
                        continue;
                    } elseif (osc_replace_double_slash($dir) === osc_uploads_path()) {
                        continue;
                    } else {
                        $res = osc_check_dir_writable(osc_replace_double_slash($dir . '/' . $file));
                        if (!$res) {
                            return false;
                        }
                    }
                } else {
                    return is_writable(osc_replace_double_slash($dir . '/' . $file));
                }
            }
        }
        closedir($dh);
    }

    return true;
}

/**
 * Walk a directory and chmod anything under it that is not writable to 0755.
 *
 * @param string $dir
 *
 * @return bool False on a path containing .., or when a chmod fails
 * @deprecated since 4.0.0
 */
function osc_change_permissions($dir = ABS_PATH)
{
    if (strpos($dir, '../') !== false || strpos($dir, "..\\") !== false) {
        return false;
    }

    clearstatcache();
    if ($dh = opendir($dir)) {
        while (($file = readdir($dh)) !== false) {
            if ($file !== '.' && $file !== '..' && $file[0] !== '.') {
                if (!is_writable(osc_replace_double_slash($dir . '/' . $file))) {
                    $result = chmod(str_replace('//', '/', $dir . '/' . $file), 0755);
                }

                if (is_dir(osc_replace_double_slash($dir . '/' . $file))) {
                    if (osc_replace_double_slash($dir) === (ABS_PATH . 'oc-content/themes')) {
                        if ($file === 'modern' || $file === 'index.php') {
                            $res = osc_change_permissions(str_replace('//', '/', $dir . '/' . $file));
                            if (!$res) {
                                return false;
                            }
                        }
                    } elseif (osc_replace_double_slash($dir) === (ABS_PATH . 'oc-content/plugins')) {
                        if ($file === 'google_maps' || $file === 'google_analytics' || $file === 'index.php') {
                            $res = osc_change_permissions(osc_replace_double_slash($dir . '/' . $file));
                            if (!$res) {
                                return false;
                            }
                        }
                    } elseif (osc_replace_double_slash($dir) === (ABS_PATH . 'oc-content/languages')) {
                        if ($file === 'en_US' || $file === 'index.php') {
                            $res = osc_change_permissions(osc_replace_double_slash($dir . '/' . $file));
                            if (!$res) {
                                return false;
                            }
                        }
                    } elseif (osc_replace_double_slash($dir) === (ABS_PATH . 'oc-content/downloads')) {
                        continue;
                    } elseif (osc_replace_double_slash($dir) === osc_uploads_path()) {
                        continue;
                    } else {
                        $res = osc_change_permissions(osc_replace_double_slash($dir . '/' . $file));
                        if (!$res) {
                            return false;
                        }
                    }

                    return true;
                }

                if (isset($result)) {
                    return $result;
                }

                return false;
            }
        }
        closedir($dh);
    }

    return true;
}

/**
 * Record the current permission bits of a directory and everything under it.
 *
 * @param string $dir
 *
 * @return array<string,int>|false Path => permission bits, or false on a path containing ..
 * @deprecated since 4.0.0
 */
function osc_save_permissions($dir = ABS_PATH)
{
    if (strpos($dir, '../') !== false || strpos($dir, "..\\") !== false) {
        return false;
    }

    $perms       = array();
    $perms[$dir] = fileperms($dir);
    clearstatcache();
    if ($dh = opendir($dir)) {
        while (($file = readdir($dh)) !== false) {
            if ($file !== '.' && $file !== '..') {
                if (is_dir(str_replace('//', '/', $dir . '/' . $file))) {
                    $res = osc_save_permissions(str_replace('//', '/', $dir . '/' . $file));
                    foreach ($res as $k => $v) {
                        $perms[$k] = $v;
                    }
                } else {
                    $perms[str_replace('//', '/', $dir . '/' . $file)] =
                        fileperms(str_replace('//', '/', $dir . '/' . $file));
                }
            }
        }
        closedir($dh);
    }

    return $perms;
}

/**
 * Format a stored integer price for display.
 *
 * @param int|string $price Price in the currency's smallest stored unit
 *
 * @return string
 */
function osc_prepare_price($price)
{
    return Utils::preparePrice($price);
}

/**
 * Recursive glob function
 *
 * @param string $pattern
 * @param int    $flags
 * @param string $path
 *
 * @return array<int,string> of files
 */
function rglob($pattern, $flags = 0, $path = '')
{
    if (!$path && ($dir = dirname($pattern)) !== '.') {
        if ($dir === '\\' || $dir === '/') {
            $dir = '';
        }

        return rglob(basename($pattern), $flags, $dir . '/');
    }
    $paths    = glob($path . '*', GLOB_ONLYDIR | GLOB_NOSORT);
    $files    = glob($path . $pattern, $flags);
    $fileList = [];
    foreach ($paths as $p) {
        $fileList[] = rglob($pattern, $flags, $p . '/');
    }
    $files = array_merge($files, ...$fileList);

    return $files;
}

/**
 * Whether a plugin's update URI advertises a version newer than the installed one.
 *
 * @param string      $update_uri
 * @param string|null $version Version currently installed
 *
 * @return bool
 * @deprecated since 4.0.0 use mindstellar\market\PackageIndex::forPlugins()->pendingUpdates() instead
 * @see \mindstellar\market\PackageIndex::pendingUpdates()
 */
function osc_check_plugin_update($update_uri, $version = null)
{
    $uri = _get_market_url('plugins', $update_uri);
    if ($uri != false) {
        return _need_update($uri, $version);
    }

    return false;
}

/**
 * Whether a theme's update URI advertises a version newer than the installed one.
 *
 * @param string      $update_uri
 * @param string|null $version Version currently installed
 *
 * @return bool
 * @deprecated since 4.0.0 use mindstellar\market\PackageIndex::forThemes()->pendingUpdates() instead
 * @see \mindstellar\market\PackageIndex::pendingUpdates()
 */
function osc_check_theme_update($update_uri, $version = null)
{
    $uri = _get_market_url('themes', $update_uri);
    if ($uri != false) {
        return _need_update($uri, $version);
    }

    return false;
}

/**
 * Whether the translations repository has a newer version of an installed language.
 *
 * The published index describes every language at once, so it is read once per
 * request however many languages are installed -- the alternative was a request per
 * language, which is what made this worth disabling in the first place.
 *
 * Anything that stops the index being read means no update is offered: a site with no
 * outbound network is not a site with stale translations, it is a site that cannot be
 * told either way.
 *
 * @param string      $update_uri Locale code, e.g. 'fr_FR'
 * @param string|null $version    Version currently installed
 * @param bool        $disable    Kept for callers that passed it; true still short-circuits
 *
 * @return bool
 */
function osc_check_language_update($update_uri, $version = null, $disable = false)
{
    if ($disable) {
        return false;
    }

    static $published = null;

    if ($published === null) {
        $published = array();
        $json      = @osc_file_get_contents(osc_get_i18n_repository_url());
        $list      = json_decode((string) $json, true);
        if (is_array($list)) {
            foreach ($list as $locale) {
                if (is_array($locale) && isset($locale['locale_code'], $locale['version'])) {
                    $published[(string) $locale['locale_code']] = (string) $locale['version'];
                }
            }
        }
    }

    if ($version === null || !isset($published[$update_uri])) {
        return false;
    }

    return version_compare((string) $version, $published[$update_uri], '<');
}

/**
 * Resolve a package's update URI to a market URL.
 *
 * @param string      $type       One of plugins, themes or languages
 * @param string|null $update_uri
 * @param bool        $disable    True short-circuits to false; that is the default
 *
 * @return string|false False unless $update_uri is already an absolute http(s) URL
 * @deprecated since 4.0.0
 */
function _get_market_url($type, $update_uri, $disable = true)
{
    if ($disable) {
        return false;
    }
    if ($update_uri == null) {
        return false;
    }

    if (in_array($type, array('plugins', 'themes', 'languages'))) {
        if (stripos($update_uri, 'http://') === false && stripos($update_uri, 'https://') === false) {
            // OSCLASS OFFICIAL REPOSITORY
            // $uri = osc_market_url( $type , $update_uri );
            return false;
        }

        /** @var string $uri */
        $uri = $update_uri;

        return $uri;
    }

    return false;
}

/**
 * Whether the JSON descriptor at a URI advertises a version newer than the installed one.
 *
 * @param string      $uri
 * @param string|null $version Version currently installed
 * @param bool        $disable True short-circuits to false; that is the default
 *
 * @return bool
 * @deprecated since 4.0.0
 */
function _need_update($uri, $version, $disable = true)
{
    if ($disable) {
        return false;
    }
    if (false === ($json = osc_file_get_contents($uri))) {
        return false;
    }

    $data = json_decode($json, true);
    if (isset($data['s_version'])) {
        $result = version_compare2($data['s_version'], $version);
        if ($result === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Returns
 *      0  if both are equal,
 *      1  if A > B, and
 *      -1 if B < A.
 *
 * @param string $a -> from market
 * @param string $b -> installed version
 *
 * @return int
 * @deprecated since 4.0.0
 * @see Utils::versionCompare()
 */
function version_compare2($a, $b)
{
    Deprecate::deprecatedFunction(__FUNCTION__, '4.0.0', 'Utils::versionCompare()');
    $aA = explode('.', rtrim($a, '.0')); //Split version into pieces and remove trailing .0
    $aB = explode('.', rtrim($b, '.0')); //Split version into pieces and remove trailing .0
    foreach ($aA as $depth => $aVal) { //Iterate over each piece of A
        if (isset($b[$depth])) { //If B matches A to this depth, compare the values
            if ($aVal > $aB[$depth]) {
                return 1;
            }

            if ($aVal < $aB[$depth]) {
                return -1;
            } //Return A > B //Return B > A
            //An equal result is inconclusive at this point
        } else {      //If B does not match A to this depth, then A comes after B in sort order
            return 1; //so return A > B
        }
    }
    //At this point, we know that to the depth that A and B extend to, they are equivalent.
    //Either the loop ended because A is shorter than B, or both are equal.
    return (count($aA) < count($aB)) ? -1 : 0;
}

/**
 * Update category stats
 *
 * @return void
 */
function osc_update_cat_stats()
{
    Utils::updateAllCategoriesStats();
}

/**
 * Recount items for a given a category id
 *
 * @param int $id
 *
 * @return void
 */
function osc_update_cat_stats_id($id)
{
    Utils::updateCategoryStatsById($id);
}

/**
 * Update locations stats. I moved this function from cron.daily.php:update_location_stats
 *
 * @param bool $force
 * @param int  $limit
 *
 * @return int
 * @since 3.1
 */
function osc_update_location_stats($force = false, $limit = 1000)
{
    return Utils::updateLocationStats($force, $limit);
}

/**
 * Translate current categories to new locale
 *
 * @param string $locale
 *
 * @return void
 */
function osc_translate_categories($locale)
{
    Utils::translateCategories($locale);
}

/**
 * The client's IP address for the current request.
 *
 * @return string
 */
function get_ip()
{
    return Utils::getClientIp();
}

/**
 * Flush pending flash messages, send a Location header and end the request.
 *
 * @param string   $url
 * @param int|null $code HTTP status to send with the redirect
 *
 * @return never
 */
function osc_redirect_to($url, $code = null)
{
    Utils::redirectTo($url, $code);
}

/**
 * Fill in the missing slugs for one location table, returning how many rows were updated.
 *
 * @param string $type One of country, region or city
 *
 * @return int|false False when $type is not a known location type
 */
function osc_calculate_location_slug($type)
{
    return Utils::calculateLocationSlug($type);
}

/**
 * Drop null and empty elements from an array in place, recursively.
 *
 * @param array<mixed> $input Modified by reference
 *
 * @return void
 */
function osc_prune_array(&$input)
{
    Utils::pruneArray($input);
}

/**
 * Whether a package's published descriptor lists the running core version as compatible.
 *
 * @param string $section         Market section, e.g. plugins or themes
 * @param string $element         URL of the package's JSON descriptor
 * @param string $osclass_version Core version to test against
 *
 * @return bool
 */
function osc_is_update_compatible($section, $element, $osclass_version = OSCLASS_VERSION)
{
    if ($element != '') {
        $data = array();
        if (stripos($element, 'http://') === true && stripos($element, 'https://') === false) {
            // OSCLASS OFFICIAL REPOSITORY
            // $url  = osc_market_url( $section , $element );
            // $data = json_decode(
            //             osc_file_get_contents(
            //                 $url ,
            //                 array ( 'api_key' => osc_market_api_connect() )
            //             ) ,
            //             true
            //          );
        } else {
            // THIRD PARTY REPOSITORY
            $data = json_decode(osc_file_get_contents($element), true);
        }
        if (isset($data['s_compatible'])) {
            $versions = explode(',', $data['s_compatible']);

            foreach ($versions as $_version) {
                $result = version_compare2($osclass_version, $_version);

                if ($result == 0 || $result == -1) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * Whether the current request arrived over HTTPS.
 *
 * @return bool
 */
function osc_is_ssl()
{
    return Utils::isSsl();
}

if (!function_exists('hex2b64')) {

    /**
     * Used to encode a field for Amazon Auth
     * (taken from the Amazon S3 PHP example library)
     *
     * @param string $str Hex string to re-encode
     *
     * @return string
     * @deprecated since 4.0.0
     * @see Utils::hex2b64()
     */
    function hex2b64($str)
    {
        Deprecate::deprecatedFunction(__FUNCTION__, '4.0.0', 'Utils::hex2b64()');

        return Utils::hex2b64($str);
    }
}

if (!function_exists('hmacsha1')) {
    /**
     * Calculate HMAC-SHA1 according to RFC2104
     * See http://www.faqs.org/rfcs/rfc2104.html
     *
     * @param string $key
     * @param string $data
     *
     * @return string
     * @deprecated since 4.0.0
     * @see Utils::hmacsha1()
     */
    function hmacsha1($key, $data)
    {
        Deprecate::deprecatedFunction(__FUNCTION__, '4.0.0', 'Utils::hmacsha1()');

        return Utils::hmacsha1($key, $data);
    }
}
