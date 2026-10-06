# Editor Testing Checklist

The visual editor (TinyMCE, configured in `admin/assets/js/content-editor.js`) and the Markdown editor (EasyMDE) run in the browser, so the PHP test suite cannot drive them. This is the manual checklist for the behavior it can't see. The server side of the same behavior (what the editor's markup becomes when saved, and what survives a format switch) is covered by `EditorMarkupTest`, so a change to sanitizing or conversion shows up there first.

Run it on a dev install (never the live source tree's installer; see `CLAUDE.md`) after any change to `content-editor.js`, `image-editor.js`, `featured-image-picker.js`, the editor CSS, or the TinyMCE/EasyMDE versions. Use a throwaway post, and use a browser's own dark-mode and narrow-window controls for the last section.

## Visual editor

Open Posts › Add New and set the editor to Visual / HTML.

- [ ] Every toolbar button is visible with no overflow menu: undo/redo, blocks, bold, italic, underline, strikethrough, superscript, subscript, inline code, font color, the four alignments, Read More, bullet and numbered lists, blockquote, horizontal rule, link, image, edit image, folder, audio, video, file, source code, code sample, paste as text, find and replace, fullscreen, table, help. Icon, emoji, and shortcode buttons appear only when their plugins are active.
- [ ] Each formatting button applies and removes its formatting, and the button shows pressed while the cursor is inside it (bold, italic, underline, strikethrough, superscript, subscript, inline code).
- [ ] Font color applies each palette color and "Remove Color" clears it. Alignment buttons set and replace the alignment on paragraphs, headings, and table cells.
- [ ] Insert Read More adds one marker paragraph; the marker is visually distinct in the editor.
- [ ] Lists, blockquote, horizontal rule, and tables (insert, add and remove rows and columns) behave normally.
- [ ] Code sample: choose a language, type code, save, reopen the post. The language is still selected.
- [ ] Paste as text turns pasted rich content into plain text while on, and off restores normal paste. Pasting from Word or Google Docs gives clean paragraphs with no stray styling.
- [ ] Find and replace, word and character count, reading time, fullscreen, and the keyboard shortcuts panel (Help) all work.
- [ ] Source code view shows clean HTML with no inline `style` attributes, and edits made there appear in the editor.
- [ ] Autosave: type, wait 20 seconds, reload without saving, and confirm the draft is offered back. The "Draft autosaved" message appears for an existing post.

## Images, media, and links

- [ ] Insert Image: the picker shows file size and dimensions, search and folder filters work, and the settings step (size, link, alignment, caption, width and height) inserts what was chosen. Drag-and-drop and paste of an image upload and insert.
- [ ] Edit Image on a placed image changes its alt text, caption, alignment, link, and size, and one undo reverts the whole change.
- [ ] "Edit Image…" on the picker's settings step opens the image editor dialog; saving a copy continues with the edited image.
- [ ] "Crop, Rotate or Flip…" in the Edit Image dialog edits a placed image. Check the image and its link both point at the new picture, at the same size as before.
- [ ] Insert File, Insert Audio, Insert Video, and Insert Folder each insert and render on the published post.
- [ ] Insert/Edit Link (button and Ctrl/Cmd+K) can pick an existing post or page and can set a custom URL.

## Image editor (Media Manager and dialog)

- [ ] Rotate left/right, flip horizontal/vertical, and Reset change the preview; flips show pressed; rotating or flipping clears the crop area.
- [ ] Dragging draws a crop area; its inside moves it; each corner resizes it. The crop shape presets lock the proportions. The X, Y, Width, and Height fields move the rectangle, and work from the keyboard alone.
- [ ] Save as New Copy leaves the original untouched; Replace Original asks first and keeps the address. A featured image chosen through its picker switches to the new copy.
- [ ] An image that isn't in the Media Library, a GIF, and a user without upload permission each get a clear message or no button.

## Format switching

- [ ] Visual to Markdown and back keeps: bold, italic, underline, strikethrough, superscript and subscript, inline code, font color, headings, lists, blockquote, tables, code block language, alignment, captions, image size and alignment, links, and the Read More marker.
- [ ] A failed conversion (turn the network off) leaves the editor on its current format with a message.

## Markdown editor

- [ ] The toolbar buttons insert the expected syntax. Syntax with no button (`^sup^`, `~sub~`, `{.center}` after a paragraph) renders in the preview when typed by hand.
- [ ] The preview matches the saved result, and the cursor-in-image Edit Image button works, including "Crop, Rotate or Flip…".

## Appearance and access

- [ ] Dark mode: the editor chrome and every dialog (picker, Edit Image, image editor) are readable. The editing area keeps the theme's own colors.
- [ ] Phone width (375px): the toolbar wraps, dialogs fit the screen with no horizontal scrolling, and the crop handles can be dragged by touch.
- [ ] Keyboard only: Tab reaches every toolbar button and dialog control, Escape closes dialogs, and focus returns to where it was.
- [ ] Screen reader: toolbar buttons announce their names and pressed state; the image editor announces its status line.
- [ ] Browsers: repeat the visual editor and image sections in current Firefox and Chromium; Safari if available.
