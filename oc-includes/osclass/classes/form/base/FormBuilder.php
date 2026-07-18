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
 * Time: 13:02
 * License is provided in root directory.
 */

namespace mindstellar\form\base;


use Exception;
use mindstellar\Csrf;

/**
 * Class FormBuilder
 * Still in development for internal use only
 *
 * @package mindstellar\form
 */
class FormBuilder
{
    /**
     * @var \mindstellar\form\base\formInputs
     */
    private $formInputs;

    /**
     * @var array $formSchema ;
     */
    private $formSchema;

    /**
     * @var \mindstellar\Csrf
     */
    private $csrf;

    /**
     * Form constructor.
     *
     * @param \mindstellar\form\base\formInputs $input
     * @param array                             $formSchema
     *                                  $exampleFormSchema = [
     *                                  'csrf' => true // if csrf is required, default true
     *                                  'attributes' => [
     *                                  'class' => 'form-horizontal', // default
     *                                  'role' => 'form', // default
     *                                  'method' => 'post', // default
     *                                  'action' => '', // default
     *                                  'enctype' => 'multipart/form-data', // default
     *                                  'name' => 'form-name', //required from user
     *                                  ],
     *                                  'inputSchema' => [
     *                                  // array list of inputs with it's own options, You can use add method for generating schema
     *                                  // or provide it here
     *                                  [
     *                                  'type' => 'text',
     *                                  'name' => 'text-name',
     *                                  'label' => 'Text Label',
     *                                  'options' => [
     *                                  'attributes' => [
     *                                  'placeholder' => 'placeholder',
     *                                  ],
     *                                  ],
     *                                  ],
     *                                  ],
     *                                  'submitSchema' => [ // Provide submit button options , Optional
     *                                  'value' => 'submit-name', // required from user
     *                                  'attributes' => [ // array list of attributes for submit button]
     *                                  ]
     *                                  ]
     *
     *
     *
     * inputSchema is a array list of inputs with it's own properties
     * Please See mindstellar\form\base\formInputs for more information and available inputs.
     */
    public function __construct(FormInputs $formInputs = null, array $formSchema = [])
    {
        if ($formInputs === null) {
            $this->formInputs = new FormInputs();
        } else {
            $this->formInputs = $formInputs;
        }
        $this->formInputs = $formInputs;
        $this->csrf       = Csrf::newInstance();

        $defaultSchema    = [
            'attributes'            => [
                'method'  => 'post',
                'enctype' => 'multipart/form-data',
                'action'  => '',
                'class'   => 'form-horizontal',
                'role'    => 'form',
            ],
            'commonInputOptions'    => [],
            'commonInputAttributes' => [],
            'inputSchema'           => [],
            'submit'                => 'Submit',
            'csrf'                  => true
        ];
        $this->formSchema = array_merge($defaultSchema, $formSchema);
    }

    /**
     * AddForm method, it will generate basic formSchema
     *
     * @param string $name        required
     * @param bool   $csrf        default true
     * @param array  $attributes  [Optional]
     * @param array  $inputSchema [optional] if already set then it will override the default inputSchema
     *
     * @return $this
     */
    public function addForm(string $name, bool $csrf = true, array $attributes = [], array $inputSchema = [])
    : FormBuilder
    {
        $this->formSchema['attributes']['name'] = $name;
        $this->formSchema['csrf']               = $csrf;
        $this->formSchema['attributes']         = array_merge($this->formSchema['attributes'], $attributes);
        $this->addCsrfToken();
        $this->formSchema['inputSchema'] = array_merge($this->formSchema['inputSchema'], $inputSchema);

        return $this;
    }

