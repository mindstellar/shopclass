# Page Builder — unifying page templates with the widget system

Status: **Phases 1–3 shipped** (template registry + page-builder canvas + rich-text
block, with the block form inlined in the editor); Phase 4 designed, not built.
Scope: static pages (`t_pages`), the page editor, and the front-end page router.
This doc proposes folding page templates and the widget registry into one
composition model instead of maintaining two parallel systems.

---

## 1. Why

Today a static page is a single WYSIWYG blob rendered into a theme template. The
recently-added widget registry (typed, plugin-registered, orderable, config-driven
components) is a much richer composition primitive, but it only applies to
theme-defined locations (header, footer, sidebars) — never to page bodies. The
question this answers: can a page template be provided by themes **and** plugins,
and can page composition reuse the widget system rather than reinventing it.

Short answer: plugin-provided templates already half-exist, and the widget
registry is the right substrate to unify on.

---

## 2. Current state (grounded)

### 2.1 Page templates

- `WebThemes::getAvailableTemplates()` (`classes/themes/WebThemes.php:306`) scans the
  active theme directory for files matching `template-*` and returns the filenames.
- The page editor's "Page template" dropdown is those filenames, passed through
  `osc_apply_filter('page_templates', …)` (`CAdminPages.php`). The chosen value is
  stored as `s_meta.template` on the page.
- The front-end router `CWebPage.php:77` resolves, in precedence order:
  1. `page-{internal_name}.php` in the theme (convention override),
  2. `s_meta.template` as a file in the **theme** dir,
  3. `s_meta.template` as a file in the **plugins** dir (`osc_plugins_path()`),
  4. fallback `page.php`.

**Consequence:** step 3 means a plugin can already ship a page template today — add
it to the picker via the `page_templates` filter, point `s_meta.template` at a path
under the plugins dir, and the router `require`s it. This works but is a
file-path-in-meta convention: no registry, no id validation, no declared
capabilities, no link to widgets.

### 2.2 Widget system

- `WidgetRegistry` (`classes/widgets/WidgetRegistry.php`) — plugins/themes register
  a **type** via `osc_register_widget($id, $spec)`: `label`, `render` callable,
  optional declarative `fields` schema, optional custom `form`, and a `capability`
  (`admin` | `super_admin`). Ids are namespaced slugs `[a-z0-9_.-]{1,60}`.
- **Instances** live in `t_widget` rows: `s_location`, `i_order`, `s_type`,
  `s_config` (JSON), `s_content` (legacy). `Widget::findByLocation()` returns a
  location's rows ordered by `i_order` (drag-drop reorder via `Widget::reorder()`).
- Front end: a theme calls `osc_show_widgets($location)` (`hUtils.php:93`), which
  dispatches each row through `osc_render_widget()` (`hWidgets.php:54`). A typed row
  runs its registered callable with decoded config; an unknown type (plugin
  deactivated) renders nothing; a legacy row echoes `s_content`.
- **Locations are arbitrary strings.** The theme decides what to render where. There
  is no fixed enum — a new location costs nothing.

---

## 3. The convergence model: a page *is* a widget location

Stop treating the page body as one blob. Treat each page as a **widget canvas**.

- A page owns a location key, e.g. `page.{id}` (id is stable; slug is not — internal
  name can change). Its widgets are ordinary `t_widget` rows with
  `s_location = 'page.42'`.
- A thin template renders that location:
  ```php
  // template-widgets.php (theme) OR core fallback
  osc_show_widgets('page.' . osc_static_page_id());
  ```
- The page editor gains a **Page builder** mode: when the page's template is the
  builder, the main editor column swaps the single TinyMCE body for the existing
  widget-placement UI (picker + drag-drop), scoped to `page.{id}`.

Nothing new is invented on the data/render side. It reuses:

- the registry (types, `fields`, `capability` — incl. `super_admin` gating for
  `core.custom_code`),
- `findByLocation` + `i_order` + `reorder` (drag-drop),
- `osc_render_widget()` dispatch and the deactivated-plugin safety.

