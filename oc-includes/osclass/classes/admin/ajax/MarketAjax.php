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

namespace mindstellar\admin\ajax;

use DOMDocument;
use DOMElement;
use DOMXPath;
use HTMLPurifier;
use HTMLPurifier_Config;
use mindstellar\market\Catalog;
use mindstellar\market\Compatibility;
use mindstellar\market\Installer;
use mindstellar\market\PackageIndex;
use mindstellar\utility\AjaxResponse;
use mindstellar\utility\FileSystem;
use Params;
use Plugins;
use WebThemes;

/**
 * The package market: catalog refresh, install, update and the detail dialog. docs/MARKET.md §8.2.
 */
final class MarketAjax extends AjaxHandler
{
    public function refresh(): void
    {
        AjaxResponse::json(self::marketRefresh(Params::getParamString('type')));
    }

    public function install(): void
    {
        AjaxResponse::json(self::marketInstallOrUpdate(
            'install',
            Params::getParamString('type'),
            Params::getParamString('slug'),
            Params::getParamString('version')
        ));
    }

    public function update(): void
    {
        AjaxResponse::json(self::marketInstallOrUpdate(
            'update',
            Params::getParamString('type'),
            Params::getParamString('slug'),
            Params::getParamString('version')
        ));
    }

    public function detail(): void
    {
        AjaxResponse::json(self::marketDetail(
            Params::getParamString('type'),
            Params::getParamString('slug')
        ));
    }

    /**
     * Validates the posted `type` against the market's two package kinds, before it
     * reaches a filesystem path or a Catalog/Installer factory call.
     *
     * @param string $type
     *
     * @return string|null 'plugin', 'theme', or null when neither
     */
    private static function marketType($type)
    {
        return in_array($type, array('plugin', 'theme'), true) ? $type : null;
    }

    /**
     * The cached catalog for the requested package kind.
     *
     * @param string $type 'theme', otherwise plugins
     *
     * @return Catalog
     */
    private static function marketCatalog($type)
    {
        return $type === 'theme' ? Catalog::forThemes() : Catalog::forPlugins();
    }

    /**
     * The installed/available package index for the requested package kind.
     *
     * @param string $type 'theme', otherwise plugins
     *
     * @return PackageIndex
     */
    private static function marketPackageIndex($type)
    {
        return $type === 'theme' ? PackageIndex::forThemes() : PackageIndex::forPlugins();
    }

    /**
     * The installer for the requested package kind.
     *
     * @param string $type 'theme', otherwise plugins
     *
     * @return Installer
     */
    private static function marketInstaller($type)
    {
        return $type === 'theme' ? Installer::forThemes() : Installer::forPlugins();
    }

    /**
     * A market_refresh answer that refreshed nothing.
     *
     * @return array{ok:false, message:string, checked_at:int, error:null, counts:array{available:int, updates:int}}
     */
    private static function refreshRefusal(string $message): array
    {
        return array(
            'ok' => false, 'message' => $message,
            'checked_at' => 0, 'error' => null, 'counts' => array('available' => 0, 'updates' => 0),
        );
    }

    /**
     * A market_install / market_update answer that installed nothing.
     *
     * @return array{ok:false, message:string, slug:string, version:null, rolled_back:false}
     */
    private static function installRefusal(string $message, string $slug): array
    {
        return array(
            'ok' => false, 'message' => $message,
            'slug' => $slug, 'version' => null, 'rolled_back' => false,
        );
    }

    /**
     * Fetch the catalog when its last check is over a day old. The daily update check in the
     * admin footer calls this; without it the catalog only changed on "Check now".
     *
     * @param string $type 'plugin' or 'theme'
     *
     * @return void
     */
    public static function refreshCatalogIfDue($type)
    {
        if (osc_market_changes_blocked()) {
            return;
        }
        $catalog = self::marketCatalog($type);
        if (time() - $catalog->lastChecked() > 24 * 3600) {
            $catalog->updates(true);
            $catalog->index(true);
        }
    }

