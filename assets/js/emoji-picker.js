/**
 * Public "Insert Emoji" trigger for the comment form (LP-012), wiring up
 * every `[data-lp-emoji-picker-trigger]` button rendered by
 * lp_emoji_picker_button() (see include/comment-functions.php's
 * comment_form()). Only ever loaded (see FooterAssets::render()) when
 * the Emoji Picker plugin is active and enabled.
 *
 * A lean, standalone reimplementation of the same dialog
 * admin/assets/js/content-editor.js already offers the Post/Page editor
 * toolbar — kept as its own small file rather than shared with that much
 * larger admin-only bundle, the same way media-viewer.js is its own
 * public-facing script distinct from the admin media picker. The whole
 * emoji dataset already arrives embedded in the trigger button's own
 * data-emoji-dataset attribute (see the plugin's own Performance notes
 * on why that's small enough to do client-side), so this never makes a
 * network request of its own — including for "recently used," which a
 * guest has no account to persist anyway; this only tracks it in memory
 * for the current dialog session.
 */
(function () {
    'use strict';

    function openEmojiPicker(container, onInsert) {
        var dataset = JSON.parse(container.dataset.emojiDataset || '[]');
        var byEmoji = {};
        dataset.forEach(function (item) { byEmoji[item.emoji] = item; });

        var categories = [];
        dataset.forEach(function (item) {
            if (categories.indexOf(item.category) === -1) {
                categories.push(item.category);
            }
        });

        var state = {
            term: '',
            category: container.dataset.emojiDefaultCategory || categories[0] || '',
        };

        var dialog = document.createElement('dialog');
        dialog.className = 'lp-comment-emoji-dialog';

        var heading = document.createElement('h2');
        heading.textContent = 'Insert Emoji';
        heading.className = 'lp-comment-emoji-dialog__heading';

        var searchInput = document.createElement('input');
        searchInput.type = 'search';
        searchInput.className = 'lp-comment-emoji-dialog__search';
        searchInput.placeholder = 'Search emoji…';
        searchInput.setAttribute('aria-label', 'Search emoji');

        var categoryNav = document.createElement('div');
        categoryNav.className = 'lp-comment-emoji-dialog__categories';
        categoryNav.setAttribute('role', 'tablist');
        categoryNav.setAttribute('aria-label', 'Emoji categories');

        var status = document.createElement('p');
        status.className = 'lp-comment-emoji-dialog__status';
        status.hidden = true;

        var grid = document.createElement('div');
        grid.className = 'lp-comment-emoji-dialog__grid';
        grid.setAttribute('role', 'group');
        grid.setAttribute('aria-label', 'Emoji');

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'lp-button lp-comment-emoji-dialog__close';
        closeButton.textContent = 'Cancel';
        closeButton.addEventListener('click', function () { dialog.close(); });

        function renderCategoryNav() {
            categoryNav.innerHTML = '';
            categories.forEach(function (category) {
                var tab = document.createElement('button');
                tab.type = 'button';
                tab.className = 'lp-comment-emoji-dialog__category' + (state.category === category && state.term === '' ? ' is-active' : '');
                tab.textContent = category;
                tab.setAttribute('role', 'tab');
                tab.setAttribute('aria-selected', state.category === category && state.term === '' ? 'true' : 'false');
                tab.addEventListener('click', function () {
                    state.term = '';
                    searchInput.value = '';
                    state.category = category;
                    renderCategoryNav();
                    renderGrid();
                });
                categoryNav.appendChild(tab);
            });
        }

        function itemsForState() {
            if (state.term !== '') {
                var term = state.term.toLowerCase();

                return dataset.filter(function (item) {
                    if (item.name.toLowerCase().indexOf(term) !== -1) {
                        return true;
                    }

                    return item.keywords.some(function (keyword) {
                        return keyword.toLowerCase().indexOf(term) !== -1;
                    });
                });
            }

            return dataset.filter(function (item) { return item.category === state.category; });
        }

        function renderItem(item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'lp-comment-emoji-dialog__item';
            button.textContent = item.emoji;
            button.setAttribute('aria-label', item.name);
            button.title = item.name;
            button.addEventListener('click', function () {
                onInsert(item.emoji);
            });
            grid.appendChild(button);
        }

        function renderGrid() {
            grid.innerHTML = '';
            var items = itemsForState();

            if (items.length === 0) {
                status.textContent = state.term !== '' ? 'No emoji match your search.' : 'Nothing here yet.';
                status.hidden = false;

                return;
            }

            status.hidden = true;
            items.forEach(renderItem);
        }

        // Roving arrow-key navigation across the grid — same approach
        // content-editor.js's own emoji dialog uses: read the grid's
        // actual resolved column count rather than assume one, so this
        // keeps working if the tile size/dialog width ever changes.
        grid.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown' && event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }

            var items = Array.prototype.slice.call(grid.children);
            var index = items.indexOf(document.activeElement);

            if (index === -1) {
                return;
            }

            var columns = window.getComputedStyle(grid).gridTemplateColumns.split(' ').length || 1;
            var target = index;

            if (event.key === 'ArrowLeft') {
                target = index - 1;
            } else if (event.key === 'ArrowRight') {
                target = index + 1;
            } else if (event.key === 'ArrowUp') {
                target = index - columns;
            } else if (event.key === 'ArrowDown') {
                target = index + columns;
            }

            if (target >= 0 && target < items.length) {
                event.preventDefault();
                items[target].focus();
            }
        });

        var searchTimer = null;
        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                state.term = searchInput.value.trim();
                renderCategoryNav();
                renderGrid();
            }, 150);
        });

        dialog.appendChild(heading);
        dialog.appendChild(searchInput);
        dialog.appendChild(categoryNav);
        dialog.appendChild(status);
        dialog.appendChild(grid);
        dialog.appendChild(closeButton);
        dialog.addEventListener('close', function () { dialog.remove(); });

        // A click on the dimmed backdrop lands on the <dialog> itself, so
        // compare its position with the dialog's box. Requiring the press to
        // have started outside too keeps a text-selection drag that ends over
        // the backdrop from closing the picker.
        function isOutside(event) {
            var box = dialog.getBoundingClientRect();

            return event.target === dialog
                && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom);
        }

        var pressedOutside = false;
        dialog.addEventListener('mousedown', function (event) { pressedOutside = isOutside(event); });
        dialog.addEventListener('click', function (event) {
            if (pressedOutside && isOutside(event)) {
                dialog.close();
            }

            pressedOutside = false;
        });

        document.body.appendChild(dialog);
        dialog.showModal();

        renderCategoryNav();
        renderGrid();
        searchInput.focus();
    }

    // Deliberately does NOT close the dialog on insert, matching
    // content-editor.js's own emoji picker — repeated inserts in one
    // session are the common case; Escape or Cancel closes it.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-emoji-picker-trigger]').forEach(function (button) {
            button.addEventListener('click', function () {
                var targetSelector = button.dataset.emojiTarget;
                var target = targetSelector
                    ? document.querySelector(targetSelector)
                    : (button.closest('form') || document).querySelector('textarea');

                if (!target) {
                    return;
                }

                openEmojiPicker(button, function (emoji) {
                    var start = target.selectionStart || target.value.length;
                    var end = target.selectionEnd || target.value.length;
                    target.value = target.value.slice(0, start) + emoji + target.value.slice(end);
                    var caret = start + emoji.length;
                    target.setSelectionRange(caret, caret);
                    target.focus();
                });
            });
        });
    });
})();