Classic WYSIWYG pages are untouched: they are simply the `default` template and
never get a page location. A `core.rich_text` widget type (wrapping `osc_autop()`)
becomes the default block so builder pages and classic pages ultimately share one
render path.

---

## 4. Proposed API — mirror the widget registry

Add a `PageTemplateRegistry` shaped exactly like `WidgetRegistry`, so plugin- and
theme-provided templates share one validated source instead of the filename scan:

```php
osc_register_page_template('sample.landing', [
    'label'       => 'Landing (widgetized)',   // shown in the editor picker
    'description' => 'Hero + widgetized body',  // optional
    'builder'     => true,                       // exposes a page widget canvas
    'locations'   => ['page.hero', 'page.body'], // widget areas, relative to the page
    'render'      => $callableOrFilePath,        // callable(array $page) or a file path
    'capability'  => 'admin',                    // reuse the widget capability vocabulary
]);
```

- The editor dropdown reads `osc_page_templates()` (registry) ∪ the legacy theme
  `template-*` scan (back-compat).
- `CWebPage` resolves a **registered id** first; the existing file-path branches stay
  as a fallback so nothing that works today breaks.
- `builder: true` templates get the page-builder editor; others render as they do now.
- Multiple `locations` let one template expose several widget areas
  (e.g. a hero strip + a body column), each keyed `page.{id}.{area}`.

---

## 5. Data model

**No schema change required for the MVP.** Page widgets are `t_widget` rows with a
`page.{id}` location string. What's needed:

- **Cascade delete:** when a page is deleted, delete `t_widget WHERE s_location =
  'page.{id}'` (and any `page.{id}.*` areas). Add to `CAdminPages` delete path.
- **Location scoping in the admin appearance screen:** page locations should not
  clutter the global widget-locations UI; the page editor manages its own location.

A later optimization (not MVP) could add an explicit `fk_i_pages_id` column to
`t_widget` for referential integrity instead of a string convention, behind a
migration. Start with the string; it costs nothing and proves the model.

---

## 6. Editor UX

- The settings-rail template select gains builder entries from the registry. Picking
  a `builder` template swaps the main column:
  - **Classic** (`default`): the TinyMCE title + body as it is now.
  - **Builder**: title stays; the body area becomes the widget canvas — an "Add
    block" picker (registry types filtered by the current user's capability) and a
    drag-drop ordered list, reusing the appearance screen's components scoped to
    `page.{id}`.
- In block mode the classic WYSIWYG body editor is **hidden** (title-only render via
  `printMultiLangTitleDesc(..., $with_description = false)`); the stored body is kept
  in a hidden field so it round-trips and returns if the page is switched back.
- Switching classic → builder should offer to seed the canvas with a single
  `core.rich_text` widget carrying the existing body, so no content is stranded.
- Multi-language: page widgets are language-agnostic by default (like theme
  widgets); per-locale page content stays in the `core.rich_text` widget's config if
  a type opts into localization. Flag as an open question (§9).

---

## 7. Rendering & the theme-repo constraint

Public themes ship from **their own repositories** (bender ← `mindstellar/theme-bender`,
downloaded at build; `oc-content/themes/*` is gitignored here). So the builder must
**not depend on a theme file existing**:

- Core carries a **fallback builder renderer**. If the active theme has no
  `template-widgets.php`, `CWebPage` renders the page's widget canvas itself (page
  chrome from `page.php` + `osc_show_widgets('page.'.id)` for the body).
- A theme's own `template-widgets.php` is then an *optional enhancement* (custom
  wrapper markup, extra areas), never a requirement.

This keeps the entire feature shippable from **core alone**. It matters more than a
generic "themes upgrade at their own pace": **bender is compatibility-only and will
not gain these features** — the core fallback is precisely what lets builder pages
work on it unchanged. The latest features are the remit of a **new default theme**
(planned) that will provide `template-widgets.php` and use the builder natively.

---