    /**
     * action=market_refresh -- forces a live catalog check (conditional GET; a 304 on
     * the common path) for the requested package kind and reports what the Browse /
     * Updates screens will now show. docs/MARKET.md §8.2.
     *
     * @param string $type 'plugin' or 'theme'
     *
     * @return array{ok:bool, message:string, checked_at:int, error:?string,
     *               counts:array{available:int, updates:int}}
     */
    private static function marketRefresh($type)
    {
        $type = self::marketType($type);
        if ($type === null) {
            return self::refreshRefusal(__('Invalid package type.'));
        }

        if (defined('DEMO')) {
            return self::refreshRefusal(__("This action can't be done because it's a demo site"));
        }
        if (osc_package_installs_disabled()) {
            return self::refreshRefusal(__('Catalog refresh is disabled on this deployment.'));
        }

        $catalog = self::marketCatalog($type);
        $catalog->index(true);
        $catalog->updates(true);

        $packageIndex = self::marketPackageIndex($type);
        $counts       = array(
            'available' => count($packageIndex->available()),
            'updates'   => count($packageIndex->pendingUpdates()),
        );

        $error = $catalog->lastError();

        return array(
            'ok'         => $error === null,
            'message'    => $error === null ? __('Catalog refreshed.') : $error,
            'checked_at' => $catalog->lastChecked(),
            'error'      => $error,
            'counts'     => $counts,
        );
    }

    /**
     * action=market_install / action=market_update -- resolves the version to install
     * server-side (Compatibility::pickBestVersion() for a fresh install,
     * PackageIndex::pendingUpdates() for an update) and refuses when the client-supplied
     * version no longer matches, instead of trusting a version string handed in by the
     * browser. docs/MARKET.md §8.2, §9.
     *
     * @param string $mode    'install' or 'update'
     * @param string $type    'plugin' or 'theme'
     * @param string $slug
     * @param string $version version the client last saw; re-validated, never trusted
     *
     * @return array{ok:bool, message:string, slug:string, version:?string, rolled_back:bool}
     */
    private static function marketInstallOrUpdate($mode, $type, $slug, $version)
    {
        $slug = trim((string) $slug);

        $type = self::marketType($type);
        if ($type === null) {
            return self::installRefusal(__('Invalid package type.'), $slug);
        }

        if (!preg_match(Installer::SLUG_PATTERN, $slug)) {
            return self::installRefusal(__('Invalid package slug.'), $slug);
        }

        $version = trim((string) $version);
        if ($version === '') {
            return self::installRefusal(__('No version was specified.'), $slug);
        }

        if (defined('DEMO')) {
            return self::installRefusal(__("This action can't be done because it's a demo site"), $slug);
        }
        if (osc_package_installs_disabled()) {
            return self::installRefusal(__('Package installs are disabled on this deployment.'), $slug);
        }

        $packageIndex  = self::marketPackageIndex($type);
        $installedRows = $packageIndex->installed();
        $isInstalled   = isset($installedRows[$slug]);

        if ($mode === 'install') {
            if ($isInstalled) {
                return self::installRefusal(__('This package is already installed; use Update instead.'), $slug);
            }

            $catalog  = self::marketCatalog($type);
            $versions = $catalog->updates()[$slug] ?? array();
            $best     = Compatibility::pickBestVersion($versions);
        } else {
            if (!$isInstalled) {
                return self::installRefusal(__('This package is not installed; use Install instead.'), $slug);
            }

            $pending = $packageIndex->pendingUpdates();
            $best    = $pending[$slug] ?? null;
        }

        if ($best === null) {
            return self::installRefusal(__('No compatible version is available for this package.'), $slug);
        }

        // Re-resolve, never trust: the version the client saw may be stale by the time
        // this request lands (a catalog refresh, or another admin, in between).
        if ((string) $best['version'] !== $version) {
            return self::installRefusal(__('The available version has changed since you loaded this page; refresh and try again.'), $slug);
        }

        $installer = self::marketInstaller($type);

        return $mode === 'install' ? $installer->install($slug, $best) : $installer->update($slug, $best);
    }

