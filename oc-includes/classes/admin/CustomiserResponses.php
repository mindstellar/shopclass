<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\admin;

use mindstellar\utility\AjaxResponse;
use mindstellar\widgets\Customiser;

/**
 * The JSON answers of the Customise screen's draft, publish and discard posts, shared by the
 * theme's Customise screen and a page's Edit blocks screen. The controller checks access and
 * the CSRF token first, then hands over the customiser.
 */
trait CustomiserResponses
{
    /**
     * Save the posted change into the draft.
     *
     * @param string $previewUrl where the preview frame reloads after the save
     */
    private function answerCustomiserDraft(Customiser $customiser, string $previewUrl): void
    {
        try {
            $result = $customiser->saveDraft(
                \Params::getParamArray('locations'),
                \Params::getParamArray('locations', false, false, false),
                \Params::getParamArray('settings', false, false, false),
                // Page text is admin markup, stored unpurified as the page form stores it.
                \Params::getParamArray('page_text', false, false, false)
            );
        } catch (\Throwable $e) {
            AjaxResponse::json(array('error' => 1, 'errors' => array(_m('The draft could not be saved.'))), 500);

            return;
        }
        if ($result['status'] !== 200) {
            AjaxResponse::json(array('error' => 1, 'errors' => $result['errors']), $result['status']);

            return;
        }

        AjaxResponse::json(array(
            'error'       => 0,
            'saved_at'    => $result['saved_at'],
            'preview_url' => $previewUrl,
        ));
    }

    /**
     * Publish the draft and purge the page cache for widgets.
     *
     * @param string $failed the message when publishing throws
     */
    private function answerCustomiserPublish(Customiser $customiser, string $failed): void
    {
        try {
            $result = $customiser->publish(\Params::getParamString('force') === '1');
        } catch (\Throwable $e) {
            AjaxResponse::json(array('error' => 1, 'errors' => array($failed), 'stale' => false), 500);

            return;
        }
        if ($result['status'] === 200) {
            osc_purge_page_cache('widget');
        }

        AjaxResponse::json(array('error' => $result['status'] === 200 ? 0 : 1, 'errors' => $result['errors'], 'stale' => !empty($result['stale'])), $result['status']);
    }

    /**
     * Throw the draft away.
     */
    private function answerCustomiserDiscard(Customiser $customiser): void
    {
        try {
            $customiser->discard();
            $ok = true;
        } catch (\Throwable $e) {
            $ok = false;
        }

        AjaxResponse::json(array('error' => $ok ? 0 : 1), $ok ? 200 : 500);
    }
}
