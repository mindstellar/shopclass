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
 * Date: 12-07-2021
 * Time: 13:56
 * License is provided in root directory.
 */

namespace mindstellar\form\base;

use Exception;
use mindstellar\utility\Escape;
use mindstellar\utility\Sanitize;

/**
 * Class BaseInputs
 * Generate Basic Form Inputs
 *
 * @package mindstellar\form
 */
class FormInputs implements InputInterface
{
    protected $textClass = 'form-control';
    protected $selectClass = 'form-select';
    protected $passwordClass = 'form-control';
    protected $checkboxClass = 'form-check-input';
    protected $radioClass = 'form-check-input';
    protected $textareaClass = 'form-control';
    protected $submitClass = 'btn btn-primary';
    protected $fileClass = 'form-control';
    protected $labelClass = 'form-label';
    protected $helpTextClass = 'form-text';
    protected $labelDivClass;
    protected $inputDivClass;
    /**
     * Default common options
     *
     * @var array
     */
    protected $options = [
        'sanitize'   => 'html',
        // default sanitize method for values is string, Check /mindstellar/utility/Sanitize for available methods
        'escapeHTML' => true,
        // default escapeHTML is true
    ];
    /**
     * @var \mindstellar\utility\Escape
     */
    private $escape;
    /**
     * Sanitize method available in /mindstellar/utility/Sanitize class
     *
     * @var string
     */
    //protected $sanitizeType = 'string';
    //protected $escapeHtml = true;
    //protected $divClass;
    /**
     * @var \mindstellar\utility\Sanitize
     */
    private $sanitize;

    /**
     * FormInputs constructor.
     *
     * @param \mindstellar\utility\Escape   $escape
     * @param \mindstellar\utility\Sanitize $sanitize
     */
    public function __construct(Escape $escape = null, Sanitize $sanitize = null)
    {
        if ($escape === null) {
            $this->escape = new Escape();
        } else {
            $this->escape = $escape;
        }
        if ($sanitize === null) {
            $this->sanitize = new Sanitize();
        } else {
            $this->sanitize = $sanitize;
        }
    }

    /**
     * @param string $name
     * @param        $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return string
     * @throws \Exception
     */
    public function text(string $name, $value, array $attributes = [], array $options = [])
    : string
    {
        if (!isset($attributes['type'])) {
            $attributes['type'] = 'text';
        }
        if (!isset($attributes['class'])) {
            $attributes['class'] = $this->textClass;
        }

        return $this->generateInput($name, $value, $attributes, $options);
    }

