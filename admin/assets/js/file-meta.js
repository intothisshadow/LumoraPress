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

    function describe(item) {
        var parts = [];

        if (item && item.fileSize !== undefined && item.fileSize !== null) {
            parts.push(formatBytes(item.fileSize));
        }

        if (item && item.width > 0 && item.height > 0) {
            parts.push(item.width + ' × ' + item.height + ' px');
        }

        return parts.filter(Boolean).join(' · ');
    }

    function element(item, className) {
        var text = describe(item);

        if (text === '') {
            return null;
        }

        var span = document.createElement('span');
        span.className = className || 'lp-file-meta';
        span.textContent = text;

        return span;
    }

    window.lpFileMeta = { formatBytes: formatBytes, describe: describe, element: element };
})();
