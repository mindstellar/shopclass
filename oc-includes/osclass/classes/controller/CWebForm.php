<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\forms\FieldValidator;
use mindstellar\model\FormSubmission;

/**
 * Class CWebForm — the public endpoint for a form placed off an item (a core.form
 * block on a page/layout). The only action is `submit`. Item forms do NOT go
 * through here; they keep their existing publish/edit save path (t_item_meta).
 *
 * The submit path is CSRF-protected (the shutdown injector adds the token to the
 * rendered <form>), honeypot- and ban-filtered, server-authoritatively validated
 * (FieldValidator re-evaluates rules and cascades), and stored transactionally.
 * It always redirects back to the referring page with a flash message and never
 * reflects the posted values, so it is not an XSS or open-redirect surface.
 */
class CWebForm extends BaseModel
{
    public function __construct()
    {
        parent::__construct();
    }

    public function doModel()
    {
        switch ($this->action) {
            case 'submit':
                $this->submit();
                break;
            default:
                $this->redirectTo(osc_base_url());
        }
    }

    private function submit()
    {
        osc_csrf_check();

        $return = $this->safeReturnUrl();

        $formId = (int) Params::getParam('osc_form_id');
        $form   = $formId > 0 ? FieldGroup::newInstance()->findByPrimaryKey($formId) : array();
        if (empty($form)) {
            osc_add_flash_error_message(_m('That form is no longer available.'));
            $this->redirectTo($return);

            return;
        }

        list($contextType, $contextId) = $this->parseContext(Params::getParam('osc_form_context'));

        // Honeypot: silently accept (so the bot believes it worked) but store nothing.
        if (trim((string) Params::getParam('osc_hp')) !== '') {
            osc_add_flash_ok_message(_m('Thank you! Your submission has been received.'));
            $this->redirectTo($return);

            return;
        }

        // IP ban (same gate as the contact form).
        if (osc_is_banned('', get_ip()) === 2) {
            osc_add_flash_error_message(_m('Your current IP is not allowed'));
            $this->redirectTo($return);

            return;
        }

        // Same field set the render used (findByGroup + the form_fields filter), so
        // a plugin's per-context add/remove can't create a validate/render mismatch.
        $fields = osc_form_fields($formId, $contextType, $contextId);
        if (empty($fields)) {
            osc_add_flash_error_message(_m('That form has no fields.'));
            $this->redirectTo($return);

            return;
        }

        $meta   = Params::getParam('meta');
        $meta   = is_array($meta) ? $meta : array();
        $result = FieldValidator::process($fields, $meta);

        // Plugins may amend the validation errors (add or clear their own).
        $errors = osc_apply_filter('form_validation_errors', $result['errors'], $form, $result['values'], $contextType, $contextId);
        $result['errors'] = is_array($errors) ? $errors : $result['errors'];

        // Let a plugin veto or flag (e.g. spam). A non-empty string return is treated
        // as a rejection message.
        $vetoed = osc_apply_filter('form_submit_veto', '', $form, $result['values'], $contextType, $contextId);

        if (!empty($result['errors']) || (is_string($vetoed) && $vetoed !== '')) {
            $message = !empty($result['errors']) ? implode(' ', $result['errors']) : $vetoed;
            osc_add_flash_error_message(osc_esc_html($message));
            $this->redirectTo($return);

            return;
        }

        osc_run_hook('form_submit', $form, $result['values'], $contextType, $contextId);

        $userId = osc_is_web_user_logged_in() ? (int) osc_logged_user_id() : null;
        $submissionId = FormSubmission::newInstance()->create(
            $formId,
            $contextType,
            $contextId,
            $userId,
            get_ip(),
            $result['values']
        );

        if ($submissionId === false) {
            osc_add_flash_error_message(_m('Sorry, your submission could not be saved. Please try again.'));
            $this->redirectTo($return);

            return;
        }

        osc_run_hook('form_submitted', $submissionId, $form, $result['values'], $contextType, $contextId);

        osc_add_flash_ok_message(_m('Thank you! Your submission has been received.'));
        $this->redirectTo($return);
    }

    /**
     * Parse the "type:id" context pair, validating the type as a slug.
     *
     * @param mixed $raw
     *
     * @return array [string $type, int $id]
     */
    private function parseContext($raw)
    {
        $raw = is_string($raw) ? $raw : '';
        $pos = strpos($raw, ':');
        if ($pos === false) {
            return array('widget', 0);
        }
        $type = substr($raw, 0, $pos);
        $id   = (int) substr($raw, $pos + 1);
        if (!preg_match('/^[a-z0-9_.-]{1,20}$/', $type)) {
            $type = 'widget';
        }

        return array($type, $id);
    }

    /**
     * Where to send the user after submit: the referring page when it is on this
     * site, else the home page. Never an attacker-controlled off-site URL.
     *
     * @return string
     */
    private function safeReturnUrl()
    {
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        if ($referer !== '') {
            $refHost  = parse_url($referer, PHP_URL_HOST);
            $baseHost = parse_url(osc_base_url(), PHP_URL_HOST);
            if ($refHost !== null && $baseHost !== null && strcasecmp($refHost, $baseHost) === 0) {
                return $referer;
            }
        }

        return osc_base_url();
    }

    /**
     * @param $file
     *
     * @return void
     */
    public function doView($file)
    {
        osc_current_web_theme_path($file);
    }
}

/* file end: ./CWebForm.php */
