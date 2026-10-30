/**
 * "Jump to folder" filter for the Media Manager sidebar. Typing narrows the
 * folder tree already on the page: non-matching rows are hidden, the
 * ancestors of a match stay, collapsed branches holding a match open
 * temporarily, and each match shows its full path so identically named
 * subfolders can be told apart. With the box empty and focused, the folders
 * visited most recently are listed. Arrow keys move through the candidates,
 * Enter opens the highlighted one and Escape clears the box.
 *
 * Opening and closing branches here is never saved: folder-tree-toggle.js skips
 * its save while data-lp-folder-filter-active is set, and every branch goes back
 * to its own state when the box is cleared. The server-side folder search form
 * is only the no-JavaScript fallback, so it is hidden once this runs.
 *
 * Markup contract (see admin/views/media/media.php):
 *   [data-lp-folder-jump] (hidden until this runs) with [data-lp-folder-jump-input],
 *   [data-lp-folder-jump-recent], [data-lp-folder-jump-status]
 *   [data-lp-folder-tree-main] wrapping the full tree; its links carry
 *   data-folder-path ("Parent › Child") and data-folder-id.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'lpMediaRecentFolders';
    var MAX_RECENT = 5;
    var CURRENT_CLASS = 'is-jump-current';

    function readRecent() {
        try {
            var parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '[]');

            return Array.isArray(parsed) ? parsed.filter(function (entry) {
                return entry && typeof entry.id === 'string' && typeof entry.path === 'string' && typeof entry.url === 'string';
            }) : [];
        } catch (error) {
            return [];
        }
    }

    function writeRecent(entries) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(entries));
        } catch (error) {
            // Per-browser convenience only; without storage the list is simply empty.
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var wrapper = document.querySelector('[data-lp-folder-jump]');
        var treeMain = document.querySelector('[data-lp-folder-tree-main]');

        if (!wrapper || !treeMain) {
            return;
        }

        var input = wrapper.querySelector('[data-lp-folder-jump-input]');
        var recentList = wrapper.querySelector('[data-lp-folder-jump-recent]');
        var status = wrapper.querySelector('[data-lp-folder-jump-status]');
        var searchForm = document.querySelector('[data-lp-folder-search-form]');
        var pinned = document.querySelector('.lp-folder-pinned');
        var links = Array.prototype.slice.call(treeMain.querySelectorAll('a[data-folder-path]'));
        var branches = Array.prototype.slice.call(treeMain.querySelectorAll('[data-lp-folder-toggle]'));
        var savedOpen = null;
        var candidates = [];
        var currentIndex = -1;

        wrapper.hidden = false;

        // The server search stays reachable when a folder search is already showing.
        if (searchForm && !searchForm.querySelector('input[name="folder_q"]').value) {
            searchForm.hidden = true;
        }

        // Remember where the person is, most recent first.
        var activeLink = treeMain.querySelector('.lp-folder-tree__item.is-active > .lp-folder-tree__row > a[data-folder-path]');

        if (activeLink) {
            var visited = readRecent().filter(function (entry) {
                return entry.id !== activeLink.dataset.folderId;
            });

            visited.unshift({
                id: activeLink.dataset.folderId,
                path: activeLink.dataset.folderPath,
                url: activeLink.getAttribute('href')
            });
            writeRecent(visited.slice(0, MAX_RECENT));
        }

        function setCurrent(index) {
            if (candidates[currentIndex]) {
                candidates[currentIndex].classList.remove(CURRENT_CLASS);
            }

            currentIndex = candidates.length === 0 ? -1 : (index + candidates.length) % candidates.length;

            if (candidates[currentIndex]) {
                candidates[currentIndex].classList.add(CURRENT_CLASS);
                candidates[currentIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        function clearFilterState() {
            treeMain.removeAttribute('data-lp-folder-filter-active');
            document.documentElement.removeAttribute('data-lp-folder-filter-active');

            treeMain.querySelectorAll('.is-filtered-out, .is-filter-ancestor').forEach(function (row) {
                row.classList.remove('is-filtered-out', 'is-filter-ancestor');
            });

            treeMain.querySelectorAll('.lp-folder-tree__path').forEach(function (path) {
                path.remove();
            });

            if (savedOpen) {
                branches.forEach(function (branch, index) {
                    branch.open = savedOpen[index];
                });
                savedOpen = null;
            }

            if (pinned) {
                pinned.hidden = false;
            }
        }

        function showRecent() {
            var recent = readRecent();
            recentList.textContent = '';
            candidates = [];

            recent.forEach(function (entry) {
                var item = document.createElement('li');
                var link = document.createElement('a');
                link.href = entry.url;
                link.textContent = entry.path;
                item.appendChild(link);
                recentList.appendChild(item);
                candidates.push(link);
            });

            recentList.hidden = recent.length === 0;
            currentIndex = -1;
        }

        function hideRecent() {
            recentList.hidden = true;
            recentList.textContent = '';
        }

        function applyFilter() {
            var query = input.value.trim().toLowerCase();

            clearFilterState();
            candidates = [];
            currentIndex = -1;

            if (query === '') {
                status.textContent = '';

                if (document.activeElement === input) {
                    showRecent();
                }

                return;
            }

            hideRecent();
            savedOpen = branches.map(function (branch) {
                return branch.open;
            });
            treeMain.setAttribute('data-lp-folder-filter-active', '1');
            document.documentElement.setAttribute('data-lp-folder-filter-active', '1');

            if (pinned) {
                pinned.hidden = true;
            }

            var visible = new Set();

            links.forEach(function (link) {
                if (link.dataset.folderPath.toLowerCase().indexOf(query) === -1) {
                    return;
                }

                candidates.push(link);

                var row = link.closest('.lp-folder-tree__item');
                visible.add(row);

                var path = document.createElement('span');
                path.className = 'lp-folder-tree__path';
                path.textContent = link.dataset.folderPath;
                link.appendChild(path);

                // Ancestors stay visible; closed branches on the way open temporarily.
                for (var ancestor = row.parentElement.closest('.lp-folder-tree__item'); ancestor; ancestor = ancestor.parentElement.closest('.lp-folder-tree__item')) {
                    visible.add(ancestor);
                    ancestor.classList.add('is-filter-ancestor');
                }

                for (var branch = row.closest('[data-lp-folder-toggle]'); branch; branch = branch.parentElement.closest('[data-lp-folder-toggle]')) {
                    branch.open = true;
                }
            });

            // An ancestor that is itself a match is not dimmed.
            candidates.forEach(function (link) {
                link.closest('.lp-folder-tree__item').classList.remove('is-filter-ancestor');
            });

            treeMain.querySelectorAll('.lp-folder-tree__item').forEach(function (row) {
                if (!visible.has(row)) {
                    row.classList.add('is-filtered-out');
                }
            });

            status.textContent = candidates.length === 0
                ? 'No folders match.'
                : candidates.length + (candidates.length === 1 ? ' folder matches.' : ' folders match.');

            setCurrent(0);
        }

        input.addEventListener('input', applyFilter);

        input.addEventListener('focus', function () {
            if (input.value.trim() === '') {
                showRecent();
            }
        });

        input.addEventListener('blur', function () {
            // Delayed so a click on a recent folder still registers.
            window.setTimeout(function () {
                if (document.activeElement !== input && input.value.trim() === '') {
                    hideRecent();
                }
            }, 150);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setCurrent(currentIndex + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setCurrent(currentIndex - 1);
            } else if (event.key === 'Enter') {
                event.preventDefault();

                if (candidates[currentIndex]) {
                    window.location.href = candidates[currentIndex].getAttribute('href');
                }
            } else if (event.key === 'Escape') {
                event.preventDefault();
                input.value = '';
                applyFilter();
                hideRecent();
            }
        });
    });
}());
