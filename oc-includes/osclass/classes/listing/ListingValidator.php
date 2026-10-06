<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\listing;

use mindstellar\utility\Sanitize;

/**
 * The listing form's own checks, for every way a listing is saved: lengths, places, price,
 * phone, the category's custom fields, and Akismet.
 */
final class ListingValidator
{
    /**
     * Widths of the t_item_location and t_item columns a submitted listing fills, from
     * struct.sql. A value wider than its column is cut short on a relaxed connection and
     * rejects the whole insert on a strict one, so each is refused by name first;
     * tests/strict-write-guards.php reads this and pins it against the live schema.
     */
    public const COLUMN_WIDTHS = array(
        's_country'       => 80,
        's_region'        => 100,
        's_city'          => 100,
        's_city_area'     => 200,
        's_address'       => 100,
        's_zip'           => 15,
        's_contact_phone' => 40,
    );

    /** Widths of the t_item contact columns a listing form fills. */
    public const CONTACT_WIDTHS = array(
        's_contact_name'  => 100,
        's_contact_email' => 140,
    );

    private Sanitize $sanitize;

    public function __construct()
    {
        $this->sanitize = new Sanitize();
    }

    /**
     * One refusal as the listing errors carry it.
     *
     * @param string $pointer a JSON pointer to the API member, '' for the listing as a whole
     * @param string $code    a short code, e.g. `too_short`, `too_long`, `invalid`, `required`
     *
     * @return array{pointer:string,code:string,message:string}
     */
    public static function entry(string $pointer, string $code, string $message): array
    {
        return array('pointer' => $pointer, 'code' => $code, 'message' => $message);
    }

    /**
     * Validate common inputs while editing/publishing
     *
     * Photo checks add their own notice to $notices too, as the form shows both.
     *
     * @param array<string,mixed> $aItem    the prepared listing data
     * @param string[]            $notices
     *
     * @return array<int,array{pointer:string,code:string,message:string}> empty when there are none
     */
    public function common(array $aItem, array &$notices = array()): array
    {
        $errors = array();
        if (!PhotoService::checkTypes($aItem['photos'], $notices)) {
            $errors[] = self::entry('/photos', 'invalid', _m('Image with an incorrect extension.'));
        }
        if (!PhotoService::checkSizes($aItem['photos'], $notices)) {
            $errors[] = self::entry('/photos', 'too_large', _m('Image is too big. Max. size') . osc_max_size_kb() . ' Kb');
        }

        // One title is enough, but a too-long one is refused in every language.
        $maxTitle = osc_max_characters_per_title();
        $tooLong  = array();
        $tooShort = array();
        $hasTitle = false;
        foreach ($aItem['title'] as $key => $value) {
            if (!osc_validate_max($value, $maxTitle)) {
                $tooLong[] = self::entry('/title', 'too_long', sprintf(_m('Title too long (%s).'), $key));
            } elseif (osc_validate_text($value)) {
                $hasTitle = true;
            } else {
                $tooShort[] = self::entry('/title', 'too_short', sprintf(_m('Title too short (%s).'), $key));
            }
        }
        $errors = array_merge($errors, $hasTitle || $tooLong !== array() ? array() : $tooShort, $tooLong);

        $descErrors = array();
        foreach ($aItem['description'] as $key => $value) {
            if (osc_validate_text($value, 3) && osc_validate_max($value, osc_max_characters_per_description())) {
                $descErrors = array();
                break;
            }
            if (!osc_validate_text($value, 3)) {
                $descErrors[] = self::entry('/description', 'too_short', sprintf(_m('Description too short (%s).'), $key));
            }
            if (!osc_validate_max($value, osc_max_characters_per_description())) {
                $descErrors[] = self::entry('/description', 'too_long', sprintf(_m('Description too long (%s).'), $key));
            }
        }
        $errors = array_merge($errors, $descErrors);

        if (!osc_validate_category($aItem['catId'])) {
            $errors[] = self::entry('/category_id', 'invalid', _m('Category invalid.'));
        }
        if (!osc_validate_number($aItem['price'])) {
            $errors[] = self::entry('/price', 'invalid', _m('Price must be a number.'));
        }
        if ($aItem['price'] !== null && !osc_validate_max(number_format($aItem['price'], 0, '', ''), 15)) {
            $errors[] = self::entry('/price', 'too_long', _m('Price too long.'));
        }
        if ($aItem['price'] !== null && (float)$aItem['price'] < 0) {
            $errors[] = self::entry('/price', 'invalid', _m('Price must be positive number.'));
        }
        if (!osc_validate_text($aItem['countryName'], 3, false)) {
            $errors[] = self::entry('/country', 'too_short', _m('Country too short.'));
        }
        if (!osc_validate_text($aItem['regionName'], 2, false)) {
            $errors[] = self::entry('/region', 'too_short', _m('Region too short.'));
        }
        if (!osc_validate_text($aItem['cityName'], 2, false)) {
            $errors[] = self::entry('/city', 'too_short', _m('City too short.'));
        }
        if (!osc_validate_text($aItem['cityArea'], 3, false)) {
            $errors[] = self::entry('/city_area', 'too_short', _m('Municipality too short.'));
        }
        if (!osc_validate_text($aItem['address'], 3, false)) {
            $errors[] = self::entry('/address', 'too_short', _m('Address too short.'));
        }
        // The input key each capped column is filled from, the API member and what to say when
        // it does not fit. The widths themselves are COLUMN_WIDTHS, pinned against the live schema.
        $capped = array(
            's_country'       => array('countryName', '/country', _m('Country too long.')),
            's_region'        => array('regionName', '/region', _m('Region too long.')),
            's_city'          => array('cityName', '/city', _m('City too long.')),
            's_city_area'     => array('cityArea', '/city_area', _m('Municipality too long.')),
            's_address'       => array('address', '/address', _m('Address too long.')),
            's_zip'           => array('s_zip', '/zip', _m('Zip code too long.')),
            's_contact_phone' => array('contactPhone', '/contact_phone', _m('Phone too long.')),
        );
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            [$key, $pointer, $message] = $capped[$column];
            if (!osc_validate_max((string)($aItem[$key] ?? ''), $width)) {
                $errors[] = self::entry($pointer, 'too_long', $message);
            }
        }
        // Checked after Sanitize::phone() has reduced the input to digits and a leading
        // plus, so this is the format of what would be stored, not of what was typed.
        if (!osc_validate_phone((string)($aItem['contactPhone'] ?? ''), 4)) {
            $errors[] = self::entry('/contact_phone', 'invalid', _m('Phone invalid.'));
        }

