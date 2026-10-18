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

namespace mindstellar\widgets;

use mindstellar\admin\form\store\StoreFactory;
use mindstellar\settings\SettingsImage;
use mindstellar\settings\SettingsPageRegistry;

/**
 * The customiser's draft, publish and discard rules over a theme's osc_customiser_spec().
 *
 * Section configs go through WidgetConfig, the widgets screen's sanitiser; look values go
 * through the settings API's sanitise and validate for their declared field. Nothing is
 * stored unless the whole change passes.
 */
final class Customiser
{
    /** @var array<string,mixed> */
    private array $spec;

    private CustomiserDraft $draft;

    /**
     * @param array<string,mixed> $spec what osc_customiser_spec() returned
     */
    public function __construct(array $spec, CustomiserDraft $draft)
    {
        $this->spec  = $spec;
        $this->draft = $draft;
    }

    /**
     * The customiser for the signed-in admin and the active theme, or null when the theme
     * declares none.
     */
    public static function forCurrentAdmin(): ?self
    {
        $spec = osc_customiser_spec();

        return $spec === null ? null : new self($spec, new CustomiserDraft(osc_theme(), osc_logged_admin_id()));
    }

    public function draft(): CustomiserDraft
    {
        return $this->draft;
    }

    /**
     * Merge a posted change into the draft and store it. Posted locations replace the drafted
     * rows of that location; posted look values replace those values; the rest is kept.
     *
     * @param array<mixed> $locations    slug => rows, as Params purified them
     * @param array<mixed> $locationsRaw the same, unpurified
     * @param array<mixed> $settings     look field name => raw posted value
     *
     * @return array{status:int,errors:string[],saved_at:int}
     */
    public function saveDraft(array $locations, array $locationsRaw, array $settings): array
    {
        $current = $this->draft->read() ?? array('locations' => array(), 'settings' => array(), 'base' => array());
        $errors  = array();

        $drafted = $current['locations'];
        $base    = $current['base'];
        foreach ($locations as $slug => $rows) {
            $slug = (string) $slug;
            if (!isset($this->spec['locations'][$slug])) {
                $errors[] = sprintf(__('%s is not a place this theme lets you customise.'), $slug);
                continue;
            }
            $raw = is_array($locationsRaw[$slug] ?? null) ? $locationsRaw[$slug] : array();
            $out = $this->rows($slug, is_array($rows) ? $rows : array(), $raw, $errors);
            if ($out !== null) {
                if (!isset($drafted[$slug])) {
                    $base[$slug] = $this->liveHash($slug);
                }
                $drafted[$slug] = $out;
            }
        }

        $look = $this->lookValues($settings, $current['settings'], $errors);

        if ($errors !== array()) {
            return array('status' => 422, 'errors' => $errors, 'saved_at' => 0);
        }

        $next = array('locations' => $drafted, 'settings' => $look['values'], 'base' => $base);
        if (CustomiserDraft::bytes($next + array('theme' => $this->draft->theme(), 'updated_at' => time())) > CustomiserDraft::MAX_BYTES) {
            return array('status' => 422, 'errors' => array(__('The draft is too large to save.')), 'saved_at' => 0);
        }

        // Uploaded only now that nothing else can refuse the change.
        $page  = $this->settingsPage();
        $fresh = array();
        foreach ($look['uploads'] as $name => $tmp) {
            $newId = $page === null ? null : SettingsImage::upload($page, $tmp);
            if ($newId === null) {
                foreach ($fresh as $freshId) {
                    SettingsImage::delete((array) $page, $freshId);
                }

                return array('status' => 422, 'errors' => array(sprintf(__('%s is not a valid image'), $this->lookFields()[$name]['label'] ?? $name)), 'saved_at' => 0);
            }
            $fresh[]                 = $newId;
            $next['settings'][$name] = $newId;
        }

        try {
            $savedAt = $this->draft->save($next);
        } catch (\LengthException $e) {
            $this->dropDraftImages($next['settings'], $current['settings']);

            return array('status' => 422, 'errors' => array(__('The draft is too large to save.')), 'saved_at' => 0);
        } catch (\Throwable $e) {
            $this->dropDraftImages($next['settings'], $current['settings']);

            return array('status' => 500, 'errors' => array(__('The draft could not be saved.')), 'saved_at' => 0);
        }

        // A drafted image replaced by another drafted image is no longer used anywhere.
        $this->dropDraftImages($current['settings'], $next['settings']);

        return array('status' => 200, 'errors' => array(), 'saved_at' => $savedAt);
    }