## 8. Phasing (each slice ships independently)

1. **PageTemplateRegistry** — ✅ **done**. `mindstellar\pages\PageTemplateRegistry` +
   `osc_register_page_template()` / `osc_page_templates()` / `osc_page_template()`
   (`hPageTemplates.php`); editor picker reads it (super_admin hidden from
   moderators); `CWebPage::renderRegisteredTemplate()` resolves registered ids
   (callable or theme/plugin file path) with all legacy branches retained; demo
   `sample_widgets.plain` template in the sample-widgets plugin. No schema change.
2. **Page-builder MVP** — ✅ **done**. Core `core.page_builder` template
   (`hPageTemplates.php`) with a theme-`template-widgets.php`-or-core-fallback
   renderer; helpers `osc_page_builder_location()` / `osc_show_page_widgets()`. The
   page editor renders a blocks canvas (list + drag/keyboard reorder) for a saved
   builder page; add/edit/delete reuse the appearance widget form/CRUD, threaded
   with a server-rebuilt `page_builder_id` return param (no config-builder
   duplication, capability gate inherited). Page delete cascades to `page.{id}`
   blocks. Verified end-to-end (render, canvas, add round-trip, cascade delete).
   **Update:** the block form is now **inlined** — a native dialog in the page
   editor holds a typed-only block form; Edit repurposes it from the row's data.
   Posts still go to `add_widget_post`/`edit_widget_post` with `page_builder_id`
   (shared config builder + capability gate unchanged), so the appearance
   round-trip is gone. Typed-only closes a moderator raw-HTML hole; the inline Edit
   button only shows for types the user may author. `widgetConfigSelectOptions`
   moved to admin `functions.php` (`appearance/page-block-form.php` is the partial).
3. **`core.rich_text` widget** — ✅ **done**. A textarea field, HTML-purified on
   save, rendered through `osc_autop`; registered before `core.custom_code` so it
   is the picker's default. Gives the builder a safe default block with no plugin,
   and is the shared formatting path between classic and builder pages. (The
   classic→builder seed-from-body migration remains a nice-to-have, not built.)
4. **New default theme + docs** — bender is **compatibility-only** and deliberately
   gets *no* new features; the core fallback renderer already makes builder pages
   work there, which is all bender needs. The latest features (page builder,
   `template-widgets.php`, the resource pipeline, widgets, `osc_autop`) are the job
   of a **new default theme** (planned) built for them. Still worthwhile: author
   docs for third-party theme/plugin authors. This phase is therefore not "upgrade
   bender" but "the new theme uses these natively."

---

## 9. Open questions

- **Per-locale page widgets.** Theme widgets are single-locale in practice. Do page
  builder blocks need per-language config, or is a localized `core.rich_text` field
  enough? (Leaning: localize at the widget-type level, not the framework.)
- **Location key: `page.{id}` vs `page.{slug}`.** Id is stable across renames; slug is
  human-readable but mutable. (Leaning: `{id}` for storage, slug only for theme
  convenience helpers.)
- **Capability for page building.** Moderators can edit pages today; should they be
  able to place `super_admin` widget types on a page? The registry already gates this
  per type — page building should defer to the same check, no new gate.
- **Interaction with `page-{internal_name}.php`.** The theme convention override
  (precedence #1) wins over any template choice today. Keep that precedence, or let a
  builder page opt out? (Leaning: keep; it's an intentional theme-author escape hatch.)

---

## 10. Bottom line

- Plugin- and theme-provided page templates already work via `CWebPage`'s
  plugin-path resolution + the `page_templates` filter — but as a thin file-path
  convention.
- The widget registry is the stronger substrate. Modeling **a page as a widget
  location** reuses the whole widget stack (types, ordering, config, capability,
  render dispatch) instead of building a parallel page-composition system.
- A `PageTemplateRegistry` mirroring `WidgetRegistry` unifies theme and plugin
  templates under one validated API.
- A core fallback renderer keeps the feature shippable without touching the
  externally-maintained themes.
