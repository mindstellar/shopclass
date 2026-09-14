# Custom Fields — grouping, inheritance, and conditional logic

Status: **Implemented** (2026-07-22). All phases below are built, committed on
`develop`, and verified against the dev DB / in-browser. What shipped vs. this
plan:
- **Phase 1 (inheritance)** — `Field::categoryPath()` + UNION resolution in
  `findByCategory` / `findByCategoryItem` / `findIDSearchableByCategories`.
- **Type registry** — `mindstellar\fields\FieldTypeRegistry` + `hFields.php`
  (`osc_register_field_type`, `osc_field_types`, `osc_field_resolve_type`, …);
  nine built-ins keep their e_type spelling, EMAIL/PHONE added text-backed.
  e_type stays the storage primitive; a non-primitive type persists in
  `s_meta['type']`. Per-field config (placeholder/help/default/min/max/step/
  maxlength/rows/pattern) lives in s_meta and is wired into rendering.
- **Phase 2 (groups)** — migration 0008 (`t_meta_group`,
  `t_meta_group_categories`, `t_meta_fields.fk_i_group_id`), `FieldGroup`
  model, group admin panel + editor, sectioned rendering (`.meta-section`).
- **Phase 3 (conditional logic + cascading)** — `rules` (show_when /
  required_when) in s_meta with a vanilla-JS client engine and server
  re-evaluation; small-set cascading dropdowns (`cascade_parent` +
  `cascade_map`) with client narrowing and server validation.
- The **drag-drop builder canvas / live preview** (§7) was delivered as an
  enhanced form-based editor rather than a canvas — the registry + group +
  rule + cascade config all edit inline in the existing field editor. A true
  canvas remains a future refinement.

Original design (kept for reference):
Scope: `t_meta_fields` / `t_meta_categories` / `t_item_meta`, the Custom fields admin
(`page=cfields`), the item form (admin + public), and listing search. This doc
proposes turning the flat field↔category model into a layered system: fields
**inherit** down the category tree, compose into reusable **groups (forms)**, and
support **conditional/dependent** behaviour — without breaking the field APIs
plugins and themes already depend on.

---

## 1. Why

Today custom fields are a flat list, each linked to individual categories with no
inheritance, no grouping, and no logic. In practice that means:

- **You re-assign fields per category.** A field on "Vehicles" does not reach
  "Vehicles › Cars"; you must tick every category, and any category added later is
  not covered.
- **You cannot attach a whole form.** There is no "Vehicle details" set you drop
  onto a category as a unit — only loose fields ordered by a single position.
- **Fields cannot depend on each other.** No "show Model only after a Make is
  picked", no cascading Make → Model dropdowns, no "required only when X".

The model is a reasonable v1 but it does not scale past a handful of fields on a
small category tree. This doc is the plan to fix that.

---

## 2. Current state (grounded)

### 2.1 Data model

- **`t_meta_fields`** — `pk_i_id, s_name, s_slug, e_type, s_options, b_required,
  b_searchable, s_meta, i_position`.
  - `e_type` is a fixed **ENUM**: `TEXT, NUMBER, TEXTAREA, DROPDOWN, RADIO, CHECKBOX,
    URL, DATE, DATEINTERVAL`. Adding a type is a schema change.
  - `s_options` is a flat option string for DROPDOWN/RADIO.
  - **`s_meta` is already a per-field JSON config blob** — `FieldForm.php:151`
    `json_decode`s it, `hItems.php:1517` reads it at render (e.g. the URL type's
    `b_new_tab`), `install-functions.php:807` seeds it. This is the ready-made
    extension point for new per-field config.
- **`t_meta_categories`** — a plain field↔category M2M (`fk_i_category_id,
  fk_i_field_id`). No group layer.
- **`t_item_meta`** — values: `fk_i_item_id, fk_i_field_id, s_value, s_multi`.
- **`t_category.fk_i_parent_id`** — the category tree parent (shallow, usually two
  levels). Not consulted when resolving fields.

### 2.2 Resolution is exact-match (no inheritance)

`Field::findByCategoryItem($catId, $itemId)` (`model/Field.php:277`) is the core
lookup the item form uses:

```sql
SELECT query.*, im.s_value ...
FROM (SELECT mf.* FROM t_meta_fields mf, t_meta_categories mc
      WHERE mc.fk_i_category_id = %d AND mf.pk_i_id = mc.fk_i_field_id) AS query
LEFT JOIN t_item_meta im ON im.fk_i_field_id = query.pk_i_id AND im.fk_i_item_id = %d
GROUP BY pk_i_id ORDER BY query.i_position ASC
```

`mc.fk_i_category_id = %d` matches **one** category. `Field::findByCategory`
(`:182`) and `findIDSearchableByCategories` (`:216`, used by search) are the same
shape. The admin's "check a subtree" button (`fields/index.php` JS) just ticks the
descendant checkboxes at assign time and writes individual rows — a convenience,
not live inheritance. Assign, then add a subcategory → the subcategory is uncovered.

