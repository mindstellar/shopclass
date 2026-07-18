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

/**
 *
 */
class LogDatabase
{
    /**
     *
     * @var
     */
    private static $instance;
    /**
     *
     * @var
     */
    public $messages;
    /**
     *
     * @var
     */
    public $explain_messages;

    /**
     *
     */
    public function __construct()
    {
        $this->messages         = array();
        $this->explain_messages = array();
    }

    /**
     *
     * @return \LogDatabase
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     *
     * @param $sql
     * @param $time
     * @param $errorLevel
     * @param $errorDescription
     */
    public function addMessage($sql, $time, $errorLevel, $errorDescription)
    {
        $this->messages[] = array(
            'query'      => $sql,
            'query_time' => $time,
            'errno'      => $errorLevel,
            'error'      => $errorDescription
        );
    }

    /**
     *
     * @param      $sql
     * @param      $results
     */
    public function addExplainMessage($sql, $results)
    {
        $this->explain_messages[] = array(
            'query'   => $sql,
            'explain' => $results
        );
    }

    /**
     *
     */
    public function printMessages()
    {
        echo '<fieldset style="border:1px solid black; padding:6px 10px 10px 10px; margin: 20px; width: 95%; background-color: #FFFFFF;" >'
            . PHP_EOL;
        echo '<legend style="font-size: 16px;">&nbsp;&nbsp;Database queries (Total queries: '
            . $this->getTotalNumberQueries() . ' - Total queries time: ' . $this->getTotalQueriesTime()
            . ')&nbsp;&nbsp;</legend>' . PHP_EOL;
        echo '<table style="border-collapse: separate; *border-collapse: collapse; width: 100%; font-size: 13px; padding: 15px; border-spacing: 0;">'
            . PHP_EOL;
        if (count($this->messages) == 0) {
            echo '<tr><td>No queries</td></tr>' . PHP_EOL;
        } else {
            foreach ($this->messages as $msg) {
                $row_style = '';
                if ($msg['errno'] != 0) {
                    $row_style = 'style="background-color: #FFC2C2;"';
                }
                echo '<tr ' . $row_style . '>' . PHP_EOL;
                echo '<td style="padding: 10px 10px 9px; text-align: left; vertical-align: top; border: 1px solid #ddd;">'
                    . $msg['query_time'] . '</td>' . PHP_EOL;
                echo '<td style="padding: 10px 10px 9px; text-align: left; vertical-align: top; border: 1px solid #ddd;">';
                if ($msg['errno'] != 0) {
                    echo '<strong>Error number:</strong> ' . $msg['errno'] . '<br/>';
                    echo '<strong>Error description:</strong> ' . $msg['error'] . '<br/><br/>';
                }
                echo nl2br($msg['query']);
                echo '</td>' . PHP_EOL;
                echo '</tr>' . PHP_EOL;
            }
        }
        echo '</table>' . PHP_EOL;
        echo '</fieldset>' . PHP_EOL;
    }

    /**
     * @return int
     */
    public function getTotalNumberQueries()
    {
        return count($this->messages);
    }

    /**
     * @return int
     */
    public function getTotalQueriesTime()
    {
        $time = 0;
        foreach ($this->messages as $m) {
            $time += $m['query_time'];
        }

        return $time;
    }

