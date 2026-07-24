(function () {
    'use strict';

    // ── Colour helpers ───────────────────────────────────────────────────────

    function hexToRgb(hex) {
        hex = hex.replace(/^#/, '').trim();
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        if (!/^[0-9a-fA-F]{6}$/.test(hex)) return null;
        return {
            r: parseInt(hex.slice(0,2), 16),
            g: parseInt(hex.slice(2,4), 16),
            b: parseInt(hex.slice(4,6), 16),
        };
    }

    function rgbToHex(r, g, b) {
        return [r, g, b].map(function(v) {
            return ('0' + Math.round(v).toString(16)).slice(-2);
        }).join('');
    }

    function rgbToHsl(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b);
        var h, s, l = (max + min) / 2;
        if (max === min) {
            h = s = 0;
        } else {
            var d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            switch (max) {
                case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                case g: h = (b - r) / d + 2; break;
                default: h = (r - g) / d + 4;
            }
            h /= 6;
        }
        return { h: h * 360, s: s * 100, l: l * 100 };
    }

    function hslToRgb(h, s, l) {
        h /= 360; s /= 100; l /= 100;
        var r, g, b;
        if (s === 0) {
            r = g = b = l;
        } else {
            var hue2rgb = function(p, q, t) {
                if (t < 0) t += 1;
                if (t > 1) t -= 1;
                if (t < 1/6) return p + (q - p) * 6 * t;
                if (t < 1/2) return q;
                if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
                return p;
            };
            var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
            var p = 2 * l - q;
            r = hue2rgb(p, q, h + 1/3);
            g = hue2rgb(p, q, h);
            b = hue2rgb(p, q, h - 1/3);
        }
        return { r: Math.round(r * 255), g: Math.round(g * 255), b: Math.round(b * 255) };
    }

    // WCAG 2.1 relative luminance
    function linearise(v) {
        v /= 255;
        return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    }

    function luminance(r, g, b) {
        return 0.2126 * linearise(r) + 0.7152 * linearise(g) + 0.0722 * linearise(b);
    }

    function contrastRatio(rgb1, rgb2) {
        var l1 = luminance(rgb1.r, rgb1.g, rgb1.b);
        var l2 = luminance(rgb2.r, rgb2.g, rgb2.b);
        var lighter = Math.max(l1, l2);
        var darker  = Math.min(l1, l2);
        return (lighter + 0.05) / (darker + 0.05);
    }

    // ── State ────────────────────────────────────────────────────────────────

    var state = {
        fg: hexToRgb('1a1a1a'),
        bg: hexToRgb('ffffff'),
    };

    // ── DOM refs ─────────────────────────────────────────────────────────────

    var fgHex      = document.getElementById('accessibility-audit-cc-fg-hex');
    var fgPicker   = document.getElementById('accessibility-audit-cc-fg-picker');
    var fgRgb      = document.getElementById('accessibility-audit-cc-fg-rgb');
    var fgLightness = document.getElementById('accessibility-audit-cc-fg-lightness');
    var fgSwatch   = document.getElementById('accessibility-audit-cc-fg-swatch');

    var bgHex      = document.getElementById('accessibility-audit-cc-bg-hex');
    var bgPicker   = document.getElementById('accessibility-audit-cc-bg-picker');
    var bgRgb      = document.getElementById('accessibility-audit-cc-bg-rgb');
    var bgLightness = document.getElementById('accessibility-audit-cc-bg-lightness');
    var bgSwatch   = document.getElementById('accessibility-audit-cc-bg-swatch');

    var preview        = document.getElementById('accessibility-audit-cc-preview');
    var previewNormal  = document.getElementById('accessibility-audit-cc-preview-normal');
    var previewLarge   = document.getElementById('accessibility-audit-cc-preview-large');
    var ratioEl        = document.getElementById('accessibility-audit-cc-ratio');

    var aaNormal  = document.getElementById('accessibility-audit-cc-aa-normal');
    var aaLarge   = document.getElementById('accessibility-audit-cc-aa-large');
    var aaUi      = document.getElementById('accessibility-audit-cc-aa-ui');
    var aaaNormal = document.getElementById('accessibility-audit-cc-aaa-normal');
    var aaaLarge  = document.getElementById('accessibility-audit-cc-aaa-large');

    var swapBtn = document.getElementById('accessibility-audit-cc-swap');

    // The tabs render one tool at a time, so the analyser markup may be absent.
    // Guard on it: the element lookups above are then harmless nulls, and the
    // wiring below only runs when the analyser is actually on the page.
    if (fgHex && bgHex) {

    // ── Rendering ────────────────────────────────────────────────────────────

    function passCell(pass) {
        // accessibility-audit-badge-pass / accessibility-audit-badge-fail are the loaded badge styles (ui.css):
        // a green or red pill. Driven per-result so a failing combination
        // reads red, not a green pill with "Fail" in it.
        var el = document.createElement('span');
        el.className = pass ? 'accessibility-audit-badge-pass' : 'accessibility-audit-badge-fail';
        el.textContent = pass ? 'Pass' : 'Fail';
        return el;
    }

    function setCell(el, pass) {
        // The AAA row is only rendered when the site targets AAA, so its cells
        // legitimately may not exist.
        if (!el) {
            return;
        }
        el.innerHTML = '';
        el.appendChild(passCell(pass));
    }

    function sliderGradient(hex) {
        var rgb = hexToRgb(hex);
        if (!rgb) return '';
        var hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
        var h = Math.round(hsl.h), s = Math.round(hsl.s);
        return 'linear-gradient(to right, hsl(' + h + ',' + s + '%,0%), hsl(' + h + ',' + s + '%,50%), hsl(' + h + ',' + s + '%,100%))';
    }

    function update() {
        var fg = state.fg, bg = state.bg;
        if (!fg || !bg) return;

        var fgColor = '#' + rgbToHex(fg.r, fg.g, fg.b);
        var bgColor = '#' + rgbToHex(bg.r, bg.g, bg.b);

        // Preview
        preview.style.backgroundColor = bgColor;
        previewNormal.style.color = fgColor;
        previewLarge.style.color  = fgColor;

        // Swatches
        fgSwatch.style.backgroundColor = fgColor;
        bgSwatch.style.backgroundColor = bgColor;

        // RGB labels
        fgRgb.textContent = fg.r + ', ' + fg.g + ', ' + fg.b;
        bgRgb.textContent = bg.r + ', ' + bg.g + ', ' + bg.b;

        // Slider gradients
        fgLightness.style.background = sliderGradient(fgColor);
        bgLightness.style.background = sliderGradient(bgColor);

        // Ratio: coloured so the headline number itself gives an at-a-glance
        // answer instead of requiring a look down at the table: green if it
        // clears AA for normal text (4.5:1), amber if it only clears the
        // large-text/UI-component threshold (3:1), red if it fails both.
        var ratio = contrastRatio(fg, bg);
        ratioEl.textContent = ratio.toFixed(2) + ' : 1';
        ratioEl.classList.remove('accessibility-audit-ratio--good', 'accessibility-audit-ratio--fair', 'accessibility-audit-ratio--poor');
        ratioEl.classList.add(ratio >= 4.5 ? 'accessibility-audit-ratio--good' : ratio >= 3.0 ? 'accessibility-audit-ratio--fair' : 'accessibility-audit-ratio--poor');

        // WCAG cells
        setCell(aaNormal,  ratio >= 4.5);
        setCell(aaLarge,   ratio >= 3.0);
        setCell(aaUi,      ratio >= 3.0);
        setCell(aaaNormal, ratio >= 7.0);
        setCell(aaaLarge,  ratio >= 4.5);
    }

    // ── Apply colour from hex string ─────────────────────────────────────────

    function applyFg(hex) {
        var rgb = hexToRgb(hex);
        if (!rgb) return;
        state.fg = rgb;
        fgHex.value    = hex.replace(/^#/, '').toLowerCase();
        fgPicker.value = '#' + hex.replace(/^#/, '');
        var hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
        fgLightness.value = Math.round(hsl.l);
        update();
    }

    function applyBg(hex) {
        var rgb = hexToRgb(hex);
        if (!rgb) return;
        state.bg = rgb;
        bgHex.value    = hex.replace(/^#/, '').toLowerCase();
        bgPicker.value = '#' + hex.replace(/^#/, '');
        var hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
        bgLightness.value = Math.round(hsl.l);
        update();
    }

    // ── Event listeners ──────────────────────────────────────────────────────

    // Foreground hex input
    fgHex.addEventListener('input', function() {
        var rgb = hexToRgb(this.value);
        if (!rgb) return;
        state.fg = rgb;
        fgPicker.value = '#' + this.value.replace(/^#/, '');
        var hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
        fgLightness.value = Math.round(hsl.l);
        update();
    });

    fgHex.addEventListener('blur', function() {
        var hex = this.value.replace(/^#/, '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        if (/^[0-9a-fA-F]{6}$/.test(hex)) this.value = hex.toLowerCase();
    });

    // Background hex input
    bgHex.addEventListener('input', function() {
        var rgb = hexToRgb(this.value);
        if (!rgb) return;
        state.bg = rgb;
        bgPicker.value = '#' + this.value.replace(/^#/, '');
        var hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
        bgLightness.value = Math.round(hsl.l);
        update();
    });

    bgHex.addEventListener('blur', function() {
        var hex = this.value.replace(/^#/, '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        if (/^[0-9a-fA-F]{6}$/.test(hex)) this.value = hex.toLowerCase();
    });

    // Foreground native colour picker
    fgPicker.addEventListener('input', function() {
        applyFg(this.value);
    });

    // Background native colour picker
    bgPicker.addEventListener('input', function() {
        applyBg(this.value);
    });

    // Foreground lightness slider
    fgLightness.addEventListener('input', function() {
        var rgb = state.fg;
        var hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
        var newRgb = hslToRgb(hsl.h, hsl.s, parseFloat(this.value));
        state.fg = newRgb;
        var hex = rgbToHex(newRgb.r, newRgb.g, newRgb.b);
        fgHex.value    = hex;
        fgPicker.value = '#' + hex;
        update();
    });

    // Background lightness slider
    bgLightness.addEventListener('input', function() {
        var rgb = state.bg;
        var hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
        var newRgb = hslToRgb(hsl.h, hsl.s, parseFloat(this.value));
        state.bg = newRgb;
        var hex = rgbToHex(newRgb.r, newRgb.g, newRgb.b);
        bgHex.value    = hex;
        bgPicker.value = '#' + hex;
        update();
    });

    // Swap button
    swapBtn.addEventListener('click', function() {
        var tmpFg = fgHex.value;
        var tmpBg = bgHex.value;
        applyFg(tmpBg);
        applyBg(tmpFg);
    });

    // ── Initial render ───────────────────────────────────────────────────────
    update();
    }

    // ══ Colour Blindness Simulator ═══════════════════════════════════════════
    // Reuses the hex helpers above. Dichromacy is approximated with the classic
    // simulation matrices applied in sRGB space: not clinically exact, but good
    // enough to catch a palette that leans on colour alone.

    (function initCvd() {
        var hex    = document.getElementById('accessibility-audit-cvd-hex');
        var picker = document.getElementById('accessibility-audit-cvd-picker');
        if (!hex || !picker) return;

        var MATRICES = {
            protanopia:   [0.567, 0.433, 0.000, 0.558, 0.442, 0.000, 0.000, 0.242, 0.758],
            deuteranopia: [0.625, 0.375, 0.000, 0.700, 0.300, 0.000, 0.000, 0.300, 0.700],
            tritanopia:   [0.950, 0.050, 0.000, 0.000, 0.433, 0.567, 0.000, 0.475, 0.525],
        };

        function clamp(v) { return v < 0 ? 0 : v > 255 ? 255 : v; }

        function simulate(rgb, m) {
            return {
                r: clamp(rgb.r * m[0] + rgb.g * m[1] + rgb.b * m[2]),
                g: clamp(rgb.r * m[3] + rgb.g * m[4] + rgb.b * m[5]),
                b: clamp(rgb.r * m[6] + rgb.g * m[7] + rgb.b * m[8]),
            };
        }

        function greyscale(rgb) {
            var y = 0.299 * rgb.r + 0.587 * rgb.g + 0.114 * rgb.b;
            return { r: y, g: y, b: y };
        }

        function paint(key, rgb) {
            var chip = document.getElementById('accessibility-audit-cvd-' + key);
            var lbl  = document.getElementById('accessibility-audit-cvd-' + key + '-hex');
            if (!chip) return;
            var h = rgbToHex(rgb.r, rgb.g, rgb.b);
            chip.style.backgroundColor = '#' + h;
            if (lbl) lbl.textContent = '#' + h;
        }

        function render(rgb) {
            if (!rgb) return;
            paint('normal', rgb);
            paint('protanopia', simulate(rgb, MATRICES.protanopia));
            paint('deuteranopia', simulate(rgb, MATRICES.deuteranopia));
            paint('tritanopia', simulate(rgb, MATRICES.tritanopia));
            paint('achromatopsia', greyscale(rgb));
        }

        hex.addEventListener('input', function () {
            var rgb = hexToRgb(this.value);
            if (!rgb) return;
            picker.value = '#' + this.value.replace(/^#/, '');
            render(rgb);
        });
        hex.addEventListener('blur', function () {
            var v = this.value.replace(/^#/, '');
            if (v.length === 3) v = v[0]+v[0]+v[1]+v[1]+v[2]+v[2];
            if (/^[0-9a-fA-F]{6}$/.test(v)) this.value = v.toLowerCase();
        });
        picker.addEventListener('input', function () {
            hex.value = this.value.replace(/^#/, '').toLowerCase();
            render(hexToRgb(this.value));
        });

        render(hexToRgb(hex.value));
    })();

    // ══ Contrast Grid ════════════════════════════════════════════════════════
    // Every colour-on-colour pairing in a palette, ratio + WCAG result. Each
    // cell is painted in its own combination, so a failing pair is literally
    // hard to read; the aria-label carries the real value for assistive tech.

    (function initGrid() {
        var table  = document.getElementById('accessibility-audit-cg-palette');
        var output = document.getElementById('accessibility-audit-cg-output');
        var status = document.getElementById('accessibility-audit-cg-status');
        if (!table || !output) return;

        var MAX = 6;

        // Read the palette from the editable table's colour cells: Craft's
        // color column stores each row's hex (no leading #) in a
        // `.color-input` text field. Skip blank/invalid rows, dedupe, cap at MAX.
        function readPalette() {
            var out = [], seen = {};
            table.querySelectorAll('.color-input').forEach(function (field) {
                var rgb = hexToRgb(field.value);
                if (!rgb) return;
                var h = rgbToHex(rgb.r, rgb.g, rgb.b);
                if (seen[h]) return;
                seen[h] = 1;
                out.push({ hex: h, rgb: rgb });
            });
            return out.slice(0, MAX);
        }

        /* WCAG 2.1 thresholds, in the order they are tested. `note` is the
           wording used in the legend under the grid. */
        var LEVELS = [
            { key: 'aaa',   min: 7,   label: 'AAA',      note: '\u2265 7:1' },
            { key: 'aa',    min: 4.5, label: 'AA',       note: '\u2265 4.5:1' },
            { key: 'large', min: 3,   label: 'AA Large', note: '\u2265 3:1 \u2014 large text and UI components only' },
            { key: 'fail',  min: 0,   label: 'Fail',     note: '< 3:1' },
        ];

        function levelFor(ratio) {
            for (var i = 0; i < LEVELS.length; i++) {
                if (ratio >= LEVELS[i].min) return LEVELS[i];
            }
            return LEVELS[LEVELS.length - 1];
        }

        /* Header cells stay white with a small swatch beside the hex rather
           than filled with colour: a filled label needs its own contrast
           workaround, and a pale swatch on white reads as a missing cell. */
        function swatchHead(c, scope) {
            var th = document.createElement('th');
            th.setAttribute('scope', scope);
            th.className = 'accessibility-audit-cg-head';

            /* The swatch and hex are laid out inside a wrapper, not on the
               <th> itself: `display: flex` on a table cell takes it out of
               the table layout. */
            var inner = document.createElement('span');
            inner.className = 'accessibility-audit-cg-head__inner';

            var chip = document.createElement('span');
            chip.className = 'accessibility-audit-cg-chip';
            chip.style.backgroundColor = '#' + c.hex;

            var label = document.createElement('span');
            label.className = 'accessibility-audit-cg-hex';
            label.textContent = '#' + c.hex;

            inner.appendChild(chip);
            inner.appendChild(label);
            th.appendChild(inner);
            return th;
        }

        function dataCell(rowC, colC) {
            var td = document.createElement('td');
            td.style.backgroundColor = '#' + colC.hex;

            if (rowC.hex === colC.hex) {
                /* A colour on itself is not a pairing anyone can use, so it is
                   struck through rather than given a meaningless 1:1. */
                td.className = 'accessibility-audit-cg-cell accessibility-audit-cg-cell--same';
                return td;
            }

            var ratio = contrastRatio(rowC.rgb, colC.rgb);
            var level = levelFor(ratio);

            td.className = 'accessibility-audit-cg-cell';
            td.style.color = '#' + rowC.hex;

            var num = document.createElement('b');
            num.textContent = ratio.toFixed(1);

            /* The badge keeps its own light background instead of inheriting
               the pairing's colours: on a failing cell the whole point is that
               the text is hard to read, and the verdict has to survive that. */
            var badge = document.createElement('span');
            badge.className = 'accessibility-audit-cg-badge accessibility-audit-cg-badge--' + level.key;
            badge.textContent = level.label;

            td.appendChild(num);
            td.appendChild(badge);
            td.setAttribute('aria-label', '#' + rowC.hex + ' text on #' + colC.hex
                + ' background: ' + ratio.toFixed(2) + ' to 1, ' + level.label);
            return td;
        }

        /* The legend earns its place: "AA Large" is not self-explanatory, and
           without the thresholds a reader cannot tell whether a number they are
           looking at is close to passing. */
        function legend() {
            var wrap = document.createElement('p');
            wrap.className = 'accessibility-audit-cg-legend';

            LEVELS.forEach(function (lvl) {
                var badge = document.createElement('span');
                badge.className = 'accessibility-audit-cg-badge accessibility-audit-cg-badge--' + lvl.key;
                badge.textContent = lvl.label;

                var note = document.createElement('span');
                note.className = 'accessibility-audit-cg-legend__note';
                note.textContent = lvl.note;

                var item = document.createElement('span');
                item.className = 'accessibility-audit-cg-legend__item';
                item.appendChild(badge);
                item.appendChild(note);
                wrap.appendChild(item);
            });

            return wrap;
        }

        function build() {
            var cols = readPalette();
            output.innerHTML = '';

            if (cols.length < 2) {
                var p = document.createElement('p');
                p.className = 'light accessibility-audit-cg-empty';
                p.textContent = 'Enter at least two valid hex colours to build the grid.';
                output.appendChild(p);
                if (status) status.textContent = 'Enter at least two valid hex colours to build the grid.';
                return;
            }

            var table = document.createElement('table');
            table.className = 'accessibility-audit-cg-table';

            var thead = document.createElement('thead');
            var hr = document.createElement('tr');
            var corner = document.createElement('th');
            corner.className = 'accessibility-audit-cg-corner';
            corner.textContent = 'Text \u2193 / BG \u2192';
            hr.appendChild(corner);
            cols.forEach(function (c) { hr.appendChild(swatchHead(c, 'col')); });
            thead.appendChild(hr);
            table.appendChild(thead);

            var tbody = document.createElement('tbody');
            cols.forEach(function (rowC) {
                var tr = document.createElement('tr');
                tr.appendChild(swatchHead(rowC, 'row'));
                cols.forEach(function (colC) { tr.appendChild(dataCell(rowC, colC)); });
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            output.appendChild(table);
            output.appendChild(legend());
            if (status) {
                status.textContent = 'Contrast grid updated: ' + cols.length + ' colours, '
                    + (cols.length * cols.length) + ' pairings.';
            }
        }

        // Rebuild on any edit (typing a hex, or picking from the swatch) and on
        // any row being added or removed. Craft.EditableTable mutates the tbody
        // for add/delete, which fire no input event of their own, so a
        // MutationObserver covers those; delegated input/change covers edits.
        table.addEventListener('input', build);
        table.addEventListener('change', build);
        var tbody = table.querySelector('tbody');
        if (tbody && window.MutationObserver) {
            new MutationObserver(build).observe(tbody, { childList: true });
        }
        build();
    })();

})();