    /**
     * action=market_detail -- the payload for the Browse/Updates detail dialog
     * (docs/MARKET.md §8.2): screenshots, rendered README, per-version compatibility and
     * support links, sourced from `Catalog::detail()`'s own cache (a cheap conditional GET
     * on the common path, never a forced fetch). Read-only, so unlike install/update this
     * carries no DEMO or self-update-disabled refusal.
     *
     * @param string $type 'plugin' or 'theme'
     * @param string $slug
     *
     * @return array{ok:bool, message:string, detail:?array}
     */
    private static function marketDetail($type, $slug)
    {
        $type = self::marketType($type);
        if ($type === null) {
            return array('ok' => false, 'message' => __('Invalid package type.'), 'detail' => null);
        }

        $slug = trim((string) $slug);
        if (!\mindstellar\utility\Validate::packageName($slug)) {
            return array('ok' => false, 'message' => __('Invalid package slug.'), 'detail' => null);
        }

        // A folder name the catalog could never hold, such as one with an underscore, is local.
        $inCatalog = preg_match(Installer::SLUG_PATTERN, $slug) === 1;
        $raw       = $inCatalog ? self::marketCatalog($type)->detail($slug) : null;
        if ($raw === null) {
            // Not in the catalog, as with a private or hand-installed package: what is on disk.
            $local = self::marketLocalDetail($type, $slug);
            if ($local !== null) {
                return array('ok' => true, 'message' => '', 'detail' => $local);
            }

            return array(
                'ok' => false, 'message' => __('No details are available for this package yet.'),
                'detail' => null,
            );
        }

        $detail = self::marketBuildDetail($slug, $raw);
        // An installed copy that is up to date shows its own README, read with this site's renderer.
        $newest = (string) ($detail['versions'][0]['version'] ?? '');
        $local  = self::marketLocalDetail($type, $slug);
        if ($local !== null && $local['description_html'] !== '' && $newest !== ''
            && version_compare((string) $local['version'], $newest, '>=')
        ) {
            $detail['description_html'] = $local['description_html'];
        }

        return array('ok' => true, 'message' => '', 'detail' => $detail);
    }

    /**
     * The detail of an installed package, read from its own folder: its header, its README,
     * the screenshots its shopclass.json lists, and its support links. Null when the slug is
     * not installed.
     *
     * @param string $type 'plugin' or 'theme'
     * @param string $slug already checked against the slug pattern
     *
     * @return array<string,mixed>|null
     */
    private static function marketLocalDetail($type, $slug)
    {
        $root = ($type === 'theme' ? osc_themes_path() : osc_plugins_path()) . $slug . '/';
        if (!is_file($root . 'index.php')) {
            return null;
        }
        if ($type === 'theme') {
            $info   = (array) WebThemes::getInstance()->loadThemeInfo($slug);
            $name   = (string) ($info['name'] ?? $slug);
            $author = (string) ($info['author_name'] ?? '');
        } else {
            $info   = (array) Plugins::getInfo($slug . '/index.php');
            $name   = (string) ($info['plugin_name'] ?? $slug);
            $author = (string) ($info['author'] ?? '');
        }
        $version = (string) ($info['version'] ?? '');
        $manifest = is_file($root . 'shopclass.json') ? json_decode((string) file_get_contents($root . 'shopclass.json'), true) : null;
        $manifest = is_array($manifest) ? $manifest : array();
        $readme   = is_file($root . 'README.md') ? (string) file_get_contents($root . 'README.md') : '';

        // Only files that exist in the package's own assets folder; their URL is this site's.
        $base  = osc_base_url() . 'oc-content/' . ($type === 'theme' ? 'themes/' : 'plugins/') . $slug . '/';
        $shots = array();
        foreach ((array) ($manifest['screenshots'] ?? array()) as $shot) {
            $src = is_array($shot) && is_string($shot['src'] ?? null) ? $shot['src'] : '';
            if (preg_match('#^assets/screenshot-[A-Za-z0-9._-]+\.(png|jpe?g)$#', $src) === 1 && is_file($root . $src)) {
                $shots[] = array('src' => $base . $src, 'caption' => is_string($shot['caption'] ?? null) ? $shot['caption'] : '');
            }
        }

        return array(
            'slug'             => $slug,
            'name'             => $name,
            'author'           => $author,
            'version'          => $version,
            'description_html' => self::marketPurifyDescription(\mindstellar\market\Markdown::toHtml($readme), $base),
            'screenshots'      => $shots,
            'versions'         => array(),
            'links'            => self::marketSanitizeLinks(array('links' => (array) ($manifest['support'] ?? array()))),
            'categories'       => array_values(array_filter((array) ($manifest['categories'] ?? array()), 'is_string')),
            'tags'             => array_values(array_filter((array) ($manifest['tags'] ?? array()), 'is_string')),
            'downloads'        => 0,
        );
    }