### 2.3 Rendering & write path

- Render switches on `e_type` in `osc_item_meta_value` and the form partial
  (`hItems.php:1512–1580`): DATE/DATEINTERVAL, CHECKBOX, URL, TEXTAREA,
  DROPDOWN/RADIO each have a branch; each reads `s_meta` JSON for extras.
- Write path: `Field::insertField($name,$type,$slug,$required,$options,$categories)`
  (`:442`), `insertCategories($id,$categories)` (`:516`),
  `cleanCategoriesFromField` (`:546`), `categories($id)` (`:405`).
- Admin UX: `page=cfields` lists fields; adding/editing a field opens a category
  **tree in an iframe** (`ajax action=field_categories_iframe`) where you tick
  categories. There is no form/section builder and no live preview.

### 2.4 The compatibility contract (must not break)

Self-hosted installs run third-party themes/plugins that call these directly — they
are a public API even though nothing declares them:

- Helpers: `osc_get_item_meta`, `osc_has_item_meta`, `osc_count_item_meta`,
  `osc_item_meta`, `osc_item_meta_value` (`hItems.php:1456–1581`).
- Filters: `item_meta`, `osc_item_meta_value_*`.
- Model: `Field::findByCategory`, `findByCategoryItem`, `insertField`.

**Every change below is additive.** Old calls return the same shape; new capability
is opt-in. This mirrors the project's compatibility contract (§2.4): the `osc_*`
helpers, hook names and field APIs that third-party themes and plugins depend on
must not change shape.

---

## 3. Goals / non-goals

**Goals**
1. Assign a field/form once at a parent category; descendants inherit it.
2. Reusable **groups** (named field sets) attached to categories as a unit and
   rendered as form sections.
3. Conditional visibility/requirement and cascading (dependent) option lists.
4. New field types and per-field config **without** an ENUM/schema change each time.
5. A form-builder admin UX that replaces "create field → tick a category tree".

