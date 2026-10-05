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

use mindstellar\admin\form\ApiSettingsScreen;
use mindstellar\admin\form\CommentSettingsScreen;
use mindstellar\admin\form\MainSettingsScreen;
use mindstellar\settings\SettingsPageRegistry;
use mindstellar\validation\InvalidException;

/**
 * The settings `/admin/settings` reads and changes: a fixed list, never a secret (keys,
 * passwords, signing secrets), and never the switch that turns the API off. A change is
 * saved through the settings screen's own form, so its checks, clean-up and save hooks run
 * as when an admin presses Save.
 */
final class ExposedSettings
{
    /**
     * API member => [the form that owns it, its field, its schema].
     *
     * @var array<string,array{0:class-string,1:string,2:array<string,mixed>}>
     */
    public const FIELDS = [
        'site_title'                   => [MainSettingsScreen::class, 'pageTitle', ['type' => 'string', 'minLength' => 1, 'maxLength' => 200]],
        'site_description'             => [MainSettingsScreen::class, 'pageDesc', ['type' => 'string', 'maxLength' => 1000]],
        'contact_email'                => [MainSettingsScreen::class, 'contactEmail', ['type' => 'string', 'format' => 'email', 'maxLength' => 100]],
        'language'                     => [MainSettingsScreen::class, 'language', ['type' => 'string', 'pattern' => '^[A-Za-z]{2,3}_[A-Za-z]{2}$', 'description' => 'One of the site\'s languages.']],
        'currency'                     => [MainSettingsScreen::class, 'currency', ['type' => 'string', 'pattern' => '^[A-Z]{3}$', 'description' => 'One of the site\'s currencies.']],
        'timezone'                     => [MainSettingsScreen::class, 'timezone', ['type' => 'string', 'maxLength' => 64]],
        'week_start'                   => [MainSettingsScreen::class, 'weekStart', ['type' => 'integer', 'minimum' => 0, 'maximum' => 6, 'description' => '0 is Sunday.']],
        'date_format'                  => [MainSettingsScreen::class, 'dateFormat', ['type' => 'string', 'maxLength' => 50, 'description' => 'A PHP date() format.']],
        'time_format'                  => [MainSettingsScreen::class, 'timeFormat', ['type' => 'string', 'maxLength' => 50, 'description' => 'A PHP date() format.']],
        'rss_items'                    => [MainSettingsScreen::class, 'num_rss_items', ['type' => 'integer', 'minimum' => 0]],
        'latest_listings_at_home'      => [MainSettingsScreen::class, 'max_latest_items_at_home', ['type' => 'integer', 'minimum' => 0]],
        'results_per_page'             => [MainSettingsScreen::class, 'default_results_per_page', ['type' => 'integer', 'minimum' => 0]],
        'selectable_parent_categories' => [MainSettingsScreen::class, 'selectable_parent_categories', ['type' => 'boolean']],
        'contact_attachments'          => [MainSettingsScreen::class, 'enabled_attachment', ['type' => 'boolean']],
        'comments_enabled'             => [CommentSettingsScreen::class, 'enabled_comments', ['type' => 'boolean']],
        'comments_need_account'        => [CommentSettingsScreen::class, 'reg_user_post_comments', ['type' => 'boolean']],
        'comments_captcha'             => [CommentSettingsScreen::class, 'enabled_recaptcha_comments', ['type' => 'boolean']],
        'comments_per_page'            => [CommentSettingsScreen::class, 'comments_per_page', ['type' => 'integer', 'minimum' => 0, 'description' => '0 shows every comment.']],
        'comments_notify_admin'        => [CommentSettingsScreen::class, 'notify_new_comment', ['type' => 'boolean']],
        'comments_notify_seller'       => [CommentSettingsScreen::class, 'notify_new_comment_user', ['type' => 'boolean']],
        'api_public_reads'             => [ApiSettingsScreen::class, 'api_public_reads', ['type' => 'boolean']],
        'api_cors_origins'             => [ApiSettingsScreen::class, 'api_cors_origins', ['type' => 'string', 'maxLength' => 4000, 'description' => 'One origin per line.']],
        'api_rate_limit_default'       => [ApiSettingsScreen::class, 'api_rate_limit_default', ['type' => 'integer', 'minimum' => 1]],
        'api_rate_limit_anon'          => [ApiSettingsScreen::class, 'api_rate_limit_anon', ['type' => 'integer', 'minimum' => 1]],
        'api_rate_limit_write'         => [ApiSettingsScreen::class, 'api_rate_limit_write', ['type' => 'integer', 'minimum' => 1]],
        'api_listing_rate'             => [ApiSettingsScreen::class, 'api_listing_rate', ['type' => 'integer', 'minimum' => 0]],
        'api_cache_max_age'            => [ApiSettingsScreen::class, 'api_cache_max_age', ['type' => 'integer', 'minimum' => 0]],
        'api_hide_phone'               => [ApiSettingsScreen::class, 'api_hide_phone', ['type' => 'boolean']],
        'api_photo_urls'               => [ApiSettingsScreen::class, 'api_photo_urls', ['type' => 'boolean']],
        'api_user_keys'                => [ApiSettingsScreen::class, 'api_user_keys', ['type' => 'boolean']],
        'api_registration'             => [ApiSettingsScreen::class, 'api_registration', ['type' => 'boolean']],
    ];