    /**
     * Common method for generating all inputs type
     *
     * @param string $name
     * @param null   $value
     * @param array  $attributes                                input tag attributes
     * @param array  $options                                   This contains flag for input
     *                                                          Supported flag in $options array :
     *                                                          'selectPlaceholder' : placeholder for select input
     *                                                          'label' : label for input
     *                                                          'inputHelp': Text for input help
     *                                                          'radioOptions' : options for radio
     *                                                          'selectOptions' : options for select input
     *                                                          'optGroupLevel' :int $optgroupLevel -1 = no optgroup, 0 = first level, 1 =
     *                                                          second level, etc. for select box
     *                                                          'optGroupKey': SubOption key in array
     *                                                          'divClass' : css class for input div container, default not set
     *                                                          'labelDivClass' : css class for input label div container, default not set
     *                                                          'inputDivClass' : css class for input field div container, default not set
     *                                                          'escapeHtml' : escape html for input attributes, default is true
     *                                                          'sanitize'   : sanitize method for input value, default is 'string'
     *
     *                                              Throw exception if $name is not set
     *
     * @return string
     * @throws \Exception
     */
    private function generateInput(string $name, $values = null, array $attributes = [], array $options = [])
    : string
    {
        if (!isset($name)) {
            throw new Exception('Input Name is not set');
        }
        $this->handleOptions($options);
        list($divStart,
            $divEnd,
            $labelDivStart,
            $labelDivEnd,
            $inputDivStart,
            $inputDivEnd) = $this->setContainerClasses($options);
        $input = $divStart;
        // Sanitize input values if needed
        if ($values !== null) {
            $values = $this->sanitizeByType($values, $options['sanitize']);
        }

        // Generate input HTML with given $options['type']
        switch ($attributes['type']) {
            // Generate input with type=radio
            case 'radio':
                //Add label if $options['label'] is set
                if (isset($options['label'])) {
                    $input .= $labelDivStart;
                    $input .= $this->label($options['label'], $name);
                    $input .= $labelDivEnd;
                }
                if (isset($options['radioOptions'])) {
                    $radioOptions = $options['radioOptions'];
                    if (is_string($radioOptions)) {
                        $radioOptions = explode(',', $radioOptions);
                        //rename $values array key to value
                        foreach ($radioOptions as $k => $v) {
                            $radioOptions[$v] = $v;
                            unset($radioOptions[$k]);
                        }
                    }
                    $i            = 0;
                    $radioOptions = $this->sanitizeByType($radioOptions, $options['sanitize']);
                    $input        .= '<ul class="meta-radio-list list-unstyled">';
                    foreach ($radioOptions as $v => $l) {
                        $i++;
                        $checked = '';
                        if ($v == $values) {
                            $checked = ' checked';
                        }
                        if (isset($attributes['id'])) {
                            $attributes['id'] .= $i;
                        }
                        $attributesString = $this->attributesToString($attributes);
                        $input            .= '<li class="meta-radio">';
                        $input            .= '<label>';
                        $input            .= sprintf('<input type="radio" name="%s" value="%s"%s>', $name, $v,
                                                     $attributesString . ' ' . $checked);
                        $input            .= ' ' . $l;
                        $input            .= '</label>';
                        $input            .= '</li>';
                    }
                    $input .= '</ul>';
                    unset($i, $radioOptions);
                }
                break;
            // Generate input with type=checkbox
            case 'checkbox':
                $attributesString = $this->attributesToString($attributes);
                $input            .= $inputDivStart;
                $input            .= sprintf(
                    '<input type="checkbox" name="%s" value="%s"%s>', $name, $values, $attributesString
                );
                $input            .= $inputDivEnd;
                if (isset($options['label'])) {
                    $input .= $labelDivStart;
                    $input .= $this->label($options['label'], $name);
                    if (isset($options['inputHelp'])) {
                        $input .= '<p class="' . $this->helpTextClass . '">' . $options['inputHelp'] . '</p>';
                    }
                    $input .= $labelDivEnd;
                }
                break;
            // Generate input with type=select
            case 'select':

                //Add label if $options['label'] is set
                if (isset($options['label'])) {
                    $input .= $labelDivStart;
                    $input .= $this->label($options['label'], $name);
                    $input .= $labelDivEnd;
                }
                // $attributes to String
                $attributesString = $this->attributesToString($attributes);
                $input            .= $inputDivStart;
                $input            .= sprintf('<select name="%s"%s>', $name, $attributesString);
                // Add selectPlaceholder option or create a new placeholder if not set
                $selectPlaceholder = $options['selectPlaceholder'] ?? '';

                if (isset($options['selectPlaceholder']) && $options['selectPlaceholder'] !== null) {
                    if ($selectPlaceholder) {
                        $input .= sprintf('<option value="">%s</option>', $options['selectPlaceholder']);
                    } else {
                        $input .= sprintf('<option value="">%s</option>', 'Select Option');
                    }
                }

                $input .= $this->getOptionsString($values, $options);
                $input .= '</select>';
                if (isset($options['inputHelp'])) {
                    $input .= '<p class="' . $this->helpTextClass . '">' . $options['inputHelp'] . '</p>';
                }
                $input .= $inputDivEnd;
                break;
            // Generate input with type=textarea
            case 'textarea':
                //Add label if $options['label'] is set
                if (isset($options['label'])) {
                    $input .= $labelDivStart;
                    $input .= $this->label($options['label'], $name);
                    $input .= $labelDivEnd;
                }
                // $attributes to String
                $attributesString = $this->attributesToString($attributes);
                $input            .= $inputDivStart;
                $input            .= sprintf('<textarea name="%s"%s>%s</textarea>', $name, $attributesString, $values);
                if (isset($options['inputHelp'])) {
                    $input .= '<p class="' . $this->helpTextClass . '">' . $options['inputHelp'] . '</p>';
                }
                $input .= $inputDivEnd;
                break;
            // Generate input with type=file
            case 'file':
                //Add label if $options['label'] is set
                if (isset($options['label'])) {
                    $input .= $labelDivStart;
                    $input .= $this->label($options['label'], $name);
                    $input .= $labelDivEnd;
                }
                // $attributes to String
                $attributesString = $this->attributesToString($attributes);
                $input            .= $inputDivStart;
                $input            .= sprintf('<input name="%s"%s>', $name, $attributesString);
                if (isset($options['inputHelp'])) {
                    $input .= '<p class="' . $this->helpTextClass . '">' . $options['inputHelp'] . '</p>';
                }
                $input .= $inputDivEnd;
                break;
            // Generate input with type=submit
            case 'submit':
                //Add label if $options['label'] is set
                if (isset($options['label'])) {
                    $input .= $labelDivStart;
                    $input .= $this->label($options['label'], $name);
                    $input .= $labelDivEnd;
                }
                // $attributes to String
                $attributesString = $this->attributesToString($attributes);
                $input            .= $inputDivStart;
                $input            .= sprintf('<input type="submit"  value="%s"%s>', $values, $attributesString);
                if (isset($options['inputHelp'])) {
                    $input .= '<p class="' . $this->helpTextClass . '">' . $options['inputHelp'] . '</p>';
                }
                $input .= $inputDivEnd;
                break;
            // Generate default input
            default:
                //Add label if $options['label'] is set
                if (isset($options['label'])) {
                    $input .= $labelDivStart;
                    $input .= $this->label($options['label'], $name);
                    $input .= $labelDivEnd;
                }
                // $attributes to String
                $attributesString = $this->attributesToString($attributes);
                $input            .= $inputDivStart;
                $input            .= sprintf('<input name="%s" %s value="%s">', $name, $attributesString, $values);
                if (isset($options['inputHelp'])) {
                    $input .= '<p class="' . $this->helpTextClass . '">' . $options['inputHelp'] . '</p>';
                }
                $input .= $inputDivEnd;
                break;
        }
        if (isset($options['customHtml'])) {
            $input .= $this->addHtml($options['customHtml']);
        }
        $input .= $divEnd;

        unset($attributesString, $defaultInputValue, $label, $selectPlaceholder);

        return $input . PHP_EOL;
    }

