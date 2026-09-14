/*
 * Drives the dirty-tracking action row of a declared settings page for
 * tests/admin-form-depends-translate.php. Registered after the script under test, so its
 * DOMContentLoaded handler has already taken the baseline by the time the first reading is.
 */
document.addEventListener('DOMContentLoaded', function () {
    var bar = document.querySelector('[data-osc-dirty-bar]');
    var always = document.getElementById('field-s_always');
    var master = document.getElementById('field-b_enabled');
    if (!bar || !always || !master) {
        return;
    }

    function snap() {
        var status = bar.querySelector('.form-actions-status');

        return {
            dirty: bar.classList.contains('is-dirty'),
            text: status ? status.textContent : null,
            position: window.getComputedStyle(bar).position
        };
    }

    function type(el, value) {
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    var out = [snap()];

    type(always, 'changed');
    out.push(snap());

    master.checked = true;
    master.dispatchEvent(new Event('change', { bubbles: true }));
    out.push(snap());

    // Typing the original value back is not a change: the count is against the baseline,
    // not a tally of events.
    type(always, '');
    master.checked = false;
    master.dispatchEvent(new Event('change', { bubbles: true }));
    out.push(snap());

    document.getElementById('osc-out').textContent = btoa(JSON.stringify(out));
});
