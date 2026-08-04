/**
 * LP-008 "URL preview" — live-updates the resulting public URL as the
 * admin types into the slug field, purely client-side. This is a rough
 * client-side approximation of PostService::slugify() (lowercase,
 * non-alphanumeric runs collapsed to a single hyphen, trimmed) for
 * display only — the server's own slugify()/generateUniqueSlug() remain
 * the actual authority on the saved slug (including duplicate-suffix
 * resolution, which this preview can't know about ahead of save).
 *
 * Markup contract (see admin/views/posts/new.php):
 *   <p class="lp-field" data-lp-url-preview data-base-url="https://example.com/post/">
 *     <input type="text" name="slug">
 *     <code data-lp-url-preview-value>https://example.com/post/current-slug</code>
 *   </p>
 * Falls back to the title field (#post-title) when the slug is blank,
 * mirroring the server's own "use the title if no slug was given" rule.
 */
(function () {
    'use strict';

    function slugify(value) {
        return String(value)
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'post';
    }

    function enhance(wrapper) {
        var slugInput = wrapper.querySelector('input[type="text"]');
        var output = wrapper.querySelector('[data-lp-url-preview-value]');
        var titleInput = document.getElementById('post-title');
        var baseUrl = wrapper.getAttribute('data-base-url') || '';

        if (!slugInput || !output) {
            return;
        }

        function update() {
            var source = slugInput.value.trim() !== '' ? slugInput.value : (titleInput ? titleInput.value : '');
            output.textContent = baseUrl + slugify(source);
        }

        slugInput.addEventListener('input', update);

        if (titleInput) {
            titleInput.addEventListener('input', update);
        }
    }

    document.querySelectorAll('[data-lp-url-preview]').forEach(enhance);
})();