**Non-goals (for now)**
- Per-user or per-plan field sets. Cross-field computed values. Field versioning.
- Replacing `t_item_meta` value storage (keep it; it's fine).

---

## 4. Proposed model

### 4.1 Phase 1 — Category inheritance (query-only, no migration)

Resolve a category's fields by unioning its **ancestry path**, not one id. Given a
category, walk `fk_i_parent_id` to root, collect the id set, and match
`mc.fk_i_category_id IN (path…)`.

- Change `findByCategoryItem`, `findByCategory`, `findIDSearchableByCategories` to
  take the path (a helper `Field::categoryPath($catId): int[]` off the existing
  Category relation map, `Category.php:102`).
- De-dup by `pk_i_id` (already `GROUP BY pk_i_id`); order by nearest-ancestor then
  `i_position` so child ordering can win.
- **Zero data-model change, zero migration, no compat break** — a field assigned to
  a leaf still resolves; a field assigned to a parent now *also* resolves for
  children. Optional per-category "stop inheriting" / "hide inherited field X" is a
  later refinement stored in `s_meta`.

This alone removes most of the "assign per category" grind and is the foundation the
other phases build on.

### 4.2 Phase 2 — Field groups / reusable forms

Introduce a **Group** = a named, ordered, reusable set of fields, rendered as a
section (fieldset/tab) on the item form. You attach the **group** to categories
(with Phase 1 inheritance), not each field.

- New `t_meta_group`: `pk_i_id, s_name, s_slug, i_position, s_meta` (JSON:
  layout=section|tab, columns, description, collapsible…).
- New `t_meta_group_categories`: group↔category M2M (mirrors `t_meta_categories`).
- Field→group membership: add `fk_i_group_id` (nullable) to `t_meta_fields`, or a
  `t_meta_group_fields` link if a field may live in several groups. **Recommend the
  nullable column** — simpler, and a field belonging to one group is the common case.
- **Back-compat:** loose fields (`fk_i_group_id IS NULL`) render in an implicit
  "Ungrouped" default section; `t_meta_categories` keeps resolving. Nothing on a
  live install changes until the owner opts into groups.
- Resolution: item form asks for the categories' groups (inherited) + their fields,
  plus any still-loose per-category fields, and renders sections in `i_position`.

### 4.3 Phase 3 — Conditional logic + cascading options

Rules live in `s_meta` JSON (no schema change for the common cases):

- **Visibility / requirement:**
  `"rules": {"show_when": {"field": "<slug>", "op": "eq|neq|in|gt|lt|filled", "value": …},
  "required_when": {…}}`. A small client-side engine (vanilla JS, per the repo's
  no-jQuery rule) toggles `hidden`/`required`; the server **re-evaluates the same
  rules on save** so a hidden field can't be required and validation can't be
  bypassed.
- **Cascading options (Make → Model):**
  - *Small sets:* nested option map in the parent field's `s_meta`
    (`{"Toyota": ["Corolla", …]}`) — the child field filters client-side.
  - *Large taxonomies:* a real `t_meta_field_option` table (`pk_i_id, fk_i_field_id,
    fk_i_parent_option_id, s_key, s_label, i_position`) with an AJAX endpoint
    returning children of a chosen parent option. This also cleans up today's flat
    `s_options` string for big lists.

Ship the small-set path first; the option table is an add-on when someone needs a
1000-row Make/Model list.

### 4.4 Cross-cutting — field-type registry + richer config

- Replace the `e_type` **ENUM** dependence with a **registry** (like the existing
  `PageTemplateRegistry` / widget registry): core registers the built-in types;
  plugins register new ones (render + validate + sanitise callbacks). `e_type` stays
  as the stored primitive/storage key so old data and old themes keep working; the
  registry decides how each type renders/validates.
- Move per-field extras into `s_meta` (already the pattern): placeholder, help text,
  default, min/max/step/regex, unit, multi-select, column width. No schema churn.
- Candidate new types once the registry exists: MULTISELECT, EMAIL, PHONE,
  PRICE/RANGE, and a location-linked select.

---

## 5. Data model changes (summary)

| Phase | Change | Migration |
|---|---|---|
| 1 | none (query logic only) | none |
| 2 | `t_meta_group`, `t_meta_group_categories`, `t_meta_fields.fk_i_group_id` | `MigrationRunner` migration; back-fill NULL group |
| 3 (opt) | `t_meta_field_option` | migration; only when cascading-at-scale is needed |
| x-cut | none (config in `s_meta`, types in registry) | none |

New tables/queries follow the current modernisation: `MigrationRunner`, `osc_db_*`
/ QueryBuilder, models under `mindstellar\model`.

---

## 6. API & compatibility

- Keep `Field::findByCategory(Item)` returning the same row shape — inheritance is
  internal to how it builds the id set. Add `Field::findByCategoryPath(...)` /
  `Group::findByCategory(...)` as the new entry points; the old methods delegate.
- Add helpers mirroring the item-meta ones for groups
  (`osc_item_meta_groups()` iterating sections) — additive.
- New filters: `custom_field_types` (registry), `custom_field_rules`,
  `item_meta_group`. Keep all existing `item_meta*` filters firing unchanged.

---

## 7. Admin UX (the form builder)

Replace "create field → tick a category tree in an iframe" with:

1. **Forms/Groups list** — create a form, set layout (sections/tabs).
2. **Builder canvas** — drag field types in from a palette, arrange into sections/
   columns, configure each field in a right-hand panel (label, slug, required,
   options, validation, **rules**), with **live preview** of the item form.
3. **Assignment** — attach the form to categories in one tree picker, with
   inheritance made explicit ("applies to Vehicles and all subcategories").

This reuses the drag-order and registry patterns already built for widgets/pages, so
it is not from scratch. Legacy loose fields appear as an "Ungrouped" form that can be
edited in the same canvas.

---

## 8. Rendering & validation

- One render path keyed by the type registry (admin add/edit item, public post,
  public listing view). Sections wrap fields; hidden-by-rule fields are output but
  `hidden` so JS can reveal them (never gate the value on a class-triggered reveal).
- Validation is server-authoritative: required/visibility/cascade rules are
  re-evaluated on save; client JS is UX only. Sanitise per type via the registry
  (`osc_sanitize_*` in, `osc_esc_*` out; state-changing admin saves keep
  `osc_csrf_check()`).

---

## 9. Search

`findIDSearchableByCategories` gets the same ancestry-path treatment as Phase 1, so
a searchable field assigned to a parent is searchable in children. Cascading fields
index their stored `s_value` as today — no change to the value model.

---

## 10. Migration & rollout

- Phase 1 ships with **no data change** — safe to release on its own; existing
  assignments simply start inheriting.
- Phase 2 migration back-fills `fk_i_group_id = NULL` (the default "Ungrouped"
  group is virtual, not a row, so there is nothing to seed). Reversible.
- Feature-gate the builder behind the existing admin so the old field editor stays
  available until the builder reaches parity.

---

## 11. Phasing & rough effort

1. **Phase 1 — inheritance.** Small. Query + a category-path helper + tests. Highest
   ROI, lowest risk. **Recommend shipping first, standalone.**
2. **Phase 2 — groups + builder.** Large. Schema + models + the builder UI + render
   changes. The bulk of the work; delivers "attach a whole form".
3. **Phase 3 — conditional logic + cascading.** Medium–large, built on Phase 2. Rule
   schema in `s_meta`, JS engine, server re-eval, optional option table.
4. **Type registry / richer config.** Can land alongside Phase 2 (the builder needs
   it to render arbitrary types cleanly).

---

## 12. Resolved

The questions this document opened are settled by what shipped: a field belongs to any
number of forms (the builder shows how many), inherited fields resolve down the category
path, and options stayed on `s_options` rather than moving to a table — the threshold that
would have justified one has not been reached.

User-facing documentation is at
[mindstellar.com/docs/use/forms-and-custom-fields](https://mindstellar.com/docs/use/forms-and-custom-fields/).
