# Troubleshooting

## The installer redirects back to `/install/` in a loop

`config/config.php` was not written. Check that the web server user can write to the `config/` directory, and check `storage/logs/error.log` for the underlying exception.

## "Unable to connect to the database"

The database host, port, name, user, or password entered in step 1 of the installer are incorrect, or the MySQL/MariaDB server is not reachable from the web server. Verify the credentials with a separate MySQL client before retrying.

## Blank page / 500 error with no message

Debug mode is off by default in generated configurations. Set `'debug' => true` in `config/config.php` temporarily to see a full stack trace, and check `storage/logs/error.log` for the same detail. Never leave debug mode on in production — it can expose file paths and internal state to visitors.

## Uploaded files are rejected

Uploads are limited to a fixed list of file types: images (JPG, PNG, GIF, WebP, ICO), documents (PDF, DOC, DOCX, RTF, ODT, TXT, CSS, XML, JSON), archives (ZIP, RAR, 7Z), audio (MP3, OGG, WAV, M4A), video (MP4, WebM, MOV), and WebVTT captions (VTT), up to 100 MB each. The real type of the file is checked, not just its extension, so a file renamed to a permitted extension is still refused. SVG is deliberately not accepted, because a browser runs any script inside an SVG when it is opened directly. A file can also be refused by your server's own limits (`upload_max_filesize` and `post_max_size` in `php.ini`), which are often lower than 100 MB.

## A theme or plugin function isn't available

Procedural helpers (`add_action`, `get_header`, `register_sidebar`, `nav_menu`, `esc_html`, `__`, etc.) are only available after `include/bootstrap.php` has run. Plugins and theme `functions.php` files are loaded during bootstrap, before routing — do not call these helpers from code that executes earlier (e.g. `config/config.php` itself).

## Log files

Application errors are logged to `storage/logs/error.log`. This directory is denied direct web access; read it via SSH/SFTP or your hosting control panel's file manager.
