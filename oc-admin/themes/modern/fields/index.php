<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_enqueue_script('sortablejs');

$fields    = __get('fields');
$forms     = __get('groups');
$placedIds = __get('placed_field_ids');
$catNames  = __get('category_names');
if (!is_array($fields)) {
    $fields = array();
}
if (!is_array($forms)) {
    $forms = array();
}
if (!is_array($placedIds)) {
    $placedIds = array();
}
if (!is_array($catNames)) {
    $catNames = array();
}
$placedIds = array_map('intval', $placedIds);

// Field lookup by id, for rendering a form's chips from its ordered field_ids.
$fieldsById = array();
foreach ($fields as $f) {
    $fieldsById[(int)$f['pk_i_id']] = $f;
}

function addHelp()
{
    echo '<p>'
         . __('Build reusable forms by dragging fields from the palette on the right into a form on the left. '
              . 'A field can be placed in any number of forms. Each form is attached to categories and renders '
              . 'as a section on the listing form.')
         . '</p>';
}

osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Forms'); ?>
        <a href="#" class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
           aria-label="<?php echo osc_esc_html(__('Help')); ?>"></a>
    </h1>
    <?php
}

if (!function_exists('cfields_type_label')) {
    /**
     * Human label for a custom-field type (registry-aware).
     *
     * @param string $type
     *
     * @return string
     */
    function cfields_type_label($type)
    {
        $spec = osc_field_type((string) $type);
        if ($spec !== null) {
            return __($spec['label']);
        }

        return (string) $type;
    }
}

if (!function_exists('cfields_render_chip')) {
    /**
     * A draggable field chip, shared by the palette and each form's field list. The
     * remove (✕) button is present in every chip but shown by CSS only inside a form
     * list, so a palette chip cloned into a form gains its remove control for free.
     *
     * @param array $field
     * @param bool  $placed whether this field already belongs to a form (palette hint)
     *
     * @return void
     */
    function cfields_render_chip($field, $placed = false)
    {
        $type = osc_field_resolve_type($field);
        echo '<li class="field-chip' . ($placed ? ' is-placed' : '') . '" data-field-id="' . (int)$field['pk_i_id']
            . '" data-type="' . osc_esc_html($type) . '">';
        echo '<span class="chip-grip" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>';
        echo '<span class="chip-name">' . osc_esc_html($field['s_name']) . '</span>';
        echo '<span class="chip-type">' . osc_esc_html(cfields_type_label($type)) . '</span>';
        echo '<span class="chip-actions">';
        echo '<button type="button" class="chip-btn chip-edit" onclick="edit_field(' . (int)$field['pk_i_id']
            . '); return false;" title="' . osc_esc_html(__('Edit field')) . '"><i class="bi bi-pencil-fill" aria-hidden="true"></i></button>';
        echo '<button type="button" class="chip-btn chip-remove" title="' . osc_esc_html(__('Remove from form'))
            . '"><i class="bi bi-x-lg" aria-hidden="true"></i></button>';
        echo '</li>';
    }
}

if (!function_exists('cfields_form_cat_summary')) {
    /**
     * The "Applies to: …" line under a form's title. A form with no categories is
     * shown as unattached — it renders on no listing until it is given at least one.
     *
     * @param array $categoryIds
     * @param array $catNames id => localised name
     *
     * @return string
     */
    function cfields_form_cat_summary($categoryIds, $catNames)
    {
        $names = array();
        foreach ((array)$categoryIds as $cid) {
            if (isset($catNames[(int)$cid])) {
                $names[] = $catNames[(int)$cid];
            }
        }
        if (empty($names)) {
            return '<span class="form-card-cats is-unattached"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> '
                . osc_esc_html(__('Not attached to a category — it won\'t appear on listings yet'))
                . '</span>';
        }
        $shown = array_slice($names, 0, 3);
        $extra = count($names) - count($shown);
        $label = '<span class="cats-label">' . osc_esc_html(__('Applies to')) . ':</span> '
            . osc_esc_html(implode(', ', $shown));
        if ($extra > 0) {
            $label .= ' <span class="cats-more">+' . $extra . '</span>';
        }

        return '<span class="form-card-cats">' . $label . '</span>';
    }
}