    /**
     * @return bool
     */
    public function writeMessages()
    {
        $filename = CONTENT_PATH . 'queries.log';

        if ($this->isFileWritableExists($filename)
        ) {
            trigger_error('Can not write explain_queries.log file in "' . CONTENT_PATH
                . '", please check directory/file permissions.', E_USER_WARNING);

            return false;
        }

        $fp = fopen($filename, 'ab');

        if ($fp === false) {
            return false;
        }

        fwrite($fp, '==================================================' . PHP_EOL);

        fwrite($fp, '=' . str_pad('Date: ' . date(osc_date_format() ?: 'Y-m-d') . ' '
                    . date(osc_time_format() ?: 'H:i:s'), 48, ' ', STR_PAD_BOTH) . '='
                . PHP_EOL);

        fwrite(
            $fp,
            '=' . str_pad('Total queries: ' . $this->getTotalNumberQueries(), 48, ' ', STR_PAD_BOTH) . '=' . PHP_EOL
        );
        fwrite($fp, '=' . str_pad('Total queries time: ' . $this->getTotalQueriesTime(), 48, ' ', STR_PAD_BOTH) . '='
            . PHP_EOL);
        fwrite($fp, '==================================================' . PHP_EOL . PHP_EOL);

        foreach ($this->messages as $msg) {
            fwrite($fp, 'QUERY TIME' . ' ' . $msg['query_time'] . PHP_EOL);
            if ($msg['errno'] != 0) {
                fwrite($fp, 'Error number: ' . $msg['errno'] . PHP_EOL);
                fwrite($fp, 'Error description: ' . $msg['error'] . PHP_EOL);
            }
            fwrite($fp, '**************************************************' . PHP_EOL);
            fwrite($fp, $msg['query'] . PHP_EOL);
            fwrite($fp, '--------------------------------------------------' . PHP_EOL);
        }

        fwrite($fp, PHP_EOL . PHP_EOL . PHP_EOL);
        fclose($fp);

        return true;
    }

    /**
     * @param $filename
     *
     * @return bool
     */
    private function isFileWritableExists($filename)
    {
        return (!file_exists($filename) && !is_writable(CONTENT_PATH))
            || (file_exists($filename)
                && !is_writable($filename));
    }

    /**
     * @return bool
     */
    public function writeExplainMessages()
    {
        $filename = CONTENT_PATH . 'explain_queries.log';

        if ($this->isFileWritableExists($filename)
        ) {
            error_log('Can not write explain_queries.log file in "' . CONTENT_PATH
                . '", please check directory/file permissions.');

            return false;
        }

        $fp = fopen($filename, 'ab');

        if ($fp == false) {
            return false;
        }

        fwrite($fp, '==================================================' . PHP_EOL);

        fwrite(
                $fp,
                '=' . str_pad(
                    'Date: ' . date(osc_date_format() ?: 'Y-m-d') . ' ' . date(osc_time_format() ?: 'H:i:s'),
                    48,
                    ' ',
                    STR_PAD_BOTH
                ) . '=' . PHP_EOL
        );
        fwrite($fp, '==================================================' . PHP_EOL . PHP_EOL);

        $title = '|' . str_pad('id', 3, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('select_type', 20, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('table', 20, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('type', 8, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('possible_keys', 28, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('key', 18, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('key_len', 9, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('ref', 48, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('rows', 8, ' ', STR_PAD_BOTH) . '|';
        $title .= str_pad('Extra', 38, ' ', STR_PAD_BOTH) . '|';

        foreach ($this->explain_messages as $i => $iValue) {
            fwrite($fp, $iValue['query'] . PHP_EOL);
            fwrite($fp, str_pad('', 211, '-', STR_PAD_BOTH) . PHP_EOL);
            fwrite($fp, $title . PHP_EOL);
            fwrite($fp, str_pad('', 211, '-', STR_PAD_BOTH) . PHP_EOL);
            foreach ($iValue['explain'] as $explain) {
                $row = '|' . str_pad($explain['id'], 3, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($explain['select_type'], 20, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($explain['table'], 20, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($explain['type'], 8, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($explain['possible_keys'], 28, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($explain['key'], 18, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($explain['key_len'], 9, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($explain['ref'], 48, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($explain['rows'], 8, ' ', STR_PAD_BOTH) . '|';
                $row .= str_pad($explain['Extra'], 38, ' ', STR_PAD_BOTH) . '|';
                fwrite($fp, $row . PHP_EOL);
                fwrite($fp, str_pad('', 211, '-', STR_PAD_BOTH) . PHP_EOL);
            }
            if ($i != (count($this->explain_messages) - 1)) {
                fwrite($fp, PHP_EOL . PHP_EOL);
            }
        }

        fwrite($fp, PHP_EOL . PHP_EOL);
        fclose($fp);

        return true;
    }
}

/* file end: ./oc-includes/osclass/logger/LogDatabase.php */
