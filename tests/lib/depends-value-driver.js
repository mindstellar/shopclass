/*
 * Drives ui-osc.js over the 'byvalue' page for tests/admin-form-depends-translate.php:
 * rows that follow one value of a select or radio master, chained under a checkbox.
 */
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('field-b_wm');
    var select = document.getElementById('field-wm_type');
    var position = document.getElementById('field-wm_pos');
    var names = ['s_wm_text', 's_wm_image', 's_wm_any', 's_font', 'wm_pos', 's_offset', 's_card', 's_amp', 's_zero'];
    if (!toggle || !select || !position) {
        return;
    }

    function row(name) {
        var control = document.querySelector('[name="' + name + '"]');
        return control ? control.closest('.form-row') : null;
    }

    function snap() {
        var shown = [];
        names.forEach(function (name) {
            var r = row(name);
            if (r && !r.hidden) {
                shown.push(name);
            }
        });
        shown.sort();
        return {
            shown: shown,
            textRequired: document.querySelector('[name="s_wm_text"]').required,
            cardRequired: document.querySelector('[name="s_card"]').required
        };
    }

    function pick(control, value) {
        control.value = value;
        control.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function tick(checked) {
        toggle.checked = checked;
        toggle.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function plan(value) {
        var radio = document.querySelector('input[name="r_plan"][value="' + value + '"]');
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
    }

    var out = [snap()];
    pick(select, 'text');
    out.push(snap());
    pick(position, 'custom');
    out.push(snap());
    tick(false);
    out.push(snap());
    tick(true);
    out.push(snap());
    pick(select, 'image');
    out.push(snap());
    plan('paid');
    out.push(snap());
    plan('a&b');
    out.push(snap());
    plan('0');
    out.push(snap());

    var calls = 0;
    var real = window.oscSyncDepends;
    window.oscSyncDepends = function (root) {
        calls++;

        return real(root);
    };
    var card = document.querySelector('[name="s_card"]');
    card.dispatchEvent(new Event('input', { bubbles: true }));
    var afterDependent = calls;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    var afterMaster = calls;
    window.oscSyncDepends = real;
    out.push({ afterDependent: afterDependent, afterMaster: afterMaster });

    document.getElementById('osc-out').textContent = btoa(JSON.stringify(out));
});