    /**
     * Shapes `Catalog::detail()`'s cached payload into what the dialog renders. The catalog
     * type-checks its fields on the way in but does not sanitise `description_html` (it is a
     * third party's README) and does not re-check `screenshots[].src` / support links against
     * the host allowlist on every read -- both happen here, on the response path, rather than
     * trusting whatever is already sitting in the cache.
     *
     * @param string              $slug
     * @param array<string,mixed> $raw  Catalog::detail()'s sanitised (but not description-purified) array
     *
     * @return array<string,mixed>
     */
    private static function marketBuildDetail($slug, array $raw)
    {
        return array(
            'slug'             => $slug,
            'name'             => is_string($raw['name'] ?? null) ? $raw['name'] : $slug,
            'author'           => is_string($raw['author'] ?? null) ? $raw['author'] : '',
            'description_html' => self::marketPurifyDescription($raw['description_html'] ?? ''),
            'screenshots'      => self::marketSanitizeScreenshots($raw),
            'versions'         => self::marketSanitizeVersions($raw),
            'links'            => self::marketSanitizeLinks($raw),
            'categories'       => isset($raw['categories']) && is_array($raw['categories']) ? array_values($raw['categories']) : array(),
            'tags'             => isset($raw['tags']) && is_array($raw['tags']) ? array_values($raw['tags']) : array(),
            'downloads'        => is_int($raw['downloads'] ?? null) ? $raw['downloads'] : 0,
        );
    }

    /**
     * Every screenshot re-checked against the package host allowlist here, on the way out to
     * the browser -- not just trusted from `Catalog::sanitizeDetail()`'s own pass on the way
     * in, so a host that was allowed when this slug was cached and is not allowed today (or a
     * cache entry that predates a stricter policy) still can't reach the dialog's DOM.
     *
     * @param array<string,mixed> $raw
     *
     * @return array<int, array{src:string, caption:string}>
     */
    private static function marketSanitizeScreenshots(array $raw)
    {
        $shots = array();
        foreach ((array) ($raw['screenshots'] ?? array()) as $shot) {
            if (!is_array($shot) || !is_string($shot['src'] ?? null) || $shot['src'] === '') {
                continue;
            }
            if (!FileSystem::isAllowedPackageHost($shot['src'])) {
                continue;
            }
            $shots[] = array(
                'src'     => $shot['src'],
                'caption' => is_string($shot['caption'] ?? null) ? $shot['caption'] : '',
            );
        }

        return $shots;
    }