osc_add_hook('admin_page_header', 'customPageHeader');

function customHead()
{
    $csrf_token = osc_csrf_token_url(); ?>
    <script type="text/javascript">
        // Inject fetched HTML and run any <script> it carries (innerHTML alone does
        // not execute scripts; the editors wire themselves up in one).
        function oscInjectHtml(container, html) {
            container.innerHTML = html;
            container.querySelectorAll('script').forEach(function (old) {
                var s = document.createElement('script');
                if (old.src) { s.src = old.src; } else { s.textContent = old.textContent; }
                old.parentNode.replaceChild(s, old);
            });
        }

        // ---- The editor drawer ----------------------------------------------
        // Both editors (a field definition, a form's name+categories) open in one
        // right-side drawer at a comfortable width, instead of being crushed into
        // the palette rail or shoved inline between form cards.
        var drawerReturnFocus = null;

        function openDrawer() {
            var drawer = document.getElementById('cf-drawer');
            if (!drawer) { return; }
            drawerReturnFocus = document.activeElement;
            drawer.hidden = false;
            // Give the grid a third column on wide screens; the overlay path ignores it.
            var grid = document.querySelector('.forms-builder-grid');
            if (grid) { grid.classList.add('is-editing'); }
            // next frame so the slide-in transition runs
            requestAnimationFrame(function () { drawer.classList.add('is-open'); });
            document.body.classList.add('cf-drawer-lock');
            // focus the first field once the fetched editor has wired itself up
            setTimeout(function () {
                var first = drawer.querySelector('input:not([type=hidden]), select, textarea, button');
                if (first) { first.focus(); }
            }, 60);
        }

        window.cfCloseDrawer = function () {
            var drawer = document.getElementById('cf-drawer');
            if (!drawer || drawer.hidden) { return; }
            drawer.classList.remove('is-open');
            document.body.classList.remove('cf-drawer-lock');
            var grid = document.querySelector('.forms-builder-grid');
            if (grid) { grid.classList.remove('is-editing'); }
            document.querySelectorAll('.field-chip.is-editing').forEach(function (el) { el.classList.remove('is-editing'); });
            var body = document.getElementById('cf-drawer-body');
            var hide = function () { drawer.hidden = true; if (body) { body.innerHTML = ''; } };
            // clear after the slide-out; guard with a timeout in case transitionend misfires
            var done = false;
            drawer.addEventListener('transitionend', function te() { if (done) { return; } done = true; drawer.removeEventListener('transitionend', te); hide(); });
            setTimeout(function () { if (!done) { done = true; hide(); } }, 260);
            if (drawerReturnFocus && drawerReturnFocus.focus) { drawerReturnFocus.focus(); }
            drawerReturnFocus = null;
        };

        function loadDrawer(url, title) {
            var body = document.getElementById('cf-drawer-body');
            var titleEl = document.getElementById('cf-drawer-title');
            if (!body) { return; }
            if (titleEl) { titleEl.textContent = title || ''; }
            body.innerHTML = '<p class="cf-drawer-loading">' + '<?php echo osc_esc_js(__('Loading…')); ?>' + '</p>';
            openDrawer();
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) { oscInjectHtml(body, html); });
        }

        // Field-definition editor. builder=1 hides the group/category controls
        // (membership + categories are set on the form) and saves the definition only.
        function edit_field(id) {
            document.querySelectorAll('.field-chip.is-editing').forEach(function (el) { el.classList.remove('is-editing'); });
            document.querySelectorAll('#palette-list .field-chip[data-field-id="' + id + '"]').forEach(function (chip) { chip.classList.add('is-editing'); });
            loadDrawer(
                '<?php echo osc_admin_base_url(true); ?>?page=ajax&action=field_categories_iframe&builder=1&<?php echo $csrf_token; ?>&id=' + id,
                '<?php echo osc_esc_js(__('Edit field')); ?>'
            );
            return false;
        }

        // Form editor (name + category assignment).
        function show_group_iframe(id) {
            loadDrawer(
                '<?php echo osc_admin_base_url(true); ?>?page=ajax&action=group_categories_iframe&<?php echo $csrf_token; ?>&id=' + id,
                '<?php echo osc_esc_js(__('Edit form')); ?>'
            );
            return false;
        }

        function checkAll(id, check) {
            var root = document.getElementById(id);
            if (root) { root.querySelectorAll('input[type=checkbox]').forEach(function (cb) { cb.checked = check; }); }
        }
        function checkCat(id, check) {
            var root = document.getElementById('cat' + id);
            if (root) { root.querySelectorAll('input[type=checkbox]').forEach(function (cb) { cb.checked = check; }); }
        }

        function delete_group(id) {
            var modal = document.getElementById('deleteGroupModal');
            modal.setAttribute('data-group-id', id);
            modal.showModal();
            return false;
        }
        function delete_field(id) {
            var modal = document.getElementById('deleteModal');
            modal.setAttribute('data-field-id', id);
            modal.showModal();
            return false;
        }

        // ---- The builder ----------------------------------------------------
        var BASE = '<?php echo osc_admin_base_url(true); ?>';
        var CSRF = '<?php echo $csrf_token; ?>';
        var CF_CAT_NAMES = <?php echo json_encode((object)$catNames); ?>;
        var CF_TXT = {
            saving:     '<?php echo osc_esc_js(__('Saving…')); ?>',
            saved:      '<?php echo osc_esc_js(__('Saved')); ?>',
            inForms:    '<?php echo osc_esc_js(__('In %d forms')); ?>',
            inFormsTip: '<?php echo osc_esc_js(__('Editing this field changes it in every form that uses it.')); ?>',
            applies:    '<?php echo osc_esc_js(__('Applies to')); ?>',
            unattached: '<?php echo osc_esc_js(__('Not attached to a category — it won\'t appear on listings yet')); ?>',
            allAdded:   '<?php echo osc_esc_js(__('Every field is already in this form.')); ?>'
        };

        function cfEsc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // A brief per-form "Saving… / Saved" note, so a silent autosave reads as done.
        function setCardStatus(el, state) {
            if (!el) { return; }
            if (el._t) { clearTimeout(el._t); el._t = null; }
            el.classList.remove('is-saving', 'is-saved');
            if (state === 'saving') {
                el.textContent = CF_TXT.saving;
                el.classList.add('is-saving');
            } else if (state === 'saved') {
                el.textContent = CF_TXT.saved;
                el.classList.add('is-saved');
                el._t = setTimeout(function () { el.classList.remove('is-saved'); el.textContent = ''; }, 2500);
            } else {
                el.textContent = '';
            }
        }

        // Mark each palette chip with the number of forms it is placed in, so the
        // shared nature of a field definition is visible before it is edited.
        function refreshSharedBadges() {
            var lists = document.querySelectorAll('.form-fieldlist');
            document.querySelectorAll('#palette-list > .field-chip').forEach(function (chip) {
                var id = chip.getAttribute('data-field-id');
                var n = 0;
                lists.forEach(function (list) {
                    if (list.querySelector(':scope > .field-chip[data-field-id="' + id + '"]')) { n++; }
                });
                var badge = chip.querySelector('.chip-shared');
                if (n >= 2) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'chip-shared';
                        badge.title = CF_TXT.inFormsTip;
                        chip.insertBefore(badge, chip.querySelector('.chip-type'));
                    }
                    badge.textContent = CF_TXT.inForms.replace('%d', n);
                } else if (badge) {
                    badge.remove();
                }
            });
        }

        // ---- Collapse / expand a form card (persisted per form) --------------
        var CF_COLLAPSE_KEY = 'cf_collapsed_forms';
        function getCollapsedSet() {
            try { return new Set(JSON.parse(localStorage.getItem(CF_COLLAPSE_KEY) || '[]')); }
            catch (e) { return new Set(); }
        }
        function saveCollapsedSet(set) {
            try { localStorage.setItem(CF_COLLAPSE_KEY, JSON.stringify(Array.from(set))); } catch (e) {}
        }
        function setCardCollapsed(card, collapsed) {
            card.classList.toggle('is-collapsed', collapsed);
            var btn = card.querySelector('.form-card-toggle');
            if (btn) { btn.setAttribute('aria-expanded', String(!collapsed)); }
        }
        function applyCollapsedState() {
            var set = getCollapsedSet();
            document.querySelectorAll('.form-card').forEach(function (card) {
                setCardCollapsed(card, set.has(card.getAttribute('data-form-id')));
            });
        }

        // ---- Add a field without dragging (keyboard / touch path) ------------
        function closeAddMenu(except) {
            document.querySelectorAll('.form-card-add').forEach(function (wrap) {
                if (wrap === except) { return; }
                var menu = wrap.querySelector('.cf-add-menu');
                var btn = wrap.querySelector('.form-add-field');
                if (menu) { menu.hidden = true; }
                if (btn) { btn.setAttribute('aria-expanded', 'false'); }
            });
        }

        // Append a field's chip to a form (mirrors a drag from the palette).
        function addFieldToForm(card, id) {
            var src = document.querySelector('#palette-list > .field-chip[data-field-id="' + id + '"]');
            var list = card ? card.querySelector('.form-fieldlist') : null;
            if (!src || !list) { return; }
            var clone = src.cloneNode(true);
            clone.classList.remove('is-editing', 'is-placed');
            var badge = clone.querySelector('.chip-shared');
            if (badge) { badge.remove(); }
            list.appendChild(clone);
            saveForm(list);
        }

        // Build the menu of fields not already in this form.
        function openAddMenu(wrap) {
            var card = wrap.closest('.form-card');
            var list = card.querySelector('.form-fieldlist');
            var menu = wrap.querySelector('.cf-add-menu');
            var btn = wrap.querySelector('.form-add-field');
            var present = {};
            list.querySelectorAll(':scope > .field-chip').forEach(function (c) { present[c.getAttribute('data-field-id')] = true; });
            menu.innerHTML = '';
            var any = false;
            document.querySelectorAll('#palette-list > .field-chip').forEach(function (src) {
                var id = src.getAttribute('data-field-id');
                if (present[id]) { return; }
                any = true;
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'cf-add-item';
                item.setAttribute('role', 'menuitem');
                item.setAttribute('data-field-id', id);
                var nameEl = src.querySelector('.chip-name');
                var typeEl = src.querySelector('.chip-type');
                item.innerHTML = '<span class="cf-add-name"></span>' + (typeEl ? '<span class="chip-type"></span>' : '');
                item.querySelector('.cf-add-name').textContent = nameEl ? nameEl.textContent : '';
                if (typeEl) { item.querySelector('.chip-type').textContent = typeEl.textContent; }
                item.addEventListener('click', function () { addFieldToForm(card, id); closeAddMenu(); btn.focus(); });
                menu.appendChild(item);
            });
            if (!any) {
                var empty = document.createElement('div');
                empty.className = 'cf-add-empty';
                empty.textContent = CF_TXT.allAdded;
                menu.appendChild(empty);
            }
            closeAddMenu(wrap);
            menu.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            var first = menu.querySelector('.cf-add-item');
            if (first) { first.focus(); }
        }

        // Rewrite a form card's "Applies to: …" line after its categories are saved.
        window.cfSetCardCats = function (formId, catIds) {
            var card = document.querySelector('.form-card[data-form-id="' + formId + '"]');
            var el = card ? card.querySelector('.form-card-cats') : null;
            if (!el) { return; }
            var names = catIds.map(function (id) { return CF_CAT_NAMES[id]; }).filter(Boolean);
            if (!names.length) {
                el.className = 'form-card-cats is-unattached';
                el.innerHTML = '<i class="bi bi-exclamation-circle" aria-hidden="true"></i> ' + cfEsc(CF_TXT.unattached);
                return;
            }
            el.className = 'form-card-cats';
            var shown = names.slice(0, 3);
            var extra = names.length - shown.length;
            var html = '<span class="cats-label">' + cfEsc(CF_TXT.applies) + ':</span> ' + shown.map(cfEsc).join(', ');
            if (extra > 0) { html += ' <span class="cats-more">+' + extra + '</span>'; }
            el.innerHTML = html;
        };

        // Persist a form's whole ordered field list (add/remove/reorder in one call).
        var saveTimers = {};
        function saveForm(listEl) {
            if (!listEl) { return; }
            var formId = listEl.getAttribute('data-form-id');
            var card = listEl.closest('.form-card');
            var status = card ? card.querySelector('.form-card-status') : null;
            var ids = Array.prototype.map.call(listEl.querySelectorAll(':scope > .field-chip'),
                function (li) { return li.getAttribute('data-field-id'); });
            // Membership just changed in the DOM — reflect it on the palette at once.
            refreshSharedBadges();
            setCardStatus(status, 'saving');
            clearTimeout(saveTimers[formId]);
            saveTimers[formId] = setTimeout(function () {
                var body = new URLSearchParams();
                body.set('form_id', formId);
                body.set('fields', JSON.stringify(ids));
                fetch(BASE + '?page=ajax&action=form_set_fields&' + CSRF, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body
                }).then(function (r) { return r.json(); }).then(function (ret) {
                    if (ret.error) { setJsMessage('error', ret.error); setCardStatus(status, ''); }
                    else { setCardStatus(status, 'saved'); }
                }).catch(function () {
                    setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, please try again.')); ?>');
                    setCardStatus(status, '');
                });
                refreshEmptyStates();
            }, 250);
        }

        function refreshEmptyStates() {
            document.querySelectorAll('.form-fieldlist').forEach(function (list) {
                var has = list.querySelector(':scope > .field-chip');
                var ph = list.parentNode.querySelector('.form-empty');
                if (ph) { ph.style.display = has ? 'none' : ''; }
            });
        }

        function initFormSortable(listEl) {
            if (typeof Sortable === 'undefined' || !listEl || listEl._sortable) { return; }
            listEl._sortable = new Sortable(listEl, {
                group: 'fields', handle: '.chip-grip', animation: 150, ghostClass: 'chip-ghost',
                onAdd: function (evt) { saveForm(evt.to); },
                onRemove: function (evt) { saveForm(evt.from); },
                onUpdate: function (evt) { saveForm(evt.to); }
            });
        }

        function buildFormCard(id, name) {
            var card = document.createElement('div');
            card.className = 'form-card';
            card.setAttribute('data-form-id', id);
            card.innerHTML = ''
                + '<div class="form-card-head">'
                + '  <button type="button" class="form-card-toggle" aria-expanded="true" aria-label="<?php echo osc_esc_js(__('Collapse or expand this form')); ?>"><i class="bi bi-chevron-down"></i></button>'
                + '  <div class="form-card-heading">'
                + '    <span class="form-card-title" id="group_name_' + id + '"></span>'
                + '    <span class="form-card-cats is-unattached"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> ' + cfEsc(CF_TXT.unattached) + '</span>'
                + '  </div>'
                + '  <span class="form-card-actions">'
                + '    <span class="form-card-status" role="status" aria-live="polite"></span>'
                + '    <button type="button" class="chip-btn" onclick="show_group_iframe(\'' + id + '\'); return false;" title="<?php echo osc_esc_js(__('Categories & name')); ?>"><i class="bi bi-gear-fill"></i></button>'
                + '    <button type="button" class="chip-btn chip-danger" onclick="delete_group(\'' + id + '\'); return false;" title="<?php echo osc_esc_js(__('Delete form')); ?>"><i class="bi bi-trash-fill"></i></button>'
                + '  </span>'
                + '</div>'
                + '<ul class="form-fieldlist" data-form-id="' + id + '"></ul>'
                + '<div class="form-empty"><?php echo osc_esc_js(__('Drag a field here, or use “Add field”.')); ?></div>'
                + '<div class="form-card-add">'
                + '  <button type="button" class="btn btn-outline-primary btn-sm form-add-field" aria-haspopup="true" aria-expanded="false"><i class="bi bi-plus-lg"></i> <?php echo osc_esc_js(__('Add field')); ?></button>'
                + '  <div class="cf-add-menu" role="menu" hidden></div>'
                + '</div>';
            card.querySelector('.form-card-title').textContent = name;
            document.getElementById('forms-list').appendChild(card);
            initFormSortable(card.querySelector('.form-fieldlist'));
            return card;
        }

        document.addEventListener('DOMContentLoaded', function () {
            // palette is a clone source only
            var palette = document.getElementById('palette-list');
            if (typeof Sortable !== 'undefined' && palette) {
                new Sortable(palette, {
                    group: { name: 'fields', pull: 'clone', put: false },
                    sort: false, handle: '.chip-grip', animation: 150, ghostClass: 'chip-ghost'
                });
            }
            document.querySelectorAll('.form-fieldlist').forEach(initFormSortable);
            refreshEmptyStates();
            refreshSharedBadges();
            applyCollapsedState();

            // Collapse / expand a form via its caret (state persists across reloads).
            document.addEventListener('click', function (e) {
                var tg = e.target.closest('.form-card-toggle');
                if (!tg) { return; }
                var card = tg.closest('.form-card');
                if (!card) { return; }
                var id = card.getAttribute('data-form-id');
                var collapsed = !card.classList.contains('is-collapsed');
                setCardCollapsed(card, collapsed);
                var set = getCollapsedSet();
                if (collapsed) { set.add(id); } else { set.delete(id); }
                saveCollapsedSet(set);
            });

            // ✕ removes a chip from its form
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.chip-remove');
                if (!btn) { return; }
                var chip = btn.closest('.field-chip');
                var list = chip ? chip.closest('.form-fieldlist') : null;
                if (chip && list) { chip.remove(); saveForm(list); }
            });

            // Drawer: close on the ✕, the scrim, or Escape.
            document.addEventListener('click', function (e) {
                if (e.target.closest('[data-cf-drawer-close]')) { e.preventDefault(); window.cfCloseDrawer(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' || e.key === 'Esc') {
                    var drawer = document.getElementById('cf-drawer');
                    if (drawer && !drawer.hidden) { window.cfCloseDrawer(); return; }
                    closeAddMenu();
                }
            });

            // Add-field menu: toggle on the button, close on any outside click.
            document.addEventListener('click', function (e) {
                var addBtn = e.target.closest('.form-add-field');
                if (addBtn) {
                    e.preventDefault();
                    var wrap = addBtn.closest('.form-card-add');
                    var menu = wrap.querySelector('.cf-add-menu');
                    if (menu.hidden) { openAddMenu(wrap); } else { closeAddMenu(); }
                    return;
                }
                if (!e.target.closest('.cf-add-menu')) { closeAddMenu(); }
            });

            // + New form
            var addFormBtn = document.getElementById('add-form-button');
            if (addFormBtn) {
                addFormBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    fetch(BASE + '?page=ajax&action=add_group&' + CSRF, { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); }).then(function (ret) {
                            if (ret && ret.error == 0) {
                                var empty = document.getElementById('forms-empty');
                                if (empty) { empty.remove(); }
                                buildFormCard(ret.group_id, ret.group_name);
                                show_group_iframe(ret.group_id);
                            } else { setJsMessage('error', '<?php echo osc_esc_js(__('Form could not be created')); ?>'); }
                        });
                });
            }

            // + New field (appends a palette chip and opens its editor)
            var addFieldBtn = document.getElementById('add-field-button');
            if (addFieldBtn) {
                addFieldBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    fetch(BASE + '?page=ajax&action=add_field&' + CSRF, { credentials: 'same-origin' })
                        .then(function (r) { return r.text(); }).then(function (res) {
                            var ret; try { ret = JSON.parse(res); } catch (err) { ret = null; }
                            if (ret && ret.error == 0) {
                                var li = document.createElement('li');
                                li.className = 'field-chip';
                                li.setAttribute('data-field-id', ret.field_id);
                                li.setAttribute('data-type', 'TEXT');
                                li.innerHTML = ''
                                    + '<span class="chip-grip" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>'
                                    + '<span class="chip-name"></span>'
                                    + '<span class="chip-type"><?php echo osc_esc_js(__('Text')); ?></span>'
                                    + '<span class="chip-actions">'
                                    + '  <button type="button" class="chip-btn chip-edit" onclick="edit_field(' + ret.field_id + '); return false;" title="<?php echo osc_esc_js(__('Edit field')); ?>"><i class="bi bi-pencil-fill"></i></button>'
                                    + '  <button type="button" class="chip-btn chip-remove" title="<?php echo osc_esc_js(__('Remove from form')); ?>"><i class="bi bi-x-lg"></i></button>'
                                    + '</span>';
                                li.querySelector('.chip-name').textContent = ret.field_name;
                                var pEmpty = document.getElementById('palette-empty');
                                if (pEmpty) { pEmpty.remove(); }
                                palette.appendChild(li);
                                edit_field(ret.field_id);
                            } else { setJsMessage('error', '<?php echo osc_esc_js(__('Field could not be added')); ?>'); }
                        });
                });
            }
        });
    </script>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