    /**
     * Make the draft live: each drafted location is replaced, each drafted look value is
     * stored, and the draft is forgotten. When a drafted location changed since the draft took
     * it (the Widgets screen, another admin), nothing is written unless $force is true.
     *
     * @return array{status:int,errors:string[],stale?:bool}
     */
    public function publish(bool $force = false): array
    {
        $draft = $this->draft->read();
        if ($draft === null) {
            return array('status' => 409, 'errors' => array(__('There are no changes to publish.')));
        }
        if (!$force && $this->staleLocations($draft) !== array()) {
            return array(
                'status' => 409,
                'stale'  => true,
                'errors' => array(__('The sections were changed elsewhere, such as on the Widgets screen, after this draft began. Publishing replaces those changes.')),
            );
        }

        try {
            foreach ($draft['locations'] as $slug => $rows) {
                if (!isset($this->spec['locations'][$slug]) || !is_array($rows)) {
                    continue;
                }
                $types = $this->spec['locations'][$slug]['types'];
                $keep  = array();
                foreach ($rows as $row) {
                    if (is_array($row) && in_array($row['type'] ?? '', $types, true)) {
                        $keep[] = array(
                            'id'          => isset($row['id']) ? (int) $row['id'] : null,
                            'type'        => (string) $row['type'],
                            'config'      => is_array($row['config'] ?? null) ? $row['config'] : array(),
                            'description' => (string) ($row['description'] ?? ''),
                        );
                    }
                }
                WidgetStore::replaceLocation((string) $slug, $keep);
            }
        } catch (\Throwable $e) {
            return array('status' => 500, 'errors' => array(__('The sections could not be published.')));
        }

        $page = $this->settingsPage();
        if ($page !== null && $draft['settings'] !== array()) {
            $fields = array_intersect_key($this->lookFields(), $draft['settings']);
            $values = array_intersect_key($draft['settings'], $fields);
            $store  = StoreFactory::forPage($page);
            $before = array();
            foreach ($fields as $name => $field) {
                if ($field['type'] === 'image') {
                    $before[$name] = $store->value($name, $field, null);
                }
            }
            try {
                $store->save($fields, $values, array(), null);
            } catch (\Throwable $e) {
                return array('status' => 500, 'errors' => array(__('The look settings could not be published.')));
            }
            foreach ($before as $name => $oldId) {
                if ($oldId !== '' && (string) $oldId !== (string) $values[$name]) {
                    SettingsImage::delete($page, $oldId);
                }
            }
            osc_run_hook('settings_page_saved', (string) $this->spec['settings']['page'], osc_settings_hook_values($fields, $values)); // hooks-doc: skip
        }

        try {
            $this->draft->discard();
        } catch (\Throwable $e) {
            // Published already; a draft left behind is overwritten by the next change.
        }
        $theme = $this->draft->theme();
        osc_run_hook('customiser_published', $theme);

        return array('status' => 200, 'errors' => array());
    }

    /**
     * Drafted locations whose live rows differ from when the draft took them.
     *
     * @param array<string,mixed> $draft
     *
     * @return string[]
     */
    public function staleLocations(array $draft): array
    {
        $stale = array();
        foreach ((array) ($draft['base'] ?? array()) as $slug => $hash) {
            if (isset($draft['locations'][$slug]) && $this->liveHash((string) $slug) !== (string) $hash) {
                $stale[] = (string) $slug;
            }
        }

        return $stale;
    }