    /**
     * The version table: one row per catalog release, each with its own
     * `Compatibility::evaluate()` verdict against THIS row's `requires`/`requires_php`/
     * `tested` -- evaluating once against the latest version and reusing it across every row
     * would show every version as equally (in)compatible, which defeats the point of the
     * table.
     *
     * `released_at` is always null: `Catalog::sanitizeVersionEntry()` (shared by
     * `updates.json` and this detail payload) does not carry the catalog's `published_at`
     * field through, so there is nothing to surface here without a `Catalog.php` change.
     *
     * @param array<string,mixed> $raw
     *
     * @return array<int,array<string,mixed>>
     */
    private static function marketSanitizeVersions(array $raw)
    {
        $versions = array();
        foreach ((array) ($raw['versions'] ?? array()) as $entry) {
            if (!is_array($entry) || !isset($entry['version']) || $entry['version'] === '') {
                continue;
            }

            $compatInfo = array(
                'requires'     => is_string($entry['requires'] ?? null) ? $entry['requires'] : '',
                'requires_php' => is_string($entry['requires_php'] ?? null) ? $entry['requires_php'] : '',
                'tested_up_to' => is_string($entry['tested'] ?? null) ? $entry['tested'] : '',
            );

            $verdict = Compatibility::evaluate($compatInfo);

            $versions[] = array(
                'version'      => (string) $entry['version'],
                'requires'     => $compatInfo['requires'],
                'requires_php' => $compatInfo['requires_php'],
                'tested'       => $compatInfo['tested_up_to'],
                'size'         => (int) ($entry['size'] ?? 0),
                'downloads'    => is_int($entry['downloads'] ?? null) ? $entry['downloads'] : 0,
                'released_at'  => null,
                'compat'       => array(
                    'status'  => $verdict['status'],
                    'blocked' => $verdict['blocked'],
                    'reason'  => $verdict['reason'],
                    'badge'   => Compatibility::badgeLabel($compatInfo),
                ),
            );
        }

        return $versions;
    }

    /**
     * `homepage` / `repo` / `issues` / `docs`, each re-checked against the package host
     * allowlist (the catalog only URL-shape-checks support links, unlike screenshots, so this
     * is the first allowlist pass they get). `Catalog::detail()` does not carry the raw
     * payload's top-level `homepage` field through sanitisation -- only its `support` object
     * survives -- so when neither `homepage` nor `repo` is present in that object, a repo link
     * is derived from the issue tracker URL instead: a GitHub issue tracker always lives at
     * "<repo>/issues".
     *
     * @param array<string,mixed> $raw
     *
     * @return array{homepage:?string, repo:?string, issues:?string, docs:?string}
     */
    private static function marketSanitizeLinks(array $raw)
    {
        $support = isset($raw['links']) && is_array($raw['links']) ? $raw['links'] : array();

        $pick = static function ($key) use ($support) {
            $value = $support[$key] ?? null;

            return (is_string($value) && $value !== '' && FileSystem::isAllowedPackageHost($value)) ? $value : null;
        };

        $issues   = $pick('issues');
        $docs     = $pick('docs');
        $homepage = $pick('homepage');
        $repo     = $pick('repo');

        if ($homepage === null && $repo === null && $issues !== null
            && preg_match('#^(https://github\.com/[^/]+/[^/]+)/issues/?$#i', $issues, $matches)
        ) {
            $homepage = $matches[1];
            $repo     = $matches[1];
        }

        return array('homepage' => $homepage, 'repo' => $repo, 'issues' => $issues, 'docs' => $docs);
    }

    /** @var \HTMLPurifier|null */
    private static $marketPurifier;