    /**
     * Handler for given input option array
     *
     * @param array $options
     * @param array $attributes
     *
     */
    private function handleOptions(array &$options)
    {
        // Overwrite default options with given options
        $options = array_merge($this->options, $options);
    }

    /**
     * @param array $options
     *
     * @return array
     */
    private function setContainerClasses(array $options)
    : array
    {
        if (isset($options['divClass'])) {
            $divStart  = '<div class="' . $options['divClass'] . '">';
            $divEnd = '</div>';
        } else {
            $divStart = '';
            $divEnd = '';
        }
        // Set Label Div container
        if ($this->labelDivClass !== null) {
            $labelDivStart = $this->labelDivClass;
            $labelDivEnd   = '</div>';
        } else {
            $labelDivStart = '';
            $labelDivEnd   = '';
        }
        if (isset($options['labelDivClass'])) {
            $labelDivStart = '<div class="' . $options['labelDivClass'] . '">' . PHP_EOL;
            $labelDivEnd   = '</div>' . PHP_EOL;
        }

        // Set InputField Div container
        if ($this->inputDivClass !== null) {
            $inputDivStart = $this->inputDivClass;
            $inputDivEnd   = '</div>';
        } else {
            $inputDivStart = '';
            $inputDivEnd   = '';
        }
        if (isset($options['inputDivClass'])) {
            $inputDivStart = '<div class="' . $options['inputDivClass'] . '">' . PHP_EOL;
            $inputDivEnd   = '</div>' . PHP_EOL;
        }

        return array($divStart, $divEnd, $labelDivStart, $labelDivEnd, $inputDivStart, $inputDivEnd);
    }

    /**
     * Sanitize input values
     *
     * @param mixed       $values
     * @param string|null $sanitizeType Sanitize method name, See /mindstellar/utility/Sanitize.php for supported types
     *
     * @return mixed
     */
    private function sanitizeByType($values, string $sanitizeType = null)
    {
        if ($sanitizeType === null) {
            return $values;
        }
        if (is_array($values)) {
            foreach ($values as $value) {
                $this->sanitizeByType($value);
            }
        } else {
            $values = $this->sanitize->$sanitizeType($values);
        }

        return $values;
    }

