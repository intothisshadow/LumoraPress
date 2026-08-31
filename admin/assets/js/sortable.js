/**
 * Native HTML5 drag-and-drop reordering, shared by the Appearance >
 * Widgets and Appearance > Menus screens (LP-048/LP-049) — a generic
 * counterpart to folder-drag-drop.js's single-purpose reparenting drag.
 *
 * Two persistence modes, both driven by the same drag detection:
 *
 * Form mode (original, still the default) reuses one pre-rendered,
 * CSRF-protected "reposition" form per group rather than a new AJAX/JSON
 * endpoint: on drop, this script only fills in that form's hidden
 * dragged/target/position fields and calls requestSubmit(), so the actual
 * reorder is a normal full-page-reload POST through the same server-side
 * validation every other action on these pages already goes through — the
 * same "full reload is expected anyway" trade-off folder-drag-drop.js's
 * own docblock makes.
 *
 * Markup contract (form mode):
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
 * AJAX mode (LP-083, opt-in via data-lp-sortable-ajax-url) is for the Post/
 * Page editor sidebars, where the surrounding form is the entire post/page
 * being edited — a full-page-reload submit on every drag would risk
 * discarding unsaved edits. In this mode there is no reposition form: this
 * script itself moves the dragged box's DOM node (there is no reload to
 * show a server-computed order), then POSTs the resulting full box order
 * plus which boxes are currently collapsed via fetch(), so a drag and a
 * collapse/expand toggle both send one consistent snapshot rather than
 * racing each other with partial state.
 *
 * Markup contract (AJAX mode):
 *   <ANY data-lp-sortable-group
 *        data-lp-sortable-ajax-url="..."
 *        data-lp-sortable-ajax-csrf="..."
 *        data-lp-editor-screen-type="post|page">
 *     <DIV data-lp-sortable-item data-lp-sortable-id="publish" class="lp-sidebar-box ...">
 *       <... data-lp-drag-handle>drag me</...>
 *       <... data-lp-sidebar-box-toggle>collapse/expand</...>  (optional)
 *     </DIV>
 *     ...
 *   </ANY>
 * The POST body is { form: 'save_editor_layout', screen_type, csrf_token,
 * order[], collapsed[] } — see admin/views/partials/editor-layout-save.php.
 * data-lp-sortable-ajax-csrf is refreshed from the JSON response after
 * every call, since Csrf::verify() (app/Core/Security/Csrf.php) is
 * single-use — the same refresh pattern content-editor.js's upload flow
 * already uses.
 *
 * data-lp-sortable-parent is optional — when present on both the dragged
 * item and a candidate drop target, they must match for the drop to be
 * allowed. This is what keeps a nested menu's drag-and-drop scoped to
 * reordering among true siblings only (matching Move Up/Move Down's own
 * sibling-only semantics exactly) — dragging an item onto a row that
 * belongs to a different parent is silently refused rather than
 * re-parenting it, since re-parenting stays the "Parent Item" dropdown's
 * job. Widgets lists and the editor sidebar omit data-lp-sortable-parent
 * entirely, so every item is a valid drop target for every other
 * (undefined matches undefined).
 *
 * Move Up/Move Down buttons (LP-134) are an optional, opt-in keyboard-
 * accessible alternative to dragging — this script's native HTML5
 * drag-and-drop has no keyboard equivalent at all, a pre-existing gap on
 * every screen above. A page that wants them adds its own buttons inside
 * each item:
 *   <button type="button" data-lp-sortable-move="up">...</button>
 *   <button type="button" data-lp-sortable-move="down">...</button>
 * Clicking one swaps the item with its adjacent sibling *within the same
 * group* (honoring data-lp-sortable-parent exactly like a drag would),
 * then persists the result the same way a drop does — persistState() in
 * AJAX mode, or the reposition form in form mode. Pages that don't render
 * these buttons see no change at all.
 */