    /**
     * `description_html` is a third party's rendered README. The catalog builder sanitises it
     * before publishing, but that is an upstream promise, not a guarantee this codebase can
     * rely on for markup it is about to inject into its own admin DOM -- so it is purified
     * again here, independently, right before it goes into the JSON response.
     *
     * A tight allowlist only: headings, paragraphs, line breaks, lists, emphasis, inline code,
     * pre blocks, links and images. No scripts, no event handlers, no iframes, no style
     * attributes -- none of those are in the allowed list, so HTMLPurifier drops them
     * regardless of what the source markup contained. `URI.AllowedSchemes` is narrowed to
     * http/https only, so a `javascript:` or `data:` URL in an href or src cannot survive.
     * `target="_blank"` and `rel="nofollow noopener"` are forced onto every link.
     *
     * A valid http(s) URL is still not necessarily one this admin should silently *fetch* from
     * a third party's README: an `<img src>` fires on render, with no click and no consent, so
     * every `<img>` that survives purification is re-checked against the same package host
     * allowlist that governs `screenshots[].src` and the support links (marketDropUnallowedHostUrls()).
     *
     * @param mixed       $html
     * @param string|null $localBase an installed package's own URL: its README's relative
     *                               images and links resolve inside it
     *
     * @return string
     */
    private static function marketPurifyDescription($html, $localBase = null)
    {
        if (!is_string($html) || $html === '') {
            return '';
        }

        if (self::$marketPurifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set(
                'HTML.Allowed',
                'h1,h2,h3,h4,h5,h6,p,br,hr,blockquote,ul,ol[start],li,strong,b,em,i,del,code,pre,a[href|title],'
                . 'img[src|alt|loading|title],table,thead,tbody,tr,th[class],td[class]'
            );
            $config->set('HTML.TargetBlank', true);
            $config->set('HTML.TargetNoopener', true);
            $config->set('HTML.Nofollow', true);
            $config->set('URI.AllowedSchemes', array('http' => true, 'https' => true));
            // Only the two alignment classes renderMarkdownSafe() (tools/ci/build-catalog.php)
            // ever emits are let through -- everything else on a class attribute is stripped.
            $config->set('Attr.AllowedClasses', array('text-center' => true, 'text-end' => true));
            \mindstellar\security\PurifierCache::apply($config);
            self::$marketPurifier = new HTMLPurifier($config);
        }

        return self::marketDropUnallowedHostUrls(self::$marketPurifier->purify($html), $localBase);
    }

    /**
     * Second pass over already-purified README markup: drops any `<img>` whose `src` is not on
     * the package host allowlist. Unlike an `<a href>` -- a click-through the visitor chooses to
     * make, already carrying `nofollow noopener` and restricted to http(s) -- an `<img>` fires
     * on render with no click and no consent, so it gets the same allowlist `screenshots[].src`
     * and the support links get, rather than being trusted just because HTMLPurifier's tag/
     * attribute/scheme rules let it through. A malformed fragment DOMDocument can't parse is
     * returned as an empty string rather than passed through.
     *
     * @param string      $html      already-purified, well-formed (small allowlist) HTML fragment
     * @param string|null $localBase an installed package's own URL, for its relative paths
     *
     * @return string
     */
    private static function marketDropUnallowedHostUrls($html, $localBase = null)
    {
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8"?><div>' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();

        // loadHTML() wraps a bare fragment in its own <html><body> -- the wrapper <div> above
        // is what scopes this back down to just the fragment's own nodes.
        $root = $doc->getElementsByTagName('div')->item(0);
        if (!$loaded || $root === null) {
            return '';
        }

        $xpath = new DOMXPath($doc);

        // A relative path in an installed package's own README names a file in its folder.
        $local = static fn ($url) => $localBase === null ? null : \mindstellar\market\Markdown::resolveInPackage($url, $localBase);
        foreach (iterator_to_array($xpath->query('.//a[@href]', $root)) as $a) {
            /** @var DOMElement $a */
            $resolved = $local($a->getAttribute('href'));
            if ($resolved !== null) {
                $a->setAttribute('href', $resolved);
            }
        }
        foreach (iterator_to_array($xpath->query('.//img[@src]', $root)) as $img) {
            /** @var DOMElement $img */
            $resolved = $local($img->getAttribute('src'));
            if ($resolved !== null) {
                $img->setAttribute('src', $resolved);
            } elseif (!FileSystem::isAllowedPackageHost($img->getAttribute('src'))) {
                $img->parentNode->removeChild($img);
            }
        }

        $result = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $result .= $doc->saveHTML($child);
        }

        return $result;
    }
}