    /**
     * Add csrf token to form
     *
     */
    protected function addCsrfToken()
    {
        $this->formSchema['attributes']['nocsrf'] = 'true'; // prevent global csrf function adding duplicate token
        if ($this->formSchema['csrf'] === true) {
            $this->addHidden('CSRFName', $this->csrf->getCsrfTokenName());
            $this->addHidden('CSRFValue', $this->csrf->getCsrfTokenValue());
        } else {
            //set nocsrf attribute in form
            $this->formSchema['attributes']['nocsrf'] = 'true';
        }
    }

    /**
     * Add a new hidden input
     *
     * @param string $name
     * @param string $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return \mindstellar\form\base\FormBuilder
     */
    public function addHidden(string $name, string $value = '', array $attributes = [], array $options = [])
    : FormBuilder
    {
        $this->formSchema['inputSchema'][] = [
            'name'       => $name,
            'type'       => 'hidden',
            'value'      => $value,
            'options'    => $options,
            'attributes' => $attributes
        ];

        return $this;
    }

    /**
     * Add Common options for all inputs
     *
     * @param array $options
     *
     * @return $this
     */
    public function addCommonInputOptions(array $options = [])
    : FormBuilder
    {
        $this->formSchema['commonInputOptions'] = array_merge($this->formSchema['commonInputOptions'], $options);

        return $this;
    }

    /**
     * Add Common attributes for all inputs
     *
     * @param array $attributes
     *
     * @return $this
     */
    public function addCommonInputAttributes(array $attributes = [])
    : FormBuilder
    {
        $this->formSchema['commonInputAttributes'] = array_merge($this->formSchema['commonInputAttributes'], $attributes);

        return $this;
    }

    /**
     * Add custom html/js
     *
     * @param string $content
     *
     * @return \mindstellar\form\base\FormBuilder
     */
    public function addHtmlContent(string $content)
    : FormBuilder
    {
        $this->formSchema['inputSchema'][] = [
            'value' => $content,
            'type'  => 'html'
        ];

        return $this;
    }

    /**
     * Add a new text input
     *
     * @param string $name
     * @param string $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return \mindstellar\form\base\FormBuilder
     */
    public function addText(string $name, string $value = '', array $attributes = [], array $options = [])
    : FormBuilder
    {
        $this->formSchema['inputSchema'][] = [
            'name'       => $name,
            'type'       => 'text',
            'value'      => $value,
            'options'    => $options,
            'attributes' => $attributes
        ];

        return $this;
    }

    /**
     * Add a new password input
     *
     * @param string $name
     * @param string $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return \mindstellar\form\base\FormBuilder
     */
    public function addPassword(string $name, string $value = '', array $attributes = [], array $options = [])
    : FormBuilder
    {
        $this->formSchema['inputSchema'][] = [
            'name'       => $name,
            'type'       => 'password',
            'value'      => $value,
            'options'    => $options,
            'attributes' => $attributes
        ];

        return $this;
    }

    /**
     * Add a new textarea input
     *
     * @param string $name
     * @param string $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return \mindstellar\form\base\FormBuilder
     */
    public function addTextarea(string $name, string $value = '', array $attributes = [], array $options = [])
    : FormBuilder
    {
        $this->formSchema['inputSchema'][] = [
            'name'       => $name,
            'type'       => 'textarea',
            'value'      => $value,
            'options'    => $options,
            'attributes' => $attributes
        ];

        return $this;
    }

    /**
     * Add a new select input
     *
     * @param string       $name
     * @param array|string $values
     * @param array        $attributes
     * @param array        $options
     *
     * @return \mindstellar\form\base\FormBuilder
     */
    public function addSelect(string $name, $values, array $attributes = [], array $options = [])
    : FormBuilder
    {
        $this->formSchema['inputSchema'][] = [
            'name'       => $name,
            'type'       => 'select',
            'value'      => $values,
            'options'    => $options,
            'attributes' => $attributes
        ];

        return $this;
    }

