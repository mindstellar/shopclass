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
     * Generate a text input.
     *
     * @param string              $name
     * @param mixed               $value
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $options
     *
     * @return string
     * @throws \Exception when the input name is empty
     */
    public function text(string $name, $value, array $attributes = [], array $options = []);

    /**
     * Generate a textarea.
     *
     * @param string              $name
     * @param mixed               $value
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $options
     *
     * @return string
     * @throws \Exception when the input name is empty
     */
    public function textarea(string $name, $value, array $attributes = [], array $options = []);

    /**
     * Generate a checkbox input.
     *
     * @param string              $name
     * @param mixed               $value
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $options
     *
     * @return string
     * @throws \Exception when the input name is empty
     */
    public function checkbox(string $name, $value, array $attributes = [], array $options = []): string;

    /**
     * Generate a select box.
     *
     * @param string              $name
     * @param string|int|null     $value The currently selected value
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $options
     *
     * @return string
     * @throws \Exception when the input name is empty
     */
    public function select(string $name, $value, array $attributes = [], array $options = []);

    /**
     * Generate a password input.
     *
     * @param string              $name
     * @param string              $value
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $options
     *
     * @return string
     * @throws \Exception when the input name is empty
     */
    public function password(string $name, string $value, array $attributes = [], array $options = []);

    /**
     * Generate a radio group.
     *
     * @param string              $name
     * @param string|int|null     $value The currently checked value
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $options
     *
     * @return string
     * @throws \Exception when the input name is empty
     */
    public function radio(string $name, $value, array $attributes = [], array $options = []);

    /**
     * Generate a hidden input.
     *
     * @param string              $name
     * @param mixed               $value
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $options
     *
     * @return string
     * @throws \Exception when the input name is empty
     */
    public function hidden(string $name, $value, array $attributes = [], array $options = []);

    /**
     * Generate a submit button.
     *
     * @param string              $name
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $options
     *
     * @return string
     * @throws \Exception when the input name is empty
     */
    public function submit(string $name, array $attributes = [], array $options = []);
}
