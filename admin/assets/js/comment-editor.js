/**
 * Small formatting toolbar for the admin comment reply and edit forms.
 *
 * A comment is stored as plain text and rendered by format_comment_content(),
 * which only understands bare URLs, "> " quote lines and @mentions, so the
 * toolbar inserts exactly that syntax into the textarea and nothing else.
 * Without JavaScript the textarea is still a working form field.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.25.0
 */
(function () {
    'use strict';

    function replaceSelection(textarea, text, selectInserted) {
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;

        textarea.setRangeText(text, start, end, selectInserted ? 'select' : 'end');
        textarea.focus();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Prefixes every line touched by the selection with "> ", or removes the
    // prefix when they are all quoted already, so the button toggles.
    function toggleQuote(textarea) {
        var value = textarea.value;
        var lineStart = value.lastIndexOf('\n', textarea.selectionStart - 1) + 1;
        var lineEndIndex = value.indexOf('\n', textarea.selectionEnd);
        var lineEnd = lineEndIndex === -1 ? value.length : lineEndIndex;
        var lines = value.slice(lineStart, lineEnd).split('\n');
        var allQuoted = lines.every(function (line) { return /^> ?/.test(line); });
        var changed = lines.map(function (line) {
            return allQuoted ? line.replace(/^> ?/, '') : '> ' + line;
        }).join('\n');

        textarea.setRangeText(changed, lineStart, lineEnd, 'select');
        textarea.focus();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // URLs link themselves, so the button only has to put a ready-to-edit
    // address in place, selected so typing replaces it.
    function insertLink(textarea) {
        var selected = textarea.value.slice(textarea.selectionStart, textarea.selectionEnd);

        if (/^https?:\/\//i.test(selected)) {
            textarea.focus();

            return;
        }

        replaceSelection(textarea, 'https://', true);
    }

    function insertMention(textarea) {
        replaceSelection(textarea, '@', false);
    }

    function addButton(toolbar, label, hint, onClick) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'lp-button lp-comment-editor__button';
        button.textContent = label;
        button.title = hint;
        button.addEventListener('click', onClick);
        toolbar.appendChild(button);

        return button;
    }

    function init(wrapper) {
        var textarea = wrapper.querySelector('textarea');

        if (!textarea) {
            return;
        }

        var toolbar = document.createElement('div');
        toolbar.className = 'lp-comment-editor__toolbar';
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', 'Comment formatting');
        toolbar.setAttribute('aria-controls', textarea.id);

        addButton(toolbar, 'Quote', 'Quote the selected lines', function () { toggleQuote(textarea); });
        addButton(toolbar, 'Link', 'Insert a web address (it becomes a link automatically)', function () { insertLink(textarea); });
        addButton(toolbar, '@', 'Mention someone by their username', function () { insertMention(textarea); });

        // The emoji window belongs to the editor script and only exists when the
        // Emoji Picker plugin has put its data on this wrapper.
        if (wrapper.dataset.emojiDataset) {
            addButton(toolbar, 'Emoji', 'Insert an emoji', function () {
                if (typeof window.lpOpenEmojiPicker !== 'function') {
                    return;
                }

                window.lpOpenEmojiPicker(wrapper, function (emoji) {
                    replaceSelection(textarea, emoji, false);
                });
            });
        }

        wrapper.insertBefore(toolbar, textarea);
    }

    function boot() {
        document.querySelectorAll('[data-lp-comment-editor]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