    private function liveHash(string $slug): string
    {
        try {
            return WidgetStore::locationHash($slug);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Forget the draft and the images only it used.
     */
    public function discard(): void
    {
        $draft = $this->draft->read();
        if ($draft !== null) {
            $this->dropDraftImages($draft['settings'], array());
        }
        $this->draft->discard();
    }

    /**
     * One location's posted rows, sanitised; null when any row is refused.
     *
     * @param array<mixed> $rows
     * @param array<mixed> $raw
     * @param string[]     $errors
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function rows(string $slug, array $rows, array $raw, array &$errors): ?array
    {
        $types    = $this->spec['locations'][$slug]['types'];
        $existing = null;
        $out      = array();
        $failed   = false;
        foreach (array_values($rows) as $i => $row) {
            $rawRow = array_values($raw)[$i] ?? array();
            if (!is_array($row)) {
                continue;
            }
            $typeId = is_string($row['type'] ?? null) ? $row['type'] : '';
            $type   = in_array($typeId, $types, true) ? WidgetRegistry::getInstance()->get($typeId) : null;
            if ($type === null) {
                $errors[] = sprintf(__('%s is not a section this page can hold.'), $typeId);
                $failed   = true;
                continue;
            }

            // Only an id already in this location is kept; any other row is a new one.
            $id = isset($row['id']) && is_scalar($row['id']) ? (int) $row['id'] : 0;
            if ($id > 0) {
                if ($existing === null) {
                    $existing = array_flip(WidgetStore::idsAt($slug));
                }
                if (!isset($existing[$id])) {
                    $id = 0;
                }
            }

            $config = WidgetConfig::fromPosted(
                $type,
                is_array($row['config'] ?? null) ? $row['config'] : array(),
                is_array($rawRow['config'] ?? null) ? $rawRow['config'] : array()
            );
            if (isset($row['enabled']) && in_array((string) $row['enabled'], array('0', 'false', ''), true)) {
                $config[WidgetConfig::ENABLED] = false;
            }

            $key         = is_string($row['key'] ?? null) && preg_match('/^[a-z0-9_-]{1,40}$/', $row['key']) ? $row['key'] : '';
            $description = is_string($row['description'] ?? null) ? trim(osc_sanitize_text($row['description'])) : '';

            $out[] = array(
                'id'          => $id > 0 ? $id : null,
                'key'         => $key,
                'type'        => $typeId,
                'description' => mb_substr($description !== '' ? $description : (string) $type['label'], 0, 40),
                'config'      => $config,
            );
        }

        return $failed ? null : $out;
    }

    /**
     * Posted look values sanitised and validated against their declared field, merged over
     * the drafted ones, plus the image files waiting to be uploaded.
     *
     * @param array<mixed>        $posted
     * @param array<string,mixed> $drafted
     * @param string[]            $errors
     *
     * @return array{values:array<string,mixed>,uploads:array<string,string>}
     */
    private function lookValues(array $posted, array $drafted, array &$errors): array
    {
        $fields  = $this->lookFields();
        $values  = array_intersect_key($drafted, $fields);
        $uploads = array();

        foreach ($posted as $name => $value) {
            $name = (string) $name;
            if (!isset($fields[$name])) {
                $errors[] = sprintf(__('%s is not a setting this theme lets you customise.'), $name);
                continue;
            }
            $field = $fields[$name];
            if ($field['type'] === 'image') {
                // Only an empty value is accepted: it removes the image. A file is posted
                // under the field's own name.
                if ($value === '') {
                    $values[$name] = '';
                }
                continue;
            }
            if (is_array($value)) {
                $errors[] = sprintf(__('%s is not one of the available options'), $field['label'] ?? $name);
                continue;
            }
            if ($field['type'] === 'checkbox') {
                $value = in_array((string) $value, array('', '0', 'false'), true) ? '' : '1';
            }
            $clean = \Params::withRequest(array($name => (string) $value), static fn () => osc_settings_sanitize($field));
            $error = osc_settings_validate($field, $clean);
            if ($error !== null) {
                $errors[] = $error;
                continue;
            }
            $values[$name] = $clean;
        }

        $page = $this->settingsPage();
        foreach ($fields as $name => $field) {
            if ($field['type'] !== 'image' || $page === null || !SettingsImage::allowed($page)) {
                continue;
            }
            $file = SettingsImage::posted($field);
            if ($file['error'] !== null) {
                $errors[] = $file['error'];
            } elseif ($file['file'] !== null) {
                $uploads[$name] = $file['file'];
            }
        }

        return array('values' => $values, 'uploads' => $uploads);
    }

    /**
     * Delete each image id in $from that is not in $keep and is not the published one.
     *
     * @param array<string,mixed> $from
     * @param array<string,mixed> $keep
     */
    private function dropDraftImages(array $from, array $keep): void
    {
        $page = $this->settingsPage();
        if ($page === null) {
            return;
        }
        $store = StoreFactory::forPage($page);
        foreach ($this->lookFields() as $name => $field) {
            if ($field['type'] !== 'image' || !isset($from[$name]) || $from[$name] === '') {
                continue;
            }
            $id = (string) $from[$name];
            if (isset($keep[$name]) && (string) $keep[$name] === $id) {
                continue;
            }
            if ((string) $store->value($name, $field, null) === $id) {
                continue;
            }
            SettingsImage::delete($page, $id);
        }
    }

    /**
     * The declared settings page, or null when the theme names none or it is not registered.
     *
     * @return array<string,mixed>|null
     */
    private function settingsPage(): ?array
    {
        $id = (string) ($this->spec['settings']['page'] ?? '');

        return $id === '' ? null : osc_settings_page($id);
    }

    /**
     * The look fields the customiser may change, keyed by name.
     *
     * @return array<string,array<string,mixed>>
     */
    private function lookFields(): array
    {
        $id = (string) ($this->spec['settings']['page'] ?? '');
        if ($id === '') {
            return array();
        }

        return array_intersect_key(
            SettingsPageRegistry::getInstance()->fields($id),
            array_flip($this->spec['settings']['fields'] ?? array())
        );
    }
}
