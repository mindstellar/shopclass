<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

use mindstellar\storage\ProviderPresets;

/**
 * The storage screen's connection form, where every rule corrects a value rather than refusing it.
 * The secret key is never drawn back, and a blank one leaves the stored key alone.
 *
 * @package mindstellar\admin\form
 */
final class StorageSettingsForm
{
    public const PAGE_ID = 'core.settings_storage';

    public const PROVIDER = 'storage_s3_provider';

    public const SECRET = 'storage_s3_secret_key';

    /** The provider a blank or unknown one becomes, and the one the form opens on. */
    public const DEFAULT_PROVIDER = 'custom';

    /** What storage_s3_signed_ttl holds when nothing above zero was given. */
    public const DEFAULT_TTL = 900;

    /** The range S3 signs a URL for, in seconds. */
    public const MIN_TTL = 60;

    public const MAX_TTL = 604800;

    /** The browser's half of the URL rule, on both URL boxes. */
    private const URL_ATTRS = array('inputmode' => 'url', 'pattern' => '[Hh][Tt][Tt][Pp][Ss]?://.*');

    /**
     * Declare the form, once per request.
     *
     * @return string the page id
     */
    public static function register(): string
    {
        if (osc_settings_page(self::PAGE_ID) !== null) {
            return self::PAGE_ID;
        }

        $providers = array();
        foreach (ProviderPresets::PRESETS as $id => $preset) {
            $providers[$id] = $preset['label'];
        }

        CoreSettings::page(self::PAGE_ID, __('Storage Settings'))
            ->select('storage_active', __('Active storage'), array(
                'local' => __('Local disk'),
                's3'    => __('Amazon S3-compatible'),
            ))
                ->default('local')
                ->sanitize(static function ($value) {
                    return self::backend($value);
                })
                // Again on the way to storage: a value posted as a list skips a field's sanitiser.
                ->persist(static function ($value) {
                    return self::backend($value);
                })
            ->select(
                self::PROVIDER,
                __('Provider'),
                $providers,
                __('Prefills the connection fields below with a starting point for the selected provider. '
                   . 'Review every field before saving.')
            )
                ->set('id', 'storage_provider')
                ->default(self::DEFAULT_PROVIDER)
                ->sanitize(static function ($value) {
                    return self::provider($value);
                })
                ->persist(static function ($value) {
                    return self::provider($value);
                })
            ->text('storage_s3_bucket', __('Bucket'))
            ->text('storage_s3_region', __('Region'))
                // At the write, so the provider is the one a before_save listener left.
                ->persist(static fn ($value, array $values) => self::region(
                    is_string($value) ? $value : '',
                    self::provider($values[self::PROVIDER] ?? null)
                ))
            // Text rather than url: the url type refuses hosts PHP will not validate, such as
            // a Docker service name with an underscore, which the screen has always stored. The
            // browser still gets a URL keyboard and the http(s) rule the save applies.
            ->text(
                'storage_s3_endpoint',
                __('Endpoint'),
                __('Leave the provider-specific placeholders (e.g. {region}, {account_id}) filled in '
                   . 'with your own values.')
            )
                ->width('key')
                ->attrs(self::URL_ATTRS)
                ->sanitize(static function ($value) {
                    return self::httpUrlOrEmpty($value);
                })
            ->text('storage_s3_access_key', __('Access key'))
                ->width('key')
            ->secret(self::SECRET, __('Secret key'))
                ->set('reveal', true)
                ->set('placeholder', __('Leave blank to keep the currently saved secret key'))
                ->set('attrs', array('autocomplete' => 'new-password'))
                ->writeOnly()
                ->persist(static fn ($value) => $value === '' ? null : $value)
            ->checkbox(
                'storage_s3_path_style',
                __('Use path-style bucket URLs.'),
                __('Required by MinIO and most self-hosted setups; leave off for AWS.')
            )
                ->rowLabel(__('Path-style URLs'))
                ->set('id', 'storage_s3_path_style')
            ->text('storage_s3_public_url', __('Public URL'))
                ->width('key')
                ->attrs(self::URL_ATTRS)
                ->set(
                    'help_html',
                    '<span id="storage_public_url_hint">'
                    . osc_esc_html(__('Optional. Overrides the URL used to serve files, e.g. a CDN domain in front of the bucket.'))
                    . '</span>'
                )
                ->sanitize(static function ($value) {
                    return self::httpUrlOrEmpty($value);
                })
            ->checkbox(
                'storage_s3_signed_urls',
                __('Serve files through time-limited signed URLs.'),
                __('Use this for a private bucket. Leave off for a public bucket or CDN.')
            )
                ->rowLabel(__('Signed URLs'))
                ->set('id', 'storage_s3_signed_urls')
            ->number('storage_s3_signed_ttl', __('Signed URL TTL'), __('How long a signed URL stays valid (60-604800).'))
                ->set('min', self::MIN_TTL)
                ->set('max', self::MAX_TTL)
                ->suffix(__('seconds'))
                ->default(self::DEFAULT_TTL)
                ->sanitize(static function ($value) {
                    return self::ttl($value);
                })
                ->persist(static function ($value) {
                    return self::ttl($value);
                })
            ->select('storage_keep_local', __('Local copies'), array(
                'all'  => __('Keep local copies'),
                'none' => __('Delete after upload'),
            ))
                ->default('all')
                ->sanitize(static function ($value) {
                    return self::keepLocal($value);
                })
                ->persist(static function ($value) {
                    return self::keepLocal($value);
                })
            ->custom('storage_clear', static function () {
                echo '<div class="clear"></div>';
            })
                ->set('row', false)
            ->register();

        return self::PAGE_ID;
    }