        return $errors;
    }

    /**
     * Errors for contact values wider than the t_item columns that hold them.
     *
     * @param array<string,mixed> $aItem
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    public function contactWidths(array $aItem): array
    {
        $errors = array();
        if (!osc_validate_max((string)($aItem['contactName'] ?? ''), self::CONTACT_WIDTHS['s_contact_name'])) {
            $errors[] = self::entry('/contact_name', 'too_long', _m('Name too long.'));
        }
        if (!osc_validate_max((string)($aItem['contactEmail'] ?? ''), self::CONTACT_WIDTHS['s_contact_email'])) {
            $errors[] = self::entry('/contact_email', 'too_long', _m('Email too long.'));
        }

        return $errors;
    }

    /**
     * Whether Akismet judges any locale of this listing to be spam.
     *
     * @param array<string,string> $title       Title per locale
     * @param array<string,string> $description Description per locale
     * @param string               $author
     * @param string               $email
     * @param string               $ip          the poster's address
     *
     * @return bool
     *
     */
    public function isSpam($title, $description, $author, $email, string $ip): bool
    {
        $spam = false;
        if (osc_akismet_key()) {
            foreach ($title as $k => $_data) {
                $_title       = $_data;
                $_description = $description[$k];
                $content      = $_title . ' ' . $_description;

                $akismet = new \Akismet(osc_base_url(), osc_akismet_key());

                $akismet->setCommentContent($content);
                $akismet->setCommentAuthor($author);
                $akismet->setCommentAuthorEmail($email);
                $akismet->setUserIP($ip);

                $status = '';
                try {
                    if ($akismet->isCommentSpam()) {
                        $status = 'SPAM';
                    }
                } catch (\Exception $e) {
                    trigger_error($e->getMessage(), E_USER_NOTICE);
                }
                if ($status === 'SPAM') {
                    $spam = true;
                    break;
                }
            }
        }

        return $spam;
    }

    /**
     * Validate Item meta field and check required fields are not empty
     *
     * @param array<int,array<string,mixed>> $_meta       The category's field definitions
     * @param array<int,mixed>|mixed          $meta        Submitted values, sanitised in place
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    public function meta(array $_meta, &$meta): array
    {
        // A category with no custom fields takes no values, or they would be stored under
        // other categories' field ids unchecked.
        if (empty($_meta)) {
            $meta = array();

            return array();
        }
        if (is_array($meta)) {
            $valid_id = array_column($_meta, 'pk_i_id');
            // special case for checkboxes
            foreach ($_meta as $value) {
                if (isset($value['e_type']) && $value['e_type'] === 'CHECKBOX') {
                    $meta[$value['pk_i_id']] = ($meta[$value['pk_i_id']] ?? 0);
                }
            }
            foreach ($meta as $k => $v) {
                if (!in_array($k, $valid_id, false)) {
                    unset($meta[$k]);
                } else {
                    $key = array_search($k, array_column($_meta, 'pk_i_id'), false);
                    // Sanitize by type
                    $meta[$k] = $this->sanitizeMeta($_meta[$key]['e_type'], $v);
                }
                unset($k, $v);
            }
            [$meta, $errors] = $this->validateMeta($_meta, $meta);

            return $errors;
        }

        return array();
    }

    /**
     * Sanitise one submitted custom-field value according to its field type.
     *
     * @param string $e_type
     * @param mixed  $metaValue
     *
     * @return mixed same shape as $metaValue
     */
    private function sanitizeMeta($e_type, $metaValue)
    {
        switch ($e_type) {
            case 'DATEINTERVAL':
                if (!empty($metaValue)) {
                    if ($metaValue['from']) {
                        $metaValue['from'] = (int)$metaValue['from'];
                    }
                    if ($metaValue['to']) {
                        $metaValue['to'] = (int)$metaValue['to'];
                    }
                }
                break;
            case 'DATE':
                if (!empty($metaValue)) {
                    $metaValue = (int)$metaValue;
                }
                break;
            case 'CHECKBOX':
                $metaValue = (int)$metaValue;
                break;
            case 'URL':
                $metaValue = $this->sanitize->websiteUrl($metaValue);
                break;
            default:
                // sanitize string safe for html
                $metaValue = $this->sanitize->html($metaValue);
                break;
        }

        return $metaValue;
    }

    /**
     * Apply the conditional and required rules to the submitted custom-field values.
     *
     * @param array<int,array<string,mixed>> $_meta       The category's field definitions
     * @param array<int,mixed>               $meta        Submitted values
     *
     * @return array{0:array<int,mixed>,1:array<int,array{pointer:string,code:string,message:string}>} the surviving values and the errors
     */
    private function validateMeta($_meta, $meta)
    {
        $errors = array();
        // Map slug -> submitted value so conditional rules (stored by slug) can be
        // re-evaluated server-side; the client engine is UX only.
        $slugValues = array();
        foreach ($_meta as $_m) {
            $slugValues[$_m['s_slug']] = $meta[$_m['pk_i_id']] ?? null;
        }

        foreach ($_meta as $_m) {
            $pointer = '/custom_fields/' . $_m['pk_i_id'];
            // Conditional logic: a field hidden by its show_when rule is not part of
            // this submission — drop any value and never require it. A required_when
            // rule overrides the field's static required flag.
            $rules = (isset($_m['rules']) && is_array($_m['rules'])) ? $_m['rules'] : array();
            if (isset($rules['show_when']) && !$this->condition($rules['show_when'], $slugValues)) {
                unset($meta[$_m['pk_i_id']]);
                continue;
            }
            $isMetaRequired = $_m['b_required'];
            if (isset($rules['required_when'])) {
                $isMetaRequired = $this->condition($rules['required_when'], $slugValues) ? 1 : 0;
            }
            $isMetaValueSet = isset($meta[$_m['pk_i_id']]);
            $metaValue      = $meta[$_m['pk_i_id']] ?? null;

            // Registry-defined types (e.g. EMAIL) validate their stored value here,
            // on top of the storage primitive's required/format checks below.
            if ($isMetaValueSet && $metaValue !== '' && $metaValue !== null) {
                $typeSpec = osc_field_type(osc_field_resolve_type($_m));
                if ($typeSpec !== null && is_callable($typeSpec['validate'])) {
                    $typeError = call_user_func($typeSpec['validate'], $metaValue, $_m);
                    if (is_string($typeError) && $typeError !== '') {
                        $errors[] = self::entry($pointer, 'invalid', $typeError);
                    }
                }
            }

            switch ($_m['e_type']) {
                case 'DATEINTERVAL':
                    if ($isMetaValueSet && $metaValue) {
                        if ($metaValue['from'] && $metaValue['to']) {
                            if (!is_numeric($metaValue['from']) || !is_numeric($metaValue['to'])) {
                                $errors[] = self::entry($pointer, 'invalid', sprintf(_m('%s is invalid.'), $_m['s_name']));
                            }
                        } elseif ($isMetaRequired) {
                            $errors[] = self::entry($pointer, 'required', sprintf(_m('%s is required.'), $_m['s_name']));
                        }
                    } elseif ($isMetaRequired) {
                        $errors[] = self::entry($pointer, 'required', sprintf(_m('%s is required.'), $_m['s_name']));
                    }
                    break;
                case 'CHECKBOX':
                case 'NUMBER':
                case 'DATE':
                    if ($isMetaValueSet && $metaValue > 0) {
                        if (!is_numeric($metaValue)) {
                            $errors[] = self::entry($pointer, 'invalid', sprintf(_m('%s is invalid.'), $_m['s_name']));
                        }
                    } elseif ($isMetaRequired) {
                        $errors[] = self::entry($pointer, 'required', sprintf(_m('%s is required.'), $_m['s_name']));
                    }
                    break;
                case 'RADIO':
                case 'DROPDOWN':
                    if ($isMetaValueSet && $metaValue) {
                        // Cascading option fields validate against the option set for
                        // the parent's submitted value (falling back to the union), not
                        // the flat s_options list (which is empty for a cascade child).
                        if (!empty($_m['cascade_map']) && is_array($_m['cascade_map'])) {
                            $parentSlug  = $_m['cascade_parent'] ?? '';
                            $parentValue = $slugValues[$parentSlug] ?? '';
                            if (isset($_m['cascade_map'][$parentValue])) {
                                $allowed = $_m['cascade_map'][$parentValue];
                            } else {
                                $allowed = array();
                                foreach ($_m['cascade_map'] as $opts) {
                                    $allowed = array_merge($allowed, (array)$opts);
                                }
                            }
                            if (!in_array($metaValue, $allowed, false)) {
                                $errors[] = self::entry($pointer, 'invalid', sprintf(_m('%s is invalid.'), $_m['s_name']));
                            }
                        } elseif (!in_array($metaValue, explode(',', $_m['s_options']), false)) {
                            // check value exist in options csv
                            $errors[] = self::entry($pointer, 'invalid', sprintf(_m('%s is invalid.'), $_m['s_name']));
                        }
                    } elseif ($isMetaRequired) {
                        $errors[] = self::entry($pointer, 'required', sprintf(_m('%s is required.'), $_m['s_name']));
                    }
                    break;
                case 'URL':
                    if ($isMetaValueSet && $metaValue) {
                        // first validate using filter_var than osc_validate_url
                        if (!filter_var($metaValue, FILTER_VALIDATE_URL)) {
                            $errors[] = self::entry($pointer, 'invalid', sprintf(_m('%s is invalid.'), $_m['s_name']));
                        } elseif (!osc_validate_url($metaValue)) {
                            $errors[] = self::entry($pointer, 'invalid', sprintf(_m('%s is invalid.'), $_m['s_name']));
                        }
                    } elseif ($isMetaRequired) {
                        $errors[] = self::entry($pointer, 'required', sprintf(_m('%s is required.'), $_m['s_name']));
                    }
                    break;
                case 'TEXTAREA':
                case 'TEXT':
                default:
                    if ($isMetaRequired && (!$isMetaValueSet || !$metaValue)) {
                        $errors[] = self::entry($pointer, 'required', sprintf(_m('%s is required.'), $_m['s_name']));
                    }
                    break;
            }
        }

        return array($meta, $errors);
    }

    /**
     * Evaluate a single conditional-logic condition (the value stored under a rule's
     * show_when/required_when key) against the submitted field values, keyed by the
     * controlling field's slug. Mirrors the client engine so client and server agree.
     *
     * @param array $cond       {field: slug, op: eq|neq|filled|gt|lt, value?: mixed}
     * @param array $slugValues submitted meta values keyed by field slug
     *
     * @return bool
     */
    private function condition($cond, $slugValues)
    {
        if (!is_array($cond) || empty($cond['field'])) {
            return true;
        }
        $actual   = $slugValues[$cond['field']] ?? '';
        if (is_array($actual)) {
            // interval/number ranges have no single scalar; treat as filled/empty only
            $actual = implode('', array_map('strval', $actual));
        }
        $expected = isset($cond['value']) ? (string)$cond['value'] : '';
        $op       = $cond['op'] ?? 'eq';
        switch ($op) {
            case 'neq':
                return (string)$actual !== $expected;
            case 'filled':
                return trim((string)$actual) !== '';
            case 'gt':
                return is_numeric($actual) && is_numeric($expected) && (float)$actual > (float)$expected;
            case 'lt':
                return is_numeric($actual) && is_numeric($expected) && (float)$actual < (float)$expected;
            case 'eq':
            default:
                return (string)$actual === $expected;
        }
    }
}
