/**
 * Native HTML5 drag-and-drop reordering, shared by the Appearance >
 * Widgets and Appearance > Menus screens (LP-048/LP-049) — a generic
 * counterpart to folder-drag-drop.js's single-purpose reparenting drag.
 * Reuses one pre-rendered, CSRF-protected "reposition" form per group
 * rather than a new AJAX/JSON endpoint: on drop, this script only fills
 * in that form's hidden dragged/target/position fields and calls
 * requestSubmit(), so the actual reorder is a normal full-page-reload
 * POST through the same server-side validation every other action on
 * these pages already goes through — the same "full reload is expected
 * anyway" trade-off folder-drag-drop.js's own docblock makes.
 *
 * Markup contract:
 *   <ANY data-lp-sortable-group>
 *     <LI data-lp-sortable-item data-lp-sortable-id="123" data-lp-sortable-parent="optional-group-id">
 *       <... data-lp-drag-handle>drag me</...>
 *     </LI>
 *     ...
 *     <form data-lp-sortable-reposition-form>
 *       <input data-lp-sortable-field="dragged_id">
 *       <input data-lp-sortable-field="target_id">
 *       <input data-lp-sortable-field="position">
 *     </form>
 *   </ANY>
 *
 * data-lp-sortable-parent is optional — when present on both the dragged
 * item and a candidate drop target, they must match for the drop to be
 * allowed. This is what keeps a nested menu's drag-and-drop scoped to
 * reordering among true siblings only (matching Move Up/Move Down's own
 * sibling-only semantics exactly) — dragging an item onto a row that
 * belongs to a different parent is silently refused rather than
 * re-parenting it, since re-parenting stays the "Parent Item" dropdown's
 * job. Widgets lists omit data-lp-sortable-parent entirely, so every item
 * is a valid drop target for every other (undefined matches undefined).
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var groups = document.querySelectorAll('[data-lp-sortable-group]');

        groups.forEach(function (group) {
            var form = group.querySelector('[data-lp-sortable-reposition-form]');

            if (!form) {
                return;
            }

            var draggedField = form.querySelector('[data-lp-sortable-field="dragged_id"]');
            var targetField = form.querySelector('[data-lp-sortable-field="target_id"]');
            var positionField = form.querySelector('[data-lp-sortable-field="position"]');

            if (!draggedField || !targetField || !positionField) {
                return;
            }

            var draggedItem = null;

            function clearIndicators() {
                group.querySelectorAll('.lp-sortable__item--drop-before, .lp-sortable__item--drop-after').forEach(function (el) {
                    el.classList.remove('lp-sortable__item--drop-before', 'lp-sortable__item--drop-after');
                });
            }

            function sameGroup(a, b) {
                return (a.dataset.lpSortableParent || null) === (b.dataset.lpSortableParent || null);
            }

            group.querySelectorAll('[data-lp-sortable-item]').forEach(function (item) {
                var handle = item.querySelector('[data-lp-drag-handle]');

                if (!handle) {
                    return;
                }

                handle.setAttribute('draggable', 'true');

                handle.addEventListener('dragstart', function (event) {
                    draggedItem = item;
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', item.dataset.lpSortableId);

                    if (event.dataTransfer.setDragImage) {
                        event.dataTransfer.setDragImage(item, 20, 20);
                    }

                    item.classList.add('lp-sortable--dragging');
                });

                handle.addEventListener('dragend', function () {
                    item.classList.remove('lp-sortable--dragging');
                    draggedItem = null;
                    clearIndicators();
                });

                item.addEventListener('dragover', function (event) {
                    if (!draggedItem || draggedItem === item || !sameGroup(draggedItem, item)) {
                        return;
                    }

                    event.preventDefault();

                    var rect = item.getBoundingClientRect();
                    var before = (event.clientY - rect.top) < rect.height / 2;

                    clearIndicators();
                    item.classList.add(before ? 'lp-sortable__item--drop-before' : 'lp-sortable__item--drop-after');
                });

                item.addEventListener('drop', function (event) {
                    if (!draggedItem || draggedItem === item || !sameGroup(draggedItem, item)) {
                        return;
                    }

                    event.preventDefault();

                    var rect = item.getBoundingClientRect();
                    var before = (event.clientY - rect.top) < rect.height / 2;

                    draggedField.value = draggedItem.dataset.lpSortableId;
                    targetField.value = item.dataset.lpSortableId;
                    positionField.value = before ? 'before' : 'after';
                    form.requestSubmit();
                });
            });
        });
    });
}());