    /**
     * What the view needs to draw the form.
     *
     * @param array<string,mixed>|null $values values a rejected save is handing back, or null
     *                                         for the stored ones
     *
     * @return array<string,mixed> view variables for osc_admin_settings_form()
     */
    public static function formVars(?array $values = null): array
    {
        $pageId = self::register();

        if ($values === null) {
            $values = osc_settings_values($pageId);
            // Read as the screen always read them: a stored value no option names opens on
            // the default, and a zero lifetime is the default lifetime.
            $values['storage_active']        = self::backend($values['storage_active']);
            $values['storage_keep_local']    = self::keepLocal($values['storage_keep_local']);
            $values['storage_s3_signed_ttl'] = (int)($values['storage_s3_signed_ttl'] ?: self::DEFAULT_TTL);
        }
        // A refused save hands back what was typed, and a secret is not drawn back even then.
        unset($values[self::SECRET]);

        return CoreSettings::vars($pageId, 'storage_post', $values, array('name' => 'storage_form'));
    }

    /**
     * A URL with an http or https scheme, after osc_sanitize_url(); anything else, javascript:
     * and data: among them, is blank. The public URL is built into every image a visitor loads.
     *
     * @param mixed $value
     *
     * @return string
     */
    public static function httpUrlOrEmpty($value): string
    {
        $value = osc_sanitize_url(is_string($value) ? $value : '');

        return preg_match('#^https?://#i', $value) ? $value : '';
    }

    /**
     * The active backend: s3, or local for anything else.
     *
     * @param mixed $value
     *
     * @return string
     */
    public static function backend($value): string
    {
        return $value === 's3' ? 's3' : 'local';
    }

    /**
     * Which local copies to keep: none, or all for anything else.
     *
     * @param mixed $value
     *
     * @return string
     */
    public static function keepLocal($value): string
    {
        return $value === 'none' ? 'none' : 'all';
    }

    /**
     * A known provider preset id, or custom.
     *
     * @param mixed $value
     *
     * @return string
     */
    public static function provider($value): string
    {
        return is_string($value) && isset(ProviderPresets::PRESETS[$value]) ? $value : self::DEFAULT_PROVIDER;
    }

    /**
     * The region to store: as typed, or the preset's own when it is blank and the provider
     * locks it, so request signing still has one.
     *
     * @param string $region
     * @param string $provider a provider() answer
     *
     * @return string
     */
    public static function region(string $region, string $provider): string
    {
        $preset = ProviderPresets::PRESETS[$provider] ?? array();
        if ($region === '' && !empty($preset['region_locked'])) {
            return (string)$preset['region'];
        }

        return $region;
    }

    /**
     * A signed-URL lifetime: above zero is held to 60-604800 seconds, anything else is 900.
     *
     * @param mixed $value
     *
     * @return int
     */
    public static function ttl($value): int
    {
        $ttl = is_scalar($value) ? (int)$value : 0;

        return $ttl > 0 ? max(self::MIN_TTL, min(self::MAX_TTL, $ttl)) : self::DEFAULT_TTL;
    }
}