    /**
     * Common method for generating label
     *
     * @param string $label
     * @param string $for
     * @param string $class
     *
     * @return string
     */
    private function label(string $label, string $for, string $class = null)
    : string
    {
        if ($class === null) {
            $class = $this->labelClass;
        }

        return '<label class="' . $class . '" for="' . $for . '">' . $this->escape::html($label) . '</label>';
    }

    /**
     * Generate attributes string from given attributes array
     *
     * @param array $attributes
     *
     * @return string
     */
    private function attributesToString(array $attributes)
    : string
    {
        $attributesString = '';
        foreach ($attributes as $key => $value) {
            // escape html special chars if escapeHtml is true
            if ($value === true) {
                $value = $this->escape::html($value);
            }
            $attributesString .= sprintf(' %s="%s"', $key, $value);
        }

        return $attributesString;
    }

    /**
     * Private function for handling select options
     *
     * @param string|int   $value
     * @param              $options ['optgroupLevel'] -1 = no optgroup, 0 = first level, 1 = second level, etc
     */
    private function getOptionsString($value, $options)
    : string
    {
        // get defaultValue, optGroupLevel options if set or set default
        $defaultValue  = $value ?? '';
        $optGroupLevel = $options['optGroupLevel'] ?? -1;
        $selectOptions = $options['selectOptions'] ?? '';

        // if $selectOptions is a csv string, Convert csv options to array
        if (is_string($selectOptions)) {
            $selectOptions = explode(',', $selectOptions);
            foreach ($selectOptions as $k => $v) {
                $selectOptions[$v] = $v;
                unset($selectOptions[$k]);
            }
        }
        $selectOptionsString = '';
        // $selectOptions is an array, loop through it
        if (is_array($selectOptions)) {
            if (isset($options['sanitize'])) {
                $selectOptions = $this->sanitizeByType($selectOptions, $options['sanitize']);
            }
            foreach ($selectOptions as $k => $v) {
                // Check if this array is in multilevel format i.e. option and children are set
                if (isset($v['option'])) {
                    $optionValue = $v['option']['value'] ?? '';
                    $optionLabel = $v['option']['label'] ?? '';
                    // if $optgroupLevel is set, add optgroup
                    if ($optGroupLevel === 0) {
                        $selectOptionsString .= sprintf('<optgroup label="%s">', $optionLabel);
                    } else {
                        $selected            = isset($defaultValue) && $defaultValue == $optionValue ? ' selected' : '';
                        $selectOptionsString .= sprintf('<option value="%s"%s>%s</option>', $optionValue, $selected, $optionLabel)
                                                . PHP_EOL;
                        unset($selected);
                    }
                    if (isset($v['children'])) {
                        $selectOptionsString .= $this->getOptionsString($v['children'], $optGroupLevel - 1) . PHP_EOL;
                    }
                    // if $optgroupLevel is set, add optgroup
                    if ($optGroupLevel === 0) {
                        $selectOptionsString .= '</optgroup>';
                    }
                } else {
                    $optionValue = $k;
                    $optionLabel = $v;
                    // check if default value is set and if it matches the current value
                    $selected            = isset($defaultValue) && $defaultValue == $optionValue ? ' selected' : '';
                    $selectOptionsString .= sprintf('<option value="%s"%s>%s</option>', $optionValue, $selected, $optionLabel) . PHP_EOL;
                    unset($selected);
                }
            }
        }

        return $selectOptionsString;
    }

    /**
     * Common method for printing customHtml after input tag
     *
     * @param string $label
     * @param string $for
     * @param string $class
     *
     * @return string
     */
    private function addHtml(string $htmlContent)
    : string
    {
        return $this->escape::html($htmlContent);
    }

