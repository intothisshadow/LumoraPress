/**
 * The Header tab's image list on Appearance > Customize: removes an image from
 * the list and adds the ones chosen in the Media Manager picker (or none-yet
 * uploaded ones come in with the form's file field instead). The list is
 * the order the images were added; nothing is saved until the form is.
 *
 * Markup contract (see admin/views/appearance/customize.php):
 *   <fieldset data-lp-header-images>
 *     <ul data-lp-header-images-list> <li data-media-id> hidden input[name="header_image_ids[]"] … </li> </ul>
 *     <p data-lp-header-images-empty>
 *     <div data-lp-header-images-picker> (featured-image-picker.js, multiple mode)
 */
(function () {
    'use strict';

    function thumbnailUrl(item) {
        var sizes = item.sizes || {};

        return (sizes.small || sizes.medium || sizes.large || sizes.full || { url: item.url }).url;
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-header-images]').forEach(function (root) {
            var list = root.querySelector('[data-lp-header-images-list]');
            var empty = root.querySelector('[data-lp-header-images-empty]');
            var picker = root.querySelector('[data-lp-header-images-picker]');

            if (!list) {
                return;
            }

            function refreshEmptyState() {
                if (empty) {
                    empty.hidden = list.children.length > 0;
                }
            }

            function addItem(item) {
                var duplicate = Array.prototype.some.call(list.children, function (existing) {
                    return existing.dataset.mediaId === String(item.id);
                });

                if (duplicate) {
                    return;
                }

                var li = document.createElement('li');
                li.className = 'lp-header-images__item';
                li.dataset.mediaId = String(item.id);

                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'header_image_ids[]';
                input.value = String(item.id);

                var img = document.createElement('img');
                img.className = 'lp-header-images__thumb';
                img.src = thumbnailUrl(item);
                img.alt = '';

                var name = document.createElement('span');
                name.className = 'lp-header-images__name';
                name.textContent = item.name;

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'lp-button lp-button--link lp-button--link--danger';
                remove.setAttribute('data-lp-header-image-remove', '');
                remove.textContent = 'Remove';

                li.appendChild(input);
                li.appendChild(img);
                li.appendChild(name);
                li.appendChild(remove);
                list.appendChild(li);
                refreshEmptyState();
            }

            // One listener covers the images already on the page and any added later.
            list.addEventListener('click', function (event) {
                var button = event.target.closest('[data-lp-header-image-remove]');

                if (button) {
                    button.closest('li').remove();
                    refreshEmptyState();
                }
            });

            if (picker) {
                picker.addEventListener('lp:picker-select', function (event) {
                    addItem(event.detail);
                });
            }
        });
    });
})();