    /**
     * Add a new checkbox input
     *
     * @param string $name
     * @param array  $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return \mindstellar\form\base\FormBuilder
     */
    public function addCheckbox(string $name, array $value = [], array $attributes = [], array $options = [])
    : FormBuilder
    {
        $this->formSchema['inputSchema'][] = [
            'name'       => $name,
            'type'       => 'checkbox',
            'value'      => $value,
            'options'    => $options,
            'attributes' => $attributes
        ];

        return $this;
    }

    /**
     * Add a new radio input
     *
     * @param string $name
     * @param        $value
     * @param array  $attributes
     * @param array  $options
     *
     * @return \mindstellar\form\base\FormBuilder
     */
    public function addRadio(string $name, $value, array $attributes = [], array $options = [])
    : FormBuilder
    {
        $this->formSchema['inputSchema'][] = [
            'name'       => $name,
            'type'       => 'radio',
            'value'      => $value,
            'options'    => $options,
            'attributes' => $attributes
        ];

        return $this;
    }

    /**
     * Add a new file input
     *
     * @param string $name
     * @param array  $attributes
     * @param array  $options
     *
     * @return \mindstellar\form\base\FormBuilder
     */
    public function addFile(string $name, array $attributes = [], array $options = [])
    : FormBuilder
    {
        $this->formSchema['inputSchema'][] = [
            'name'       => $name,
            'type'       => 'file',
            'options'    => $options,
            'attributes' => $attributes
        ];

        return $this;
    }

    /**
     * render form using $this-formSchema with given inputs and add crsf token
     * Throw exception if name attributes is not set
     *
     * @return string
     * @throws \Exception
     */
    public function renderForm()
    : string
    {
        if (!isset($this->formSchema['attributes']['name'])) {
            throw new Exception('Form name attribute is not set');
        }
        $str = '<form' . $this->formAttributesString() . '>';
        $str .= $this->renderInputs();
        $str .= $this->renderSubmit();
        $str .= '</form>';

        return $str;
    }

    /**
     * Form attributes string from given schema
     *
     * @return string
     */
    private function formAttributesString()
    : string
    {
        $str = '';
        // if form attributes are given
        if (!empty($this->formSchema['attributes'])) {
            foreach ($this->formSchema['attributes'] as $key => $value) {
                $str .= ' ' . $key . '="' . $value . '"';
            }
        }

        return $str;
    }

    /**
     * render form inputs from given schema
     * @return string
     */
    private function renderInputs()
    : string
    {
        $str = '';
        if (!empty($this->formSchema['inputSchema'])) {
            foreach ($this->formSchema['inputSchema'] as $input) {
                switch ($input['type']) {
                    case 'html':
                        $str .= $input['value'];
                        break;
                    default:
                        if (isset($input['options'])) {
                            $input['options'] =
                                array_merge($this->formSchema['commonInputOptions'], $input['options']);
                        } else {
                            $input['options'] = $this->formSchema['commonInputOptions'];
                        }
                        if (isset($input['attributes'])) {
                            $input['attributes'] =
                                array_merge($this->formSchema['commonInputAttributes'], $input['attributes']);
                        } else {
                            $input['attributes'] = $this->formSchema['commonInputAttributes'];
                        }
                        // call $input['type] as FormInput method and pass $input['name'], $input['value'] and $input['options'] as arguments
                        $inputMethod = $input['type'];
                        $str         .= $this->formInputs->$inputMethod($input['name'], $input['value'], $input['attributes'],
                                                                        $input['options']);
                        break;
                }
                unset($input);
            }
        }

        return $str;
    }

    /**
     * render submit button
     *
     * @return string
     * @throws \Exception
     */
    private function renderSubmit()
    : string
    {
        $options    = [];
        $attributes = [];
        $value      = 'Submit';

        if (isset($this->formSchema['submitSchema']['value'], $this->formSchema['submitSchema']['attributes'])
        ) {
            $value      = $this->formSchema['submitSchema']['value'];
            $attributes = $this->formSchema['submitSchema']['attributes'];
        }

        return $this->formInputs->submit($value, $attributes, $options);
    }
}
