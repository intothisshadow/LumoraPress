/**
 * Shared "1.2 MB · 800 × 600 px" formatting for every Media-library picker
 * (Insert Image/Audio/Video/File, Featured Image, Downloads), so each one
 * describes a file the same way. Items carry `fileSize` (bytes) and, for
 * images, `width`/`height` (px), as built server-side by MediaService::pickerMeta().
 */
(function () {
    'use strict';

    var UNITS = ['B', 'KB', 'MB', 'GB'];

    function formatBytes(bytes) {
        var value = Number(bytes);

        if (!isFinite(value) || value < 0) {
            return '';
        }

        var unit = 0;

        while (value >= 1024 && unit < UNITS.length - 1) {
            value /= 1024;
            unit++;
        }

        var text = unit === 0 ? String(Math.round(value)) : value.toFixed(1).replace(/\.0$/, '');

        return text + ' ' + UNITS[unit];
    }

    function parts(item) {
        var rows = [];

        if (item && item.fileSize !== undefined && item.fileSize !== null) {
            rows.push(formatBytes(item.fileSize));
        }

        if (item && item.width > 0 && item.height > 0) {
            rows.push(item.width + ' × ' + item.height + ' px');
        }

        return rows.filter(Boolean);
    }

    function describe(item) {
        return parts(item).join(' · ');
    }

    // Size and dimensions go on separate rows so a narrow grid tile never has to truncate one of them.
    function element(item, className) {
        var rows = parts(item);

        if (rows.length === 0) {
            return null;
        }

        var wrapper = document.createElement('span');
        wrapper.className = className || 'lp-file-meta';

        rows.forEach(function (text) {
            var row = document.createElement('span');
            row.className = 'lp-file-meta__row';
            row.textContent = text;
            wrapper.appendChild(row);
        });

        return wrapper;
    }

    window.lpFileMeta = { formatBytes: formatBytes, describe: describe, element: element };
})();
