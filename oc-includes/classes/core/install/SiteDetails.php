<?php

declare(strict_types=1);

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\install;

/**
 * Server check of the installer's site details form.
 *
 * @package mindstellar\install
 */
final class SiteDetails
{
    /**
     * The first problem with the answers, or null when they are fine.
     *
     * @return array{error:string,field:string}|null
     */
    public static function problem(string $adminUser, string $email, string $siteName): ?array
    {
        if ($adminUser !== '' && !preg_match('/^[A-Za-z0-9]+$/', $adminUser)) {
            return array('error' => __('The admin username may only contain letters and numbers.'), 'field' => 'admin_user');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return array('error' => __('Enter a valid contact email address.'), 'field' => 'email');
        }
        if (trim($siteName) === '') {
            return array('error' => __('Give your site a name.'), 'field' => 'webtitle');
        }

        return null;
    }
}
