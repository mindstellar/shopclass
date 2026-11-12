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

    /**
     * The From address of the install mail: the admin's address, else an address on the site's domain.
     */
    public static function mailFrom(string $adminEmail, string $serverName, string $siteUrl): string
    {
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) !== false) {
            return $adminEmail;
        }
        $host = strtolower($serverName !== '' ? $serverName : (string) parse_url($siteUrl, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);

        return 'noreply@' . ($host !== '' ? $host : 'localhost');
    }

    /**
     * Sort the country rows by display name, using intl rules when it is there.
     *
     * @param array<int, array<string, mixed>> $rows catalogue rows with a 'name'
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sortCountries(array $rows): array
    {
        $collator = class_exists('Collator') ? new \Collator('en') : null;
        usort($rows, static function (array $a, array $b) use ($collator): int {
            $x = (string) $a['name'];
            $y = (string) $b['name'];

            return $collator !== null ? (int) $collator->compare($x, $y) : strnatcasecmp($x, $y);
        });

        return $rows;
    }

    /**
     * The first timezone of a country (a code or a catalogue file name such as "IN.json"), else UTC.
     */
    public static function timezoneForCountry(string $location): string
    {
        $code = self::countryCode($location);
        if ($code !== null) {
            $zones = @\DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $code);
            if (is_array($zones) && $zones !== []) {
                return (string) $zones[0];
            }
        }

        return 'UTC';
    }

    /**
     * The country code whose places the Your-site step imported, or null.
     */
    public static function chosenCountry(): ?string
    {
        $country = (string) \Session::getInstance()->_get('install_country');

        return $country !== '' ? $country : null;
    }

    /**
     * The two-letter code in a code or catalogue file name such as "IN.json", upper case, else null.
     */
    public static function countryCode(string $location): ?string
    {
        return preg_match('/^([A-Za-z]{2})(?:[._-]|$)/', $location, $m) === 1 ? strtoupper($m[1]) : null;
    }
}
