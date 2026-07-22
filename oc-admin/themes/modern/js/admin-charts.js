/*
 * Shared theming for the admin's Google Charts (stats pages). The charts used to
 * hardcode Bootstrap blue on a white fill, so they were off-brand and unreadable in
 * dark mode. This reads the live theme tokens instead, paints on a transparent
 * surface, and redraws every registered chart when the admin flips the theme.
 */
(function () {
    'use strict';

    function tok(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v && v.trim()) || fallback;
    }

    // Merge chart-specific overrides onto a themed base. Series default to bronze +
    // bronze tint; axes, gridlines and legend take the muted/rule tokens.
    window.oscChartOpts = function (overrides) {
        var muted = tok('--osc-ink-muted', '#67635d');
        var rule = tok('--osc-rule', '#e0dcd4');
        var base = {
            colors: [tok('--osc-bronze', '#8f5d00'), tok('--osc-bronze-tint', '#fbf0e2')],
            backgroundColor: { fill: 'transparent' },
            areaOpacity: 0.14,
            lineWidth: 2,
            legend: { textStyle: { color: muted } },
            titleTextStyle: { color: muted, bold: false },
            chartArea: { left: 40, top: 16, width: '88%', height: '74%' },
            hAxis: {
                textStyle: { color: muted, fontSize: 10 },
                gridlines: { color: rule },
                baselineColor: rule,
                slantedText: false
            },
            vAxis: {
                minValue: 0,
                textStyle: { color: muted },
                gridlines: { color: rule },
                baselineColor: rule
            }
        };
        if (overrides) {
            for (var k in overrides) {
                if (Object.prototype.hasOwnProperty.call(overrides, k)) {
                    base[k] = overrides[k];
                }
            }
        }

        return base;
    };

    // Pie/geo charts don't use axes, but they still default to a white fill and
    // dark legend/label text — unreadable in dark mode. Paint on a transparent
    // surface and pull legend/title/slice-label colours from the live theme tokens.
    window.oscPieOpts = function (overrides) {
        var muted = tok('--osc-ink-muted', '#67635d');
        var base = {
            backgroundColor: { fill: 'transparent' },
            legend: { textStyle: { color: muted } },
            titleTextStyle: { color: muted, bold: false },
            pieSliceTextStyle: { color: tok('--osc-bench', '#ffffff') },
            chartArea: { left: 8, top: 16, width: '92%', height: '78%' }
        };
        if (overrides) {
            for (var k in overrides) {
                if (Object.prototype.hasOwnProperty.call(overrides, k)) {
                    base[k] = overrides[k];
                }
            }
        }

        return base;
    };

    // Register a (re)draw callback; the first registration wires a single observer
    // that re-runs them all when data-bs-theme changes, so charts follow the theme.
    var redraws = [];
    window.oscChartAutoRedraw = function (fn) {
        redraws.push(fn);
        if (redraws.length === 1) {
            new MutationObserver(function (muts) {
                for (var i = 0; i < muts.length; i++) {
                    if (muts[i].attributeName === 'data-bs-theme') {
                        redraws.forEach(function (f) {
                            try {
                                f();
                            } catch (e) {
                                /* a broken chart must not stop the others */
                            }
                        });
                        break;
                    }
                }
            }).observe(document.documentElement, { attributes: true });
        }
    };
})();
