/**
 * "Create categories while editing" (LP-008): lets the post editor add a
 * brand-new category without leaving the page or losing whatever else is
 * currently typed into the post form. Posts to the same page's
 * `add_category` JSON sub-action (see admin/views/posts.php, the same
 * pattern editor_upload/convert_content already use) and, on success,
 * appends a new checked checkbox to the Categories checklist in place —
 * no page reload, no redirect.
 *
 * Markup contract (see admin/views/posts/new.php):
 *   <details data-lp-category-quick-add data-add-url="..." data-add-csrf="...">
 *     <ul data-lp-category-list>...existing <li> checkboxes...</ul>
 *     <input data-lp-category-name-input>
 *     <button data-lp-category-add-button>
 *     <p data-lp-category-add-error hidden></p>
 *   </details>
 *
 * A quick-added category has no parent, so it always appends as a new
 * top-level <li> (no data-style-margin-left) rather than trying to slot
 * into the existing depth-nested list (LP-105).
 */
(function () {
    'use strict';

    function enhance(details) {
        var field = details.closest('[data-lp-category-field]');
        var list = field ? field.querySelector('[data-lp-category-list]') : null;
        var input = details.querySelector('[data-lp-category-name-input]');
        var button = details.querySelector('[data-lp-category-add-button]');
        var errorEl = details.querySelector('[data-lp-category-add-error]');

        if (!field || !list || !input || !button) {
            return;
        }

        function showError(message) {
            if (!errorEl) {
                return;
            }

            errorEl.textContent = message;
            errorEl.hidden = false;
        }

        function hideError() {
            if (!errorEl) {
                return;
            }

            errorEl.hidden = true;
            errorEl.textContent = '';
        }

        function checkExisting(id) {
            var checkbox = list.querySelector('input[value="' + id + '"]');

            if (!checkbox) {
                return false;
            }

            checkbox.checked = true;

            return true;
        }

        function appendCategory(category) {
            if (checkExisting(category.id)) {
                return;
            }

            var item = document.createElement('li');
            var label = document.createElement('label');
            label.className = 'lp-field--checkbox';

            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = 'category_ids[]';
            checkbox.value = category.id;
            checkbox.checked = true;

            label.appendChild(checkbox);
            label.appendChild(document.createTextNode(' ' + category.name));
            item.appendChild(label);
            list.appendChild(item);
        }

        function submit() {
            var name = input.value.trim();

            if (name === '') {
                showError('Enter a category name first.');

                return;
            }

            hideError();
            button.disabled = true;

            var formData = new FormData();
            formData.append('form', 'add_category');
            formData.append('csrf_token', details.dataset.addCsrf);
            formData.append('name', name);

            fetch(details.dataset.addUrl, { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    if (json.error) {
                        throw new Error(json.error);
                    }

                    appendCategory(json.data);
                    input.value = '';
                })
                .catch(function (error) {
                    showError(error.message || 'Could not add that category.');
                })
                .finally(function () {
                    button.disabled = false;
                });
        }

        button.addEventListener('click', submit);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submit();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-category-quick-add]').forEach(enhance);
    });
}());