(function () {
    'use strict';

    function clearIndicators(group) {
        group.querySelectorAll('.lp-sortable__item--drop-before, .lp-sortable__item--drop-after').forEach(function (el) {
            el.classList.remove('lp-sortable__item--drop-before', 'lp-sortable__item--drop-after');
        });
    }

    function sameGroup(a, b) {
        return (a.dataset.lpSortableParent || null) === (b.dataset.lpSortableParent || null);
    }

    /**
     * The current on-screen box order and collapsed-box set for an AJAX-mode
     * group, read straight from the DOM — the source of truth for what to
     * persist is whatever the user currently sees, not any prior server
     * response.
     */
    function readState(group) {
        var order = [];
        var collapsed = [];

        group.querySelectorAll('[data-lp-sortable-item]').forEach(function (item) {
            var id = item.dataset.lpSortableId;

            if (!id) {
                return;
            }

            order.push(id);

            if (item.classList.contains('lp-sidebar-box--collapsed')) {
                collapsed.push(id);
            }
        });

        return { order: order, collapsed: collapsed };
    }

    function persistState(group) {
        var url = group.dataset.lpSortableAjaxUrl;

        if (!url) {
            return;
        }

        var state = readState(group);
        var body = new URLSearchParams();
        body.set('form', 'save_editor_layout');
        body.set('screen_type', group.dataset.lpEditorScreenType || '');
        body.set('csrf_token', group.dataset.lpSortableAjaxCsrf || '');
        state.order.forEach(function (id) {
            body.append('order[]', id);
        });
        state.collapsed.forEach(function (id) {
            body.append('collapsed[]', id);
        });

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                if (json && typeof json.csrfToken === 'string') {
                    group.dataset.lpSortableAjaxCsrf = json.csrfToken;
                }
            })
            .catch(function (error) {
                // A UI-preference save failing isn't worth interrupting the
                // editor for — worst case the layout just reverts to its
                // previous saved state next visit, while the drag/collapse
                // the user just did still visually applied.
                console.error('Failed to save editor sidebar layout.', error);
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var groups = document.querySelectorAll('[data-lp-sortable-group]');

        groups.forEach(function (group) {
            var ajaxUrl = group.dataset.lpSortableAjaxUrl;
            var form = null;
            var draggedField = null;
            var targetField = null;
            var positionField = null;

            if (!ajaxUrl) {
                form = group.querySelector('[data-lp-sortable-reposition-form]');

                if (!form) {
                    return;
                }

                draggedField = form.querySelector('[data-lp-sortable-field="dragged_id"]');
                targetField = form.querySelector('[data-lp-sortable-field="target_id"]');
                positionField = form.querySelector('[data-lp-sortable-field="position"]');

                if (!draggedField || !targetField || !positionField) {
                    return;
                }
            }

            var draggedItem = null;

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
                    clearIndicators(group);
                });

                item.addEventListener('dragover', function (event) {
                    if (!draggedItem || draggedItem === item || !sameGroup(draggedItem, item)) {
                        return;
                    }

                    event.preventDefault();

                    var rect = item.getBoundingClientRect();
                    var before = (event.clientY - rect.top) < rect.height / 2;

                    clearIndicators(group);
                    item.classList.add(before ? 'lp-sortable__item--drop-before' : 'lp-sortable__item--drop-after');
                });

                item.addEventListener('drop', function (event) {
                    if (!draggedItem || draggedItem === item || !sameGroup(draggedItem, item)) {
                        return;
                    }

                    event.preventDefault();

                    var rect = item.getBoundingClientRect();
                    var before = (event.clientY - rect.top) < rect.height / 2;

                    if (ajaxUrl) {
                        item.parentNode.insertBefore(draggedItem, before ? item : item.nextSibling);
                        persistState(group);
                        return;
                    }

                    draggedField.value = draggedItem.dataset.lpSortableId;
                    targetField.value = item.dataset.lpSortableId;
                    positionField.value = before ? 'before' : 'after';
                    form.requestSubmit();
                });
            });
        });

        document.querySelectorAll('[data-lp-sidebar-box-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var box = button.closest('[data-lp-sortable-item]');
                var group = button.closest('[data-lp-sortable-group]');

                if (!box || !group) {
                    return;
                }

                var collapsed = box.classList.toggle('lp-sidebar-box--collapsed');
                button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                persistState(group);
            });
        });

        // LP-134: keyboard-operable equivalent of a drag — see this file's
        // own docblock for the opt-in markup contract.
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-lp-sortable-move]');

            if (!button) {
                return;
            }

            var group = button.closest('[data-lp-sortable-group]');
            var item = button.closest('[data-lp-sortable-item]');

            if (!group || !item) {
                return;
            }

            var siblings = Array.prototype.slice.call(group.querySelectorAll('[data-lp-sortable-item]')).filter(function (candidate) {
                return sameGroup(item, candidate);
            });
            var index = siblings.indexOf(item);
            var direction = button.getAttribute('data-lp-sortable-move');
            var target = direction === 'up' ? siblings[index - 1] : siblings[index + 1];

            if (!target) {
                return;
            }

            var ajaxUrl = group.dataset.lpSortableAjaxUrl;

            if (ajaxUrl) {
                target.parentNode.insertBefore(item, direction === 'up' ? target : target.nextSibling);
                persistState(group);
                button.focus();
                return;
            }

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

            draggedField.value = item.dataset.lpSortableId;
            targetField.value = target.dataset.lpSortableId;
            positionField.value = direction === 'up' ? 'before' : 'after';
            form.requestSubmit();
        });
    });
}());
