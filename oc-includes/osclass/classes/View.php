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
 * Class View
 */
class View
{
    private static $instance;
    private $aExported;
    private $aCurrent;

    /**
     * Start with an empty set of exported variables.
     */
    public function __construct()
    {
        $this->aExported = array();
    }

    /**
     * The shared View instance, created on first call.
     *
     * @return \View
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * to export variables at the business layer
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function _exportVariableToView($key, $value)
    {
        $this->aExported[$key] = $value;
    }

    /**
     * to get the exported variables for the view
     *
     * @param string $key
     *
     * @return mixed the exported value, or '' when nothing was exported under $key
     */
    public function _get($key)
    {
        if ($this->_exists($key)) {
            return $this->aExported[$key];
        }

        return '';
    }

    //only for debug

    /**
     * Whether anything has been exported under this key.
     *
     * @param string $key
     *
     * @return bool
     */
    public function _exists($key)
    {
        return (isset($this->aExported[$key]) ? true : false);
    }

    /**
     * Dump one exported variable, or all of them, for debugging.
     *
     * @param string|null $key
     *
     * @return void
     */
    public function _view($key = null)
    {
        if ($key) {
            print_r($this->aExported[$key]);
        } else {
            print_r($this->aExported);
        }
    }

    /**
     * The element the internal pointer of an exported array sits on.
     *
     * @param string $key
     *
     * @return mixed '' when the exported value is not an array
     */
    public function _current($key)
    {
        if (is_array($this->aExported[$key])) {
            if (!isset($this->aCurrent[$key])) {
                $this->aCurrent[$key] = current($this->aExported[$key]);
            }

            return $this->aCurrent[$key];
        }

        return '';
    }

    /**
     * The zero-based index of the element _current() would return.
     *
     * @param string $key
     *
     * @return int|false false when the exported value is not an array
     */
    public function _key($key)
    {
        if (is_array($this->aExported[$key])) {
            $_key = key($this->aExported[$key]) - 1;
            if ($_key == -1) {
                $_key = count($this->aExported[$key]) - 1;
            }

            return $_key;
        }

        return false;
    }

    /**
     * Move the internal pointer of an exported array to a zero-based position.
     *
     * @param string $key
     * @param int    $position
     *
     * @return bool false when the key is not an array or the position is out of range
     */
    public function _seek($key, $position)
    {
        if (is_array($this->aExported[$key])) {
            $this->_reset($key);
            for ($k = 0; $k <= $position; $k++) {
                $res = $this->_next($key);
                if (!$res) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Rewind an exported array's internal pointer and return its first element.
     *
     * @param string $key
     *
     * @return mixed array() when the key is missing or not an array; false when it is empty
     */
    public function _reset($key)
    {
        if (!array_key_exists($key, $this->aExported)) {
            return array();
        }
        if (!is_array($this->aExported[$key])) {
            return array();
        }

        return reset($this->aExported[$key]);
    }

    /**
     * Advance an exported array's internal pointer by one.
     *
     * @param string $key
     *
     * @return bool false at the end of the array, or when the key is not an array
     */
    public function _next($key)
    {
        if (is_array($this->aExported[$key])) {
            $this->aCurrent[$key] = current($this->aExported[$key]);
            if ($this->aCurrent[$key]) {
                next($this->aExported[$key]);

                return true;
            }
        }

        return false;
    }

    /**
     * How many elements an exported array holds, or -1 when it is not an array.
     *
     * @param string $key
     *
     * @return int
     */
    public function _count($key)
    {
        if (isset($this->aExported[$key]) && is_array($this->aExported[$key])) {
            return count($this->aExported[$key]);
        }

        return -1; // @TOFIX @FIXME ?? why ? why not 0 ?
    }

    /**
     * Drop an exported variable and its pointer state.
     *
     * @param string $key
     *
     * @return void
     */
    public function _erase($key)
    {
        unset($this->aExported[$key], $this->aCurrent[$key]);
    }
}
