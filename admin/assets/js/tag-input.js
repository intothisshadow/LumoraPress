/**
 * Progressively enhances a plain, comma-separated tags <input> into a
 * chip + live-suggestions widget. The underlying <input> stays in the DOM
 * (hidden) as the real form field, so a post can still be tagged with no
 * JavaScript at all — this script only makes it nicer to use.
 *
 * Suggestions come from a small debounced fetch() to the tag-input's own
 * server-side search (LP-011, TagService::searchNames()) rather than a
 * full tag-name list preloaded into the page and filtered client-side —
 * that approach grew both the page payload and the filtering cost
 * linearly with the site's total tag count.
 *
 * Markup contract (see admin/views/posts/new.php):
 *   <div class="lp-field lp-tag-input" data-lp-tag-input
 *        data-query-url="..." data-query-csrf="...">
 *     <label for="...">Tags</label>
 *     <input type="text" name="tags" value="a, b">
 *     <span class="lp-field__hint">...</span>
 *   </div>
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 200;

    function enhance(wrapper) {
        var sourceInput = wrapper.querySelector('input[type="text"]');

        if (!sourceInput) {
            return;
        }

        var queryUrl = wrapper.dataset.queryUrl;
        var queryCsrf = wrapper.dataset.queryCsrf;

        var chips = sourceInput.value
            .split(',')
            .map(function (name) { return name.trim(); })
            .filter(function (name) { return name !== ''; });

        sourceInput.hidden = true;

        var chipList = document.createElement('ul');
        chipList.className = 'lp-tag-input__chips';

        var typeahead = document.createElement('input');
        typeahead.type = 'text';
        typeahead.className = 'lp-tag-input__typeahead';
        typeahead.setAttribute('placeholder', sourceInput.getAttribute('placeholder') || '');
        typeahead.setAttribute('autocomplete', 'off');

        var suggestionList = document.createElement('ul');
        suggestionList.className = 'lp-tag-input__suggestions';
        suggestionList.hidden = true;

        var box = document.createElement('div');
        box.className = 'lp-tag-input__box';
        box.appendChild(chipList);
        box.appendChild(typeahead);

        sourceInput.insertAdjacentElement('afterend', suggestionList);
        sourceInput.insertAdjacentElement('afterend', box);

        var activeIndex = -1;
        var currentMatches = [];
        var debounceTimer = null;
        var requestId = 0;

        function hasChip(name) {
            var lower = name.toLowerCase();

            return chips.some(function (chip) { return chip.toLowerCase() === lower; });
        }

        function syncSourceInput() {
            sourceInput.value = chips.join(', ');
        }

        function renderChips() {
            chipList.innerHTML = '';

            chips.forEach(function (name, index) {
                var item = document.createElement('li');
                item.className = 'lp-tag-input__chip';
                item.textContent = name;

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'lp-tag-input__chip-remove';
                remove.setAttribute('aria-label', 'Remove ' + name);
                remove.textContent = '×';
                remove.addEventListener('click', function () {
                    chips.splice(index, 1);
                    renderChips();
                    syncSourceInput();
                    typeahead.focus();
                });

                item.appendChild(remove);
                chipList.appendChild(item);
            });
        }

        function hideSuggestions() {
            window.clearTimeout(debounceTimer);
            // Invalidates any in-flight fetch's response so it can't
            // repopulate the dropdown after this point.
            requestId += 1;
            suggestionList.hidden = true;
            suggestionList.innerHTML = '';
            activeIndex = -1;
            currentMatches = [];
        }

        function highlightActive() {
            Array.prototype.forEach.call(suggestionList.children, function (li, index) {
                li.classList.toggle('is-active', index === activeIndex);
            });
        }

        function renderMatches(matches) {
            currentMatches = matches.filter(function (name) { return !hasChip(name); });

            suggestionList.innerHTML = '';
            activeIndex = -1;

            if (currentMatches.length === 0) {
                hideSuggestions();

                return;
            }

            currentMatches.forEach(function (name) {
                var item = document.createElement('li');
                item.className = 'lp-tag-input__suggestion';
                item.textContent = name;
                item.addEventListener('mousedown', function (event) {
                    // mousedown (not click) so this fires before the
                    // typeahead's blur would otherwise hide the dropdown.
                    event.preventDefault();
                    addChip(name);
                    typeahead.focus();
                });
                suggestionList.appendChild(item);
            });

            suggestionList.hidden = false;
        }

        function showSuggestions(query) {
            window.clearTimeout(debounceTimer);

            if (!queryUrl) {
                return;
            }

            debounceTimer = window.setTimeout(function () {
                var thisRequestId = ++requestId;

                var formData = new FormData();
                formData.append('form', 'tag_autocomplete_query');
                formData.append('csrf_token', queryCsrf);
                formData.append('term', query);

                fetch(queryUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(function (response) { return response.json(); })
                    .then(function (json) {
                        // Csrf::verify() is single-use — every response
                        // carries a freshly issued token that must replace
                        // this one for the next query.
                        if (json.csrfToken) {
                            queryCsrf = json.csrfToken;
                            wrapper.dataset.queryCsrf = json.csrfToken;
                        }

                        // A later keystroke may have already started its
                        // own request — an out-of-order response to this
                        // now-stale one must never repopulate the dropdown.
                        if (thisRequestId !== requestId) {
                            return;
                        }

                        renderMatches(json.names || []);
                    })
                    .catch(function () {
                        // A transient network hiccup — leave whatever
                        // suggestions (if any) are already showing rather
                        // than surfacing an error in a tagging widget.
                    });
            }, DEBOUNCE_MS);
        }

        function addChip(name) {
            var trimmed = name.trim();

            if (trimmed === '' || hasChip(trimmed)) {
                typeahead.value = '';
                hideSuggestions();

                return;
            }

            chips.push(trimmed);
            renderChips();
            syncSourceInput();
            typeahead.value = '';
            hideSuggestions();
        }

        typeahead.addEventListener('input', function () {
            var query = typeahead.value;

            if (query.trim() === '') {
                hideSuggestions();

                return;
            }

            showSuggestions(query);
        });

        typeahead.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                if (currentMatches.length === 0) {
                    return;
                }

                event.preventDefault();
                activeIndex = Math.min(activeIndex + 1, currentMatches.length - 1);
                highlightActive();
            } else if (event.key === 'ArrowUp') {
                if (currentMatches.length === 0) {
                    return;
                }

                event.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                highlightActive();
            } else if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();

                if (activeIndex >= 0 && currentMatches[activeIndex]) {
                    addChip(currentMatches[activeIndex]);
                } else if (typeahead.value.trim() !== '') {
                    addChip(typeahead.value);
                }
            } else if (event.key === 'Escape') {
                hideSuggestions();
            } else if (event.key === 'Backspace' && typeahead.value === '' && chips.length > 0) {
                chips.pop();
                renderChips();
                syncSourceInput();
            }
        });

        document.addEventListener('click', function (event) {
            if (!box.contains(event.target)) {
                hideSuggestions();
            }
        });

        var form = wrapper.closest('form');

        if (form) {
            form.addEventListener('submit', function () {
                if (typeahead.value.trim() !== '') {
                    addChip(typeahead.value);
                }
            });
        }

        renderChips();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var wrappers = document.querySelectorAll('[data-lp-tag-input]');

        Array.prototype.forEach.call(wrappers, enhance);
    });
})();
