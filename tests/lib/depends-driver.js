/*
 * Drives ui-osc.js over a rendered settings page for tests/admin-form-depends-translate.php.
 * Registered after the script under test, so its own DOMContentLoaded handler has already
 * run by the time the first reading is taken.
 */
document.addEventListener('DOMContentLoaded', function () {
    var row = document.querySelector('[data-osc-depends]');
    var master = document.getElementById('field-b_enabled');
    var control = document.getElementById('field-s_key');
    if (!row || !master || !control) {
        return;
    }

    function snap() {
        return {
            hidden: row.hidden,
            display: window.getComputedStyle(row).display,
            required: control.required
        };
    }

    function toggle(checked) {
        master.checked = checked;
        master.dispatchEvent(new Event('change', { bubbles: true }));
    }

    var out = [snap()];
    toggle(true);
    out.push(snap());
    toggle(false);
    out.push(snap());

    // Typing in a box no row follows cannot flip anything, so the sweep over every
    // dependent row must not run for it. Counted rather than timed: the handlers resolve
    // the global at call time, so replacing it here sees every call they make.
    var calls = 0;
    var real = window.oscSyncDepends;
    window.oscSyncDepends = function (root) {
        calls++;

        return real(root);
    };
    toggle(true);
    calls = 0;
    control.value = 'typed';
    control.dispatchEvent(new Event('input', { bubbles: true }));
    var afterDependent = calls;
    master.dispatchEvent(new Event('input', { bubbles: true }));
    var afterMaster = calls;
    window.oscSyncDepends = real;

    out.push({ afterDependent: afterDependent, afterMaster: afterMaster, hidden: row.hidden });

    document.getElementById('osc-out').textContent = btoa(JSON.stringify(out));
});
