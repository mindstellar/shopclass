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
 * Created by Mindstellar Community.
 * User: navjottomer
 * Date: 17-07-2021
 * Time: 15:31
 * License is provided in root directory.
 */

namespace mindstellar\form\base;


/**
 * Class BaseInputs
 * Generate Basic Form Inputs
 *
 * @package mindstellar\form
 */
interface InputInterface
{
    /**
     * @param string $name
     * @param        $value
     * @param array  $attributes
     * @param array  $options
     */
    public function text(string $name, $value, array $attributes = [], array $options = []);

    /**
     * TextArea
     *
     * @param string $name
     * @param        $value
     * @param array  $attributes
     * @param array  $options
     */
    public function textarea(string $name, $value, array $attributes = [], array $options = []);

    /**
     * Checkbox
     *
     * @param string $name
     * @param        $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return string
     */
    public function checkbox(string $name, $value, array $attributes = [], array $options = [])
    : string;

    /**
     * Select
     *
     * @param string       $name
     * @param array|string $value
     * @param array        $attributes
     * @param array        $options
     */
    public function select(string $name, $value, array $attributes = [], array $options = []);

    /**
     * Password
     *
     * @param string $name
     * @param string $value
     * @param array  $attributes
     * @param array  $options
     */
    public function password(string $name, string $value, array $attributes = [], array $options = []);

    /**
     * radio
     *
     * @param string       $name
     * @param array|string|int $value
     * @param array        $attributes
     * @param array        $options
     */
    public function radio(string $name, $value, array $attributes = [], array $options = []);

    /**
     * hidden
     *
     * @param string $name
     * @param        $value
     * @param array  $attributes
     * @param array  $options
     */
    public function hidden(string $name, $value, array $attributes = [], array $options = []);

    /**
     * submit
     *
     * @param string $name
     * @param array  $attributes
     * @param array  $options
     */
    public function submit(string $name, array $attributes = [], array $options = []);
}