    /**
     * The members' schemas, for the Settings component.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function schemas(): array
    {
        return array_map(static fn (array $entry): array => $entry[2], self::FIELDS);
    }

    /**
     * Every exposed setting as it is stored now, typed.
     *
     * @return array<string,mixed>
     */
    public function read(): array
    {
        $values = [];
        $out    = [];
        foreach (self::FIELDS as $member => [$form, $field, $schema]) {
            $page = $form::register();
            $values[$page] ??= osc_settings_values($page);
            $out[$member] = self::typed($values[$page][$field] ?? null, (string) $schema['type']);
        }

        return $out;
    }

    /**
     * Change some settings, all or none: each form they belong to is saved whole, with its
     * stored values and the sent ones over them.
     *
     * @param array<string,mixed> $patch member => value, already checked against the schema
     *
     * @throws InvalidException with what the forms refused
     */
    public function save(array $patch): void
    {
        $byPage = [];
        foreach ($patch as $member => $value) {
            [$form, $field] = self::FIELDS[$member];
            $byPage[$form::register()][$field] = $value;
        }
        osc_db_transaction(static function () use ($byPage): void {
            $errors = [];
            foreach ($byPage as $page => $changes) {
                $post   = self::post(osc_settings_values($page), $changes, SettingsPageRegistry::instance()->fields($page));
                $result = \Params::withRequest($post, static fn () => osc_settings_save($page));
                foreach ((array) ($result['errors'] ?? []) as $error) {
                    $errors[] = ['pointer' => '', 'code' => 'rejected', 'message' => trim(strip_tags((string) $error))];
                }
            }
            if ($errors !== []) {
                throw InvalidException::all($errors);
            }
        });
    }

    /**
     * A form's whole submission as a browser would post it: a ticked box is present, an
     * unticked one absent, everything else a string.
     *
     * @param array<string,mixed>                $stored  field => stored value
     * @param array<string,mixed>                $changes field => new value
     * @param array<string,array<string,mixed>>  $fields  the form's declared fields
     *
     * @return array<string,string>
     */
    private static function post(array $stored, array $changes, array $fields): array
    {
        $post = [];
        foreach (array_replace($stored, $changes) as $name => $value) {
            if (is_array($value) || $value === null) {
                continue;
            }
            if (($fields[$name]['type'] ?? '') === 'checkbox') {
                if ($value === true || (string) $value === '1') {
                    $post[$name] = '1';
                }
                continue;
            }
            $post[$name] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $post;
    }

    private static function typed(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => $value === true || (string) $value === '1',
            'integer' => (int) $value,
            default   => $value === null ? '' : (string) $value,
        };
    }
}