    /**
     * Generate Text Area Input
     *
     * @param string $name
     * @param        $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return string
     * @throws \Exception
     */
    public function textarea(string $name, $value, array $attributes = [], array $options = [])
    : string
    {
        $attributes['type'] = 'textarea';
        // set default attributes
        $attributes = array_merge([
                                      'class'   => $this->textareaClass,
                                      'columns' => 5,
                                      'rows'    => 10,
                                  ],
                                  $attributes);
        // set default options
        $options = array_merge([
                                   'sanitize' => 'html',
                               ], $options);

        return $this->generateInput($name, $value, $attributes, $options);
    }

    /**
     * Generate Checkbox Input
     *
     * @param string $name
     * @param        $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return string
     * @throws \Exception
     */
    public function checkbox(string $name, $value, array $attributes = [], array $options = [])
    : string
    {
        $attributes['type'] = 'checkbox';
        // add css class if not set
        if (!isset($attributes['class'])) {
            $attributes['class'] = $this->checkboxClass;
        }

        return $this->generateInput($name, $value, $attributes, $options);
    }

    /**
     * Generate Select Input
     *
     * @param string $name
     * @param string $value
     * @param array  $attributes                                input tag attributes
     * @param array  $options                                   This contains flag for input
     *                                                          Supported flag in $options array :
     *                                                          'selectPlaceholder' : placeholder for select input
     *                                                          'label' : label for input
     *                                                          'selectOptions' : options for select input
     *                                                          'optGroupLevel' :int $optgroupLevel -1 = no optgroup, 0 = first level, 1 =
     *                                                          second level, etc. for select box
     *                                                          'optGroupKey': SubOption key in array
     *                                                          'divClass' : css class for input div container, default not set
     *                                                          'escapeHtml' : escape html for input attributes, default is true
     *                                                          'sanitize'   : sanitize method for input value, default is 'string'
     *
     * @return string
     * @throws \Exception
     */
    public function select(string $name, $value, array $attributes = [], array $options = [])
    : string
    {
        $attributes['type'] = 'select';
        // add class if not set
        if (!isset($attributes['class'])) {
            $attributes['class'] = $this->selectClass;
        }

        return $this->generateInput($name, $value, $attributes, $options);
    }

    /**
     * Generate Password Input
     *
     * @param string $name
     * @param string $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return string
     * @throws \Exception
     */
    public function password(string $name, string $value, array $attributes = [], array $options = [])
    : string
    {
        $attributes['type']         = 'password';
        $attributes['autocomplete'] = 'on';
        // add class if not set
        if (!isset($attributes['class'])) {
            $attributes['class'] = $this->passwordClass;
        }

        return $this->generateInput($name, $value, $attributes, $options);
    }

    /**
     * Generate radio input
     *
     * @param string $name
     * @param        $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return string
     * @throws \Exception
     */
    public function radio(string $name, $value, array $attributes = [], array $options = [])
    : string
    {
        $attributes['type'] = 'radio';
        // add css class if not set
        if (!isset($options['class'])) {
            $attributes['class'] = $this->radioClass;
        }

        return $this->generateInput($name, $value, $attributes, $options);
    }

    /**
     * Generate hidden input
     *
     * @param string $name
     * @param        $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return string
     * @throws \Exception
     */
    public function hidden(string $name, $value, array $attributes = [], array $options = [])
    : string
    {
        $attributes['type'] = 'hidden';

        return $this->generateInput($name, $value, $attributes, $options);
    }

    /**
     * Generate submit input
     *
     * @param string $name
     * @param array  $attributes
     * @param array  $options
     *
     * @return string
     * @throws \Exception
     */
    public function submit(string $name, array $attributes = [], array $options = [])
    : string
    {
        $attributes['type'] = 'submit';
        // add css class if not set
        if (!isset($attributes['class'])) {
            $attributes['class'] = $this->submitClass;
        }

        return $this->generateInput($name, $name, $attributes, $options);
    }

    /**
     * Generate file input
     *
     * @param string $name
     * @param array  $attributes
     * @param array  $options
     *
     * @return string
     * @throws \Exception
     */
    public function file(string $name, array $attributes = [], array $options = [])
    : string
    {
        $attributes['type'] = 'file';
        if (!isset($attributes['class'])) {
            $attributes['class'] = $this->fileClass;
        }
        $options['escapeHTML'] = false;
        $options['sanitize']   = null;

        return $this->generateInput($name, null, $attributes, $options);
    }
}
