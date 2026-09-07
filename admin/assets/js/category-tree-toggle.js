/**
 * Expand/collapse toggles for the admin Categories list's tree view
 * (LP-010). Client-side only — collapsed state isn't persisted across
 * page loads, since a drag-and-drop reorder or a Trash action already
 * triggers a full page reload elsewhere on this same screen.
 *
 * The tree is a flat <ul><li> list with depth expressed only via
 * indentation (admin/views/posts/categories.php), not real nested
 * <ul>s, so collapsing a node requires walking the flat list by
 * data-lp-sortable-parent to find its full descendant subtree — a
 * child collapsed on its own stays collapsed when its own ancestor is
 * re-expanded (its toggle's aria-expanded is checked before recursing
 * into its own children).
 *
 * Markup contract:
 *   <UL data-lp-tree>
 *     <LI data-lp-sortable-item data-lp-sortable-id="1" data-lp-sortable-parent="">
 *       <button data-lp-tree-toggle aria-expanded="true">...</button>
 *       ...
 *     <LI data-lp-sortable-item data-lp-sortable-id="2" data-lp-sortable-parent="1">
 *       <span class="lp-categories-tree__toggle-spacer"></span>  (no children, no toggle)
 *       ...
 *   </UL>
 */
(function () {
    'use strict';

    function childrenOf(items, parentId) {
        return items.filter(function (item) {
            return (item.dataset.lpSortableParent || '') === parentId;
        });
    }

    function showDescendants(items, parentId) {
        childrenOf(items, parentId).forEach(function (child) {
            child.hidden = false;

            var toggle = child.querySelector('[data-lp-tree-toggle]');

            if (!toggle || toggle.getAttribute('aria-expanded') !== 'false') {
                showDescendants(items, child.dataset.lpSortableId);
            }
        });
    }

    function hideDescendants(items, parentId) {
        childrenOf(items, parentId).forEach(function (child) {
            child.hidden = true;
            hideDescendants(items, child.dataset.lpSortableId);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-tree]').forEach(function (tree) {
            var items = Array.prototype.slice.call(tree.querySelectorAll('[data-lp-sortable-item]'));

            tree.addEventListener('click', function (event) {
                var button = event.target.closest('[data-lp-tree-toggle]');

                if (!button) {
                    return;
                }

                var item = button.closest('[data-lp-sortable-item]');

                if (!item) {
                    return;
                }

                var id = item.dataset.lpSortableId;
                var expanded = button.getAttribute('aria-expanded') !== 'false';

                if (expanded) {
                    button.setAttribute('aria-expanded', 'false');
                    button.setAttribute('aria-label', button.getAttribute('aria-label').replace('Collapse', 'Expand'));
                    hideDescendants(items, id);
                } else {
                    button.setAttribute('aria-expanded', 'true');
                    button.setAttribute('aria-label', button.getAttribute('aria-label').replace('Expand', 'Collapse'));
                    showDescendants(items, id);
                }
            });
        });
    });
})();