function customPageTitle($string)
{
    return sprintf(__('Custom forms &raquo; %s'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php');
?>
    <h2 class="render-title"><?php _e('Custom forms'); ?></h2>

    <div class="forms-builder">
        <div class="forms-builder-grid">

            <!-- LEFT: forms -->
            <div class="forms-col">
                <div class="col-head">
                    <h3 class="col-title"><?php _e('Forms'); ?></h3>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="add-form-button">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> <?php _e('New form'); ?>
                    </button>
                </div>
                <p class="col-hint"><?php _e('A form is a set of fields shown together on the listing form, for the categories you choose.'); ?></p>
                <div id="forms-list">
                    <?php if (count($forms) === 0) { ?>
                        <div id="forms-empty" class="builder-empty">
                            <?php _e('No forms yet. Create one, then drag fields into it from the palette.'); ?>
                        </div>
                    <?php } ?>
                    <?php foreach ($forms as $form) {
                        $fid = (int)$form['pk_i_id']; ?>
                        <div class="form-card" data-form-id="<?php echo $fid; ?>">
                            <div class="form-card-head">
                                <button type="button" class="form-card-toggle" aria-expanded="true"
                                        aria-label="<?php echo osc_esc_html(__('Collapse or expand this form')); ?>"><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
                                <div class="form-card-heading">
                                    <span class="form-card-title" id="group_name_<?php echo $fid; ?>"><?php echo osc_esc_html($form['s_name']); ?></span>
                                    <?php echo cfields_form_cat_summary($form['category_ids'] ?? array(), $catNames); ?>
                                </div>
                                <span class="form-card-actions">
                                    <span class="form-card-status" role="status" aria-live="polite"></span>
                                    <button type="button" class="chip-btn" onclick="show_group_iframe('<?php echo $fid; ?>'); return false;"
                                            title="<?php echo osc_esc_html(__('Categories & name')); ?>"><i class="bi bi-gear-fill" aria-hidden="true"></i></button>
                                    <button type="button" class="chip-btn chip-danger" onclick="delete_group('<?php echo $fid; ?>'); return false;"
                                            title="<?php echo osc_esc_html(__('Delete form')); ?>"><i class="bi bi-trash-fill" aria-hidden="true"></i></button>
                                </span>
                            </div>
                            <ul class="form-fieldlist" data-form-id="<?php echo $fid; ?>">
                                <?php foreach ($form['field_ids'] as $fieldId) {
                                    if (isset($fieldsById[(int)$fieldId])) {
                                        cfields_render_chip($fieldsById[(int)$fieldId]);
                                    }
                                } ?>
                            </ul>
                            <div class="form-empty"><?php _e('Drag a field here, or use “Add field”.'); ?></div>
                            <div class="form-card-add">
                                <button type="button" class="btn btn-outline-primary btn-sm form-add-field" aria-haspopup="true" aria-expanded="false">
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i> <?php _e('Add field'); ?>
                                </button>
                                <div class="cf-add-menu" role="menu" hidden></div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>

            <!-- RIGHT: field palette -->
            <div class="palette-col">
                <div class="col-head">
                    <h3 class="col-title"><?php _e('Fields'); ?></h3>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="add-field-button">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> <?php _e('New field'); ?>
                    </button>
                </div>
                <p class="col-hint"><?php _e('Your reusable fields. Drag one into a form on the left, or use a form’s “Add field”. The same field can be placed in several forms.'); ?></p>
                <ul id="palette-list" class="palette-list">
                    <?php if (count($fields) === 0) { ?>
                        <li id="palette-empty" class="builder-empty"><?php _e('No fields yet. Create one to get started.'); ?></li>
                    <?php } ?>
                    <?php foreach ($fields as $field) {
                        cfields_render_chip($field, in_array((int)$field['pk_i_id'], $placedIds, true));
                    } ?>
                </ul>
            </div>

            <!-- Shared editor. On wide screens it docks inline as a third column
                 (both editors open here); on narrow screens it overlays as a drawer. -->
            <div id="cf-drawer" class="cf-drawer" hidden>
                <div class="cf-drawer-scrim" data-cf-drawer-close></div>
                <aside class="cf-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="cf-drawer-title">
                    <header class="cf-drawer-head">
                        <h3 id="cf-drawer-title" class="cf-drawer-title"></h3>
                        <button type="button" class="chip-btn" data-cf-drawer-close
                                aria-label="<?php echo osc_esc_html(__('Close')); ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    </header>
                    <div id="cf-drawer-body" class="cf-drawer-body edit"></div>
                </aside>
            </div>

        </div>
    </div>

    <dialog id="deleteModal" class="osc-dialog osc-dialog-danger" data-field-id="">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo __('Delete field'); ?></p>
            <p class="osc-dialog-text"><?php _e('This deletes the field definition and removes it from every form. Continue?'); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="deleteSubmit" type="button" class="btn btn-danger btn-sm"><?php echo __('Delete'); ?></button>
        </div>
    </dialog>
    <dialog id="deleteGroupModal" class="osc-dialog osc-dialog-danger" data-group-id="">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo __('Delete form'); ?></p>
            <p class="osc-dialog-text"><?php _e('Deleting the form keeps its fields (they return to the palette) but removes the form and its category assignment. Continue?'); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="deleteGroupSubmit" type="button" class="btn btn-danger btn-sm"><?php echo __('Delete'); ?></button>
        </div>
    </dialog>
    <script>
        document.getElementById('deleteSubmit').onclick = function () {
            var modal = document.getElementById('deleteModal');
            var fieldId = modal.dataset.fieldId;
            modal.close();
            fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=delete_field&<?php echo osc_csrf_token_url(); ?>&id=' + fieldId, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); }).then(function (o) {
                    if (o.error) { setJsMessage('error', o.error); }
                    if (o.ok) {
                        setJsMessage('ok', o.ok);
                        document.querySelectorAll('.field-chip[data-field-id="' + fieldId + '"]').forEach(function (el) {
                            var list = el.closest('.form-fieldlist');
                            el.remove();
                            if (list) { /* server already removed links via delete */ }
                        });
                        if (typeof refreshSharedBadges === 'function') { refreshSharedBadges(); }
                    }
                }).catch(function () { setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, try again.')); ?>'); });
        };
        document.getElementById('deleteGroupSubmit').onclick = function () {
            var modal = document.getElementById('deleteGroupModal');
            var groupId = modal.dataset.groupId;
            modal.close();
            fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=delete_group&<?php echo osc_csrf_token_url(); ?>&id=' + groupId, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); }).then(function (o) {
                    if (o.error) { setJsMessage('error', o.error); }
                    if (o.ok) {
                        setJsMessage('ok', o.ok);
                        var card = document.querySelector('.form-card[data-form-id="' + groupId + '"]');
                        if (card) { card.remove(); }
                        if (typeof refreshSharedBadges === 'function') { refreshSharedBadges(); }
                    }
                }).catch(function () { setJsMessage('error', '<?php echo osc_esc_js(__('Ajax error, try again.')); ?>'); });
        };
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
