/*
 * Drives the shared tab widget over a rendered translated field for
 * tests/admin-form-depends-translate.php: which locale panels are on screen at load, and
 * which after the second tab of the first field is clicked.
 */
document.addEventListener('DOMContentLoaded', function () {
    function visible() {
        return Array.prototype.slice.call(document.querySelectorAll('.field-translate-panel'))
            .filter(function (panel) { return !panel.hidden; })
            .map(function (panel) { return panel.id; });
    }

    var tabs = document.querySelectorAll('.field-translate .osc-tab a');
    var out = { atLoad: visible(), tabCount: tabs.length };

    if (tabs.length > 1) {
        tabs[1].click();
    }
    out.afterClick = visible();

    document.getElementById('osc-out').textContent = btoa(JSON.stringify(out));
});
