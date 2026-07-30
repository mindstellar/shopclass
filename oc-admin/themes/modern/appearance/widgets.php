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


// The widget editor now opens inline in this page, so the editor's dependencies
// have to be present here rather than only on the standalone add/edit screen.
osc_enqueue_script('tiny_mce');
osc_enqueue_script('sortablejs');

$info = __get('info');

function addHelp()
{
    echo '<p>' . __("Modify your site's header or footer here.") . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Appearance'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Appearance &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="appearance-page">
    <div class="appearance">
        <h2 class="render-title"><?php _e('Manage Widgets'); ?> </h2>
    </div>
</div>
</div> <!-- -->
<?php
// Palette of available widget types, grouped by source. "Legacy" sorts last: the
// raw-markup escape hatch should be reachable but never the obvious first pick.
$paletteTypes = array();
foreach (osc_widget_types() as $typeId => $typeSpec) {
    if (osc_is_moderator() && isset($typeSpec['capability']) && $typeSpec['capability'] === 'super_admin') {
        continue;
    }
    $paletteTypes[$typeSpec['group']][$typeId] = $typeSpec;
}
uksort($paletteTypes, static function ($a, $b) {
    if ($a === 'Legacy') { return 1; }
    if ($b === 'Legacy') { return -1; }
    return strcasecmp($a, $b);
});
$locations = (isset($info['locations']) && is_array($info['locations'])) ? $info['locations'] : array();

// Sections that still hold widgets but which the active theme no longer declares.
// Switching theme (or disabling a plugin that registered an area) does not delete
// the widgets — it just leaves them with nowhere to render, silently. They are
// listed after the live sections, marked unavailable, so they can be moved or
// removed rather than quietly doing nothing.
$orphanLocations = array();
foreach (Widget::newInstance()->distinctLocations() as $stored) {
    if (!in_array($stored, $locations, true)) {
        $orphanLocations[] = $stored;
    }
}
?>
<?php if ($locations === array()) { ?>
    <div class="builder-empty"><?php _e('The current theme does not declare any widget sections.'); ?></div>
<?php } else { ?>
<div class="widgets-builder">
    <div class="widgets-builder-grid">

        <!-- LEFT: the theme's sections -->
        <div class="widgets-col">
            <div class="col-head">
                <h3 class="col-title"><?php _e('Sections'); ?></h3>
            </div>
            <p class="col-hint"><?php _e('Each section is a place your theme renders widgets. Drag a type in from the right, and drag widgets to reorder them or move them between sections.'); ?></p>

            <?php foreach (array_merge($locations, $orphanLocations) as $location) {
                $isOrphan = in_array($location, $orphanLocations, true);
                $widgets  = Widget::newInstance()->findByLocation($location); ?>
                <div class="form-card widget-section<?php echo $isOrphan ? ' is-unavailable' : ''; ?>"
                     data-location="<?php echo osc_esc_html($location); ?>"<?php echo $isOrphan ? ' data-unavailable="1"' : ''; ?>>
                    <div class="form-card-head">
                        <button type="button" class="form-card-toggle" aria-expanded="true"
                                aria-label="<?php echo osc_esc_html(__('Collapse or expand this section')); ?>"><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
                        <div class="form-card-heading">
                            <span class="form-card-title"><?php echo osc_esc_html($location); ?></span>
                            <?php if ($isOrphan) { ?>
                                <span class="form-card-cats is-unattached">
                                    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                                    <?php _e('Your theme no longer has this section — these widgets are not shown on the site. Move them somewhere else, or delete them.'); ?>
                                </span>
                            <?php } else { ?>
                                <span class="form-card-cats"><span class="widget-count"><?php
                                    printf(osc_esc_html(_n('%d widget', '%d widgets', count($widgets))), count($widgets)); ?></span></span>
                            <?php } ?>
                        </div>
                        <span class="form-card-actions">
                            <span class="form-card-status" role="status" aria-live="polite"></span>
                        </span>
                    </div>

                    <ul class="widget-list js-widget-sortable" data-location="<?php echo osc_esc_html($location); ?>">
                        <?php foreach ($widgets as $w) {
                            $wid   = (int)$w['pk_i_id'];
                            $allTypes = osc_widget_types();
                            $type     = (!empty($w['s_type']) && isset($allTypes[$w['s_type']]))
                                ? $allTypes[$w['s_type']]
                                : null;
                            $label = $w['s_description'] !== '' ? $w['s_description'] : sprintf(__('Widget %d'), $wid); ?>
                            <li class="field-chip widget-row" data-widget-id="<?php echo $wid; ?>">
                                <div class="field-chip-row">
                                    <button type="button" class="chip-grip widget-drag-handle" draggable="true" tabindex="0"
                                            title="<?php echo osc_esc_html(__('Drag to reorder or move, or focus and use the arrow keys')); ?>"
                                            aria-label="<?php echo osc_esc_html(sprintf(__('Reorder %s'), $label)); ?>"><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>
                                    <span class="chip-name"><?php echo osc_esc_html($label); ?></span>
                                    <span class="chip-type"><?php echo osc_esc_html($type !== null ? $type['label'] : __('Custom HTML')); ?></span>
                                    <span class="chip-actions">
                                        <button type="button" class="chip-btn js-widget-edit"
                                                data-widget-id="<?php echo $wid; ?>"
                                                data-location="<?php echo osc_esc_html($location); ?>"
                                                title="<?php echo osc_esc_html(__('Edit widget')); ?>"><i class="bi bi-pencil-fill" aria-hidden="true"></i></button>
                                        <button type="button" class="chip-btn chip-danger"
                                                onclick="return delete_dialog('<?php echo $wid; ?>');"
                                                title="<?php echo osc_esc_html(__('Delete widget')); ?>"><i class="bi bi-trash-fill" aria-hidden="true"></i></button>
                                    </span>
                                </div>
                                <div class="field-chip-editor edit" hidden></div>
                            </li>
                        <?php } ?>
                    </ul>
                    <?php if (!$isOrphan) { ?>
                        <div class="form-empty js-widget-empty"<?php echo count($widgets) > 0 ? ' style="display:none"' : ''; ?>><?php
                            _e('Drop a widget type here'); ?></div>
                    <?php } ?>
                    <div class="widget-reorder-error alert alert-danger py-1 px-2 small d-none" role="alert"></div>
                </div>
            <?php } ?>
        </div>

        <!-- RIGHT: available widget types, by source -->
        <div class="widgets-palette-col">
            <div class="col-head">
                <h3 class="col-title"><?php _e('Available widgets'); ?></h3>
            </div>
            <p class="col-hint"><?php _e('Drag a type into a section on the left to add it there. You can place the same type as many times as you like.'); ?></p>

            <?php foreach ($paletteTypes as $group => $types) { ?>
                <div class="palette-group">
                    <h4 class="palette-group-title"><?php echo osc_esc_html(__($group)); ?></h4>
                    <ul class="palette-list js-widget-palette">
                        <?php foreach ($types as $typeId => $typeSpec) { ?>
                            <li class="field-chip widget-type" data-type-id="<?php echo osc_esc_html($typeId); ?>">
                                <div class="field-chip-row">
                                    <button type="button" class="chip-grip" draggable="true" tabindex="-1"
                                            title="<?php echo osc_esc_html(__('Drag into a section')); ?>" aria-hidden="true"><i class="bi bi-grip-vertical"></i></button>
                                    <span class="chip-name"><?php echo osc_esc_html($typeSpec['label']); ?></span>
                                    <span class="chip-actions">
                                        <button type="button" class="chip-btn js-widget-place"
                                                data-type-id="<?php echo osc_esc_html($typeId); ?>"
                                                aria-haspopup="true" aria-expanded="false"
                                                title="<?php echo osc_esc_html(__('Add to a section')); ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                                    </span>
                                </div>
                                <?php if ($typeSpec['description'] !== '') { ?>
                                    <p class="palette-type-desc"><?php echo osc_esc_html($typeSpec['description']); ?></p>
                                <?php } ?>
                                <div class="cf-add-menu js-place-menu" role="menu" hidden></div>
                            </li>
                        <?php } ?>
                    </ul>
                </div>
            <?php } ?>
        </div>

    </div>
</div>
<?php } ?>
</div>
</div>
<dialog id="deleteModal" class="osc-dialog osc-dialog-danger">
    <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
        <input type="hidden" name="page" value="appearance"/>
        <input type="hidden" name="action" value="delete_widget"/>
        <input type="hidden" name="id" value=""/>
        <div class="osc-dialog-body">
            <p class="osc-dialog-title">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo __('Delete widget'); ?>
            </p>
            <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this widget?'); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="deleteSubmit" class="btn btn-danger btn-sm" type="submit"><?php echo __('Delete'); ?></button>
        </div>
    </form>
</dialog>
<script type="text/javascript">
    function delete_dialog(id) {
        var deleteModal = document.getElementById('deleteModal');
        var input = deleteModal.querySelector("input[name='id']");
        if (input) { input.value = id; }
        deleteModal.showModal();
        return false;
    }
</script>
<style>
    .widget-drag-cell {
        width: 1%;
        white-space: nowrap;
        vertical-align: middle;
    }

    .widget-drag-handle {
        display: inline-flex;
        align-items: center;
        cursor: grab;
        padding: 0.25rem;
        border-radius: 0.25rem;
    }

    .widget-drag-handle:focus-visible {
        outline: 2px solid var(--bs-primary);
        outline-offset: 1px;
    }

    .widget-order-buttons {
        display: inline-flex;
        flex-direction: column;
        vertical-align: middle;
        margin-left: 0.25rem;
    }

    .widget-order-buttons .btn {
        line-height: 1;
    }

    .widget-row.widget-row-dragging {
        opacity: 0.5;
    }
</style>
<script type="text/javascript">
(function () {
    var ADMIN = '<?php echo osc_admin_base_url(true); ?>';
    var CSRF  = '<?php echo osc_csrf_token_url(); ?>';
    var TXT   = {
        saving:  '<?php echo osc_esc_js(__('Saving…')); ?>',
        saved:   '<?php echo osc_esc_js(__('Saved')); ?>',
        failed:  '<?php echo osc_esc_js(__('Could not save the new order. Reload and try again.')); ?>',
        loading: '<?php echo osc_esc_js(__('Loading…')); ?>',
        moved:   '<?php echo osc_esc_js(__('Moved to position %1$d of %2$d in %3$s.')); ?>',
        pick:    '<?php echo osc_esc_js(__('Add to which section?')); ?>'
    };

    function announce(msg) {
        var live = document.getElementById('widgets-live');
        if (live) { live.textContent = msg; }
    }

    function sectionOf(el) { return el.closest('.widget-section'); }
    function listOf(section) { return section.querySelector('.js-widget-sortable'); }
    function idsOf(list) {
        return Array.prototype.map.call(list.querySelectorAll(':scope > .widget-row'),
            function (li) { return li.getAttribute('data-widget-id'); });
    }

    function setStatus(section, state) {
        var el = section.querySelector('.form-card-status');
        if (!el) { return; }
        if (el._t) { clearTimeout(el._t); el._t = null; }
        el.classList.remove('is-saving', 'is-saved');
        if (state === 'saving') { el.textContent = TXT.saving; el.classList.add('is-saving'); }
        else if (state === 'saved') {
            el.textContent = TXT.saved; el.classList.add('is-saved');
            el._t = setTimeout(function () { el.classList.remove('is-saved'); el.textContent = ''; }, 2500);
        } else { el.textContent = ''; }
    }

    function showError(section) {
        var box = section.querySelector('.widget-reorder-error');
        if (box) { box.textContent = TXT.failed; box.classList.remove('d-none'); }
    }
    function clearError(section) {
        var box = section.querySelector('.widget-reorder-error');
        if (box) { box.classList.add('d-none'); }
    }

    function refreshSection(section) {
        var list  = listOf(section);
        var count = list.querySelectorAll(':scope > .widget-row').length;
        var empty = section.querySelector('.js-widget-empty');
        if (empty) { empty.style.display = count > 0 ? 'none' : ''; }
        var badge = section.querySelector('.widget-count');
        if (badge) {
            badge.textContent = count === 1
                ? '<?php echo osc_esc_js(__('1 widget')); ?>'
                : count + ' <?php echo osc_esc_js(__('widgets')); ?>';
        }
    }

    // Persist the order of a section. `movedId` is set when the widget arrived from
    // another section, which also has to change its location — a plain reorder
    // deliberately refuses ids that do not already live here.
    function persist(section, movedId) {
        var list = listOf(section);
        var body = new URLSearchParams();
        body.set('location', section.getAttribute('data-location') || '');
        idsOf(list).forEach(function (id) { body.append('ids[]', id); });
        var action = 'reorder_widgets_post';
        if (movedId) { body.set('id', movedId); action = 'widget_move_post'; }

        setStatus(section, 'saving');
        fetch(ADMIN + '?page=appearance&action=' + action + '&' + CSRF, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body
        }).then(function (r) { return r.json(); }).then(function (o) {
            if (o && o.error === 0) { clearError(section); setStatus(section, 'saved'); }
            else { showError(section); setStatus(section, ''); }
        }).catch(function () { showError(section); setStatus(section, ''); });
    }

    // ---- Inline editor (opens in the widget's own row) ----------------------
    function closeEditors() {
        document.querySelectorAll('.widget-row.is-editing').forEach(function (row) {
            row.classList.remove('is-editing');
            var slot = row.querySelector('.field-chip-editor');
            if (slot) { slot.hidden = true; slot.innerHTML = ''; }
        });
    }
    window.closeWidgetEditor = closeEditors;

    function openEditor(row, url) {
        var already = row.classList.contains('is-editing');
        closeEditors();
        if (already) { return; }
        row.classList.add('is-editing');
        var slot = row.querySelector('.field-chip-editor');
        slot.hidden = false;
        slot.innerHTML = '<p class="cf-editor-loading">' + TXT.loading + '</p>';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                slot.innerHTML = html;
                slot.querySelectorAll('script').forEach(function (old) {
                    var sc = document.createElement('script');
                    if (old.src) { sc.src = old.src; } else { sc.textContent = old.textContent; }
                    old.parentNode.replaceChild(sc, old);
                });
                slot.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                var first = slot.querySelector('input:not([type=hidden]), select, textarea');
                if (first) { first.focus({ preventScroll: true }); }
            });
    }

    function editUrl(id, location) {
        return ADMIN + '?page=appearance&action=edit_widget&inline=1&id=' + encodeURIComponent(id)
            + '&location=' + encodeURIComponent(location);
    }

    // ---- Creating a widget from a type -------------------------------------
    function createIn(section, typeId, index) {
        var body = new URLSearchParams();
        body.set('location', section.getAttribute('data-location') || '');
        body.set('s_type', typeId);
        setStatus(section, 'saving');
        return fetch(ADMIN + '?page=appearance&action=widget_create_post&' + CSRF, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body
        }).then(function (r) { return r.json(); }).then(function (o) {
            if (!o || o.error !== 0) { showError(section); setStatus(section, ''); return null; }
            var list = listOf(section);
            var li = document.createElement('li');
            li.className = 'field-chip widget-row';
            li.setAttribute('data-widget-id', o.id);
            li.innerHTML = ''
                + '<div class="field-chip-row">'
                + '  <button type="button" class="chip-grip widget-drag-handle" draggable="true" tabindex="0"><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>'
                + '  <span class="chip-name"></span>'
                + '  <span class="chip-type"></span>'
                + '  <span class="chip-actions">'
                + '    <button type="button" class="chip-btn js-widget-edit" data-widget-id="' + o.id + '" data-location="' + (section.getAttribute('data-location') || '') + '"><i class="bi bi-pencil-fill"></i></button>'
                + '    <button type="button" class="chip-btn chip-danger" onclick="return delete_dialog(\'' + o.id + '\');"><i class="bi bi-trash-fill"></i></button>'
                + '  </span>'
                + '</div>'
                + '<div class="field-chip-editor edit" hidden></div>';
            li.querySelector('.chip-name').textContent = o.description;
            li.querySelector('.chip-type').textContent = o.type_label || o.description;
            var ref = (typeof index === 'number') ? list.children[index] : null;
            list.insertBefore(li, ref || null);
            refreshSection(section);
            persist(section, o.id);
            // A fresh widget has no configuration yet, so go straight to its editor.
            openEditor(li, editUrl(o.id, section.getAttribute('data-location') || ''));
            return li;
        });
    }

    // ---- Drag and drop ------------------------------------------------------
    // sortablejs is enqueued into the footer, so it does not exist yet while this
    // script is parsed — initialising here directly silently did nothing.
    function ready(fn) {
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); }
        else { fn(); }
    }

    ready(function initDragAndDrop() {
    if (typeof Sortable !== 'undefined') {
        // The palette is a clone source of TYPES; dropping one creates a widget.
        document.querySelectorAll('.js-widget-palette').forEach(function (pal) {
            new Sortable(pal, {
                group: { name: 'widgets', pull: 'clone', put: false },
                sort: false, handle: '.chip-grip', animation: 150, ghostClass: 'chip-ghost'
            });
        });

        document.querySelectorAll('.js-widget-sortable').forEach(function (list) {
            var section = sectionOf(list);
            if (section.hasAttribute('data-unavailable')) {
                // A section the theme no longer has: you may drag widgets OUT of it,
                // but putting more in would be adding to something invisible.
                new Sortable(list, {
                    group: { name: 'widgets', pull: true, put: false },
                    handle: '.chip-grip', animation: 150, ghostClass: 'chip-ghost',
                    onStart: closeEditors,
                    onRemove: function (evt) { refreshSection(section); persist(section); }
                });
                return;
            }
            new Sortable(list, {
                group: 'widgets', handle: '.chip-grip', animation: 150, ghostClass: 'chip-ghost',
                onStart: closeEditors,
                onAdd: function (evt) {
                    var el = evt.item;
                    if (el.classList.contains('widget-type')) {
                        // A type was dropped: replace the clone with a real widget.
                        var index = Array.prototype.indexOf.call(list.children, el);
                        el.remove();
                        createIn(section, el.getAttribute('data-type-id'), index);
                        return;
                    }
                    refreshSection(section);
                    persist(section, el.getAttribute('data-widget-id'));
                },
                onRemove: function () { refreshSection(section); persist(section); },
                onUpdate: function () { persist(section); }
            });
        });
    }
    });

    // ---- Clicks --------------------------------------------------------------
    document.addEventListener('click', function (e) {
        var edit = e.target.closest('.js-widget-edit');
        if (edit) {
            e.preventDefault();
            openEditor(edit.closest('.widget-row'), editUrl(edit.dataset.widgetId, edit.dataset.location));
            return;
        }

        // Keyboard/touch path for placing a type without dragging.
        var place = e.target.closest('.js-widget-place');
        if (place) {
            e.preventDefault();
            var chip = place.closest('.widget-type');
            var menu = chip.querySelector('.js-place-menu');
            document.querySelectorAll('.js-place-menu').forEach(function (m) { if (m !== menu) { m.hidden = true; } });
            if (!menu.hidden) { menu.hidden = true; place.setAttribute('aria-expanded', 'false'); return; }
            menu.innerHTML = '';
            document.querySelectorAll('.widget-section:not([data-unavailable])').forEach(function (sec) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'cf-add-item';
                item.setAttribute('role', 'menuitem');
                item.textContent = sec.getAttribute('data-location');
                item.addEventListener('click', function () {
                    menu.hidden = true;
                    place.setAttribute('aria-expanded', 'false');
                    createIn(sec, chip.getAttribute('data-type-id'));
                });
                menu.appendChild(item);
            });
            menu.hidden = false;
            place.setAttribute('aria-expanded', 'true');
            var first = menu.querySelector('.cf-add-item');
            if (first) { first.focus(); }
            return;
        }
        if (!e.target.closest('.js-place-menu')) {
            document.querySelectorAll('.js-place-menu').forEach(function (m) { m.hidden = true; });
            document.querySelectorAll('.js-widget-place').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        }

        // Collapse / expand a section.
        var toggle = e.target.closest('.form-card-toggle');
        if (toggle) {
            var sec = toggle.closest('.widget-section');
            var collapsed = !sec.classList.contains('is-collapsed');
            sec.classList.toggle('is-collapsed', collapsed);
            toggle.setAttribute('aria-expanded', String(!collapsed));
            try {
                var store = JSON.parse(localStorage.getItem('widget_collapsed_sections') || '[]');
                var key = sec.getAttribute('data-location');
                var i = store.indexOf(key);
                if (collapsed && i === -1) { store.push(key); }
                if (!collapsed && i !== -1) { store.splice(i, 1); }
                localStorage.setItem('widget_collapsed_sections', JSON.stringify(store));
            } catch (err) { /* private mode: collapsing still works, it just will not persist */ }
        }
    });

    // ---- Keyboard reordering (kept from the table version) -------------------
    document.addEventListener('keydown', function (e) {
        var grip = e.target.closest('.chip-grip');
        if (!grip) { return; }
        var row = grip.closest('.widget-row');
        var list = row ? row.closest('.js-widget-sortable') : null;
        if (!list) { return; }
        var moved = false;
        if (e.key === 'ArrowUp' && row.previousElementSibling) { list.insertBefore(row, row.previousElementSibling); moved = true; }
        else if (e.key === 'ArrowDown' && row.nextElementSibling) { list.insertBefore(row.nextElementSibling, row); moved = true; }
        else if (e.key === 'Home' && row !== list.firstElementChild) { list.insertBefore(row, list.firstElementChild); moved = true; }
        else if (e.key === 'End' && row !== list.lastElementChild) { list.appendChild(row); moved = true; }
        else { return; }
        e.preventDefault();
        if (!moved) { return; }
        closeEditors();
        grip.focus();
        var section = sectionOf(list);
        var rows = list.querySelectorAll(':scope > .widget-row');
        var pos = Array.prototype.indexOf.call(rows, row) + 1;
        announce(TXT.moved.replace('%1$d', pos).replace('%2$d', rows.length)
            .replace('%3$s', section.getAttribute('data-location')));
        persist(section);
    });

    // Restore collapsed sections.
    document.addEventListener('DOMContentLoaded', function () {
        var store = [];
        try { store = JSON.parse(localStorage.getItem('widget_collapsed_sections') || '[]'); } catch (err) { store = []; }
        document.querySelectorAll('.widget-section').forEach(function (sec) {
            if (store.indexOf(sec.getAttribute('data-location')) !== -1) {
                sec.classList.add('is-collapsed');
                var t = sec.querySelector('.form-card-toggle');
                if (t) { t.setAttribute('aria-expanded', 'false'); }
            }
        });
    });
})();
</script>
<div id="widgets-live" class="visually-hidden" role="status" aria-live="polite"></div>
<div class="row">
    <div class="col-12">
        <div class="row-wrapper">
            <?php osc_current_admin_theme_path('parts/media-picker.php'); ?>
            <?php osc_current_admin_theme_path('parts/footer.php'); ?>
