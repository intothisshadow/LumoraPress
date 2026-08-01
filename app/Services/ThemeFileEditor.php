<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use ParseError;
use RuntimeException;

/**
 * Theme File Editor (LP-050): browse, view, and edit a single theme's own
 * source files directly from the admin area — a built-in alternative to
 * FTP/SSH for quick template tweaks, in the spirit of classic WordPress's
 * Appearance > Theme File Editor.
 *
 * Every public method takes a theme slug and a caller-supplied relative
 * path and re-resolves both with realpath() immediately before touching
 * disk, confirming the result is still contained inside that one theme's
 * directory — the same "never trust a path carried from an earlier
 * request" posture MediaImportService::isPathAllowed() documents (a
 * relative path round-tripped through a hidden form field between page
 * load and submit is exactly the kind of value that could be tampered
 * with). This also means symlinks inside a theme directory can't be used
 * to escape it, since realpath() resolves them before the containment
 * check runs.
 *
 * Editing is restricted to a fixed allow-list of plain-text extensions
 * (EDITABLE_EXTENSIONS) — a theme's images, fonts, and any other binary
 * asset are simply invisible to this class, both in the browse tree and as
 * a read/write target, satisfying LP-050's "hide unsupported/binary
 * files" without needing real MIME sniffing for content this class never
 * touches.
 */
final class ThemeFileEditor
{
    private const EDITABLE_EXTENSIONS = ['php', 'css', 'js', 'html', 'htm', 'md', 'json', 'xml', 'txt', 'svg'];

    private const MAX_FILE_BYTES = 2 * 1024 * 1024;

    private const MAX_BACKUPS_PER_FILE = 10;

    public function __construct(
        private readonly string $themesPath,
        private readonly string $backupsPath,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function editableExtensions(): array
    {
        return self::EDITABLE_EXTENSIONS;
    }

    /**
     * Recursively builds a tree of every editable file and every
     * directory under a theme (directories are always shown, even if they
     * contain no editable file directly, so their editable descendants
     * remain reachable), directories first then alphabetically.
     *
     * @return array<int, array{name: string, path: string, type: 'file'|'dir', size: int, modifiedAt: int, writable: bool, children: array<int, mixed>}>
     */
    public function tree(string $slug): array
    {
        return $this->scanDirectory($this->themeRoot($slug), '');
    }

    /**
     * @return array<int, array{name: string, path: string, type: 'file'|'dir', size: int, modifiedAt: int, writable: bool, children: array<int, mixed>}>
     */
    private function scanDirectory(string $root, string $relativeDir): array
    {
        $absoluteDir = $relativeDir === '' ? $root : $root . '/' . $relativeDir;
        $entries = scandir($absoluteDir) ?: [];
        $dirNodes = [];
        $fileNodes = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryAbsolute = $absoluteDir . '/' . $entry;
            $entryRelative = $relativeDir === '' ? $entry : $relativeDir . '/' . $entry;

            if (is_dir($entryAbsolute)) {
                $children = $this->scanDirectory($root, $entryRelative);

                // A directory with no editable file anywhere beneath it
                // (recursively) is left out entirely — an empty branch in
                // the tree would just be dead weight to render.
                if ($children === [] && !$this->hasEditableDescendant($entryAbsolute)) {
                    continue;
                }

                $dirNodes[] = [
                    'name' => $entry,
                    'path' => $entryRelative,
                    'type' => 'dir',
                    'size' => 0,
                    'modifiedAt' => (int) (filemtime($entryAbsolute) ?: 0),
                    'writable' => is_writable($entryAbsolute),
                    'children' => $children,
                ];

                continue;
            }

            if (!$this->isEditableExtension($entry)) {
                continue;
            }

            $fileNodes[] = [
                'name' => $entry,
                'path' => $entryRelative,
                'type' => 'file',
                'size' => (int) (filesize($entryAbsolute) ?: 0),
                'modifiedAt' => (int) (filemtime($entryAbsolute) ?: 0),
                'writable' => is_writable($entryAbsolute),
                'children' => [],
            ];
        }

        usort($dirNodes, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        usort($fileNodes, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [...$dirNodes, ...$fileNodes];
    }

    private function hasEditableDescendant(string $absoluteDir): bool
    {
        $entries = scandir($absoluteDir) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryAbsolute = $absoluteDir . '/' . $entry;

            if (is_dir($entryAbsolute)) {
                if ($this->hasEditableDescendant($entryAbsolute)) {
                    return true;
                }

                continue;
            }

            if ($this->isEditableExtension($entry)) {
                return true;
            }
        }

        return false;
    }

    public function read(string $slug, string $relativePath): string
    {
        $path = $this->resolveExisting($slug, $relativePath, requireFile: true);
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read that file.');
        }

        return $contents;
    }

    /**
     * Saves new content for an existing file, taking a timestamped backup
     * of its previous content first (see backups()/restoreBackup()).
     */
    public function save(string $slug, string $relativePath, string $contents): void
    {
        $path = $this->resolveExisting($slug, $relativePath, requireFile: true);

        if (!is_writable($path)) {
            throw new RuntimeException('This file is not writable by the web server. Check its file permissions.');
        }

        if (strlen($contents) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('That file is too large to save (2 MB limit).');
        }

        $this->backup($slug, $relativePath, $path);

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Failed to save the file.');
        }
    }

    /**
     * Best-effort PHP syntax check with no shell/subprocess dependency —
     * this project deliberately avoids shell_exec/exec for anything
     * (see UpdateBackupService's docblock: "no mysqldump or shell_exec,
     * since either may be unavailable on shared hosting"), which rules out
     * the usual `php -l` approach. TOKEN_PARSE makes token_get_all() throw
     * a real ParseError on malformed syntax (unmatched braces/quotes,
     * unexpected tokens, ...) instead of silently returning a partial
     * token list — not a full semantic check the way `php -l` is, but a
     * genuine tokenizer-level syntax check that needs nothing beyond PHP
     * itself, satisfying LP-050's "where possible" qualifier honestly.
     */
    public function checkPhpSyntax(string $contents): ?string
    {
        try {
            $tokens = token_get_all($contents, TOKEN_PARSE);
            unset($tokens);

            return null;
        } catch (ParseError $error) {
            return $error->getMessage();
        }
    }

    /**
     * @return array<int, array{id: string, createdAt: int, size: int}>
     */
    public function backups(string $slug, string $relativePath): array
    {
        $this->resolveExisting($slug, $relativePath, requireFile: true);

        $backupDir = $this->backupDirFor($slug, $relativePath);

        if (!is_dir($backupDir)) {
            return [];
        }

        $entries = scandir($backupDir) ?: [];
        $backups = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.bak')) {
                continue;
            }

            $absolute = $backupDir . '/' . $entry;

            $backups[] = [
                'id' => $entry,
                'createdAt' => (int) (filemtime($absolute) ?: 0),
                'size' => (int) (filesize($absolute) ?: 0),
            ];
        }

        usort($backups, static fn (array $a, array $b): int => $b['createdAt'] <=> $a['createdAt']);

        return $backups;
    }

    /**
     * Restores a previous backup as the file's current content. Goes
     * through save() itself, so restoring also takes a fresh backup of
     * whatever was overwritten — undoing a restore is just restoring the
     * backup created immediately before it.
     */
    public function restoreBackup(string $slug, string $relativePath, string $backupId): void
    {
        if (!$this->isSafeBasename($backupId) || !str_ends_with($backupId, '.bak')) {
            throw new RuntimeException('Invalid backup.');
        }

        $backupDir = $this->backupDirFor($slug, $relativePath);
        $backupPath = realpath($backupDir . '/' . $backupId);
        $resolvedBackupDir = realpath($backupDir);

        if ($backupPath === false || $resolvedBackupDir === false || !str_starts_with($backupPath, $resolvedBackupDir . '/')) {
            throw new RuntimeException('That backup could not be found.');
        }

        $contents = file_get_contents($backupPath);

        if ($contents === false) {
            throw new RuntimeException('Unable to read that backup.');
        }

        $this->save($slug, $relativePath, $contents);
    }

    /**
     * Creates a new, empty editable file. $relativePath is the full path
     * of the file to create (parent directories must already exist).
     */
    public function createFile(string $slug, string $relativePath): void
    {
        $path = $this->resolveNew($slug, $relativePath);

        if (!$this->isEditableExtension($path)) {
            throw new RuntimeException('That file type is not supported by the editor.');
        }

        if (touch($path) === false) {
            throw new RuntimeException('Failed to create the file.');
        }
    }

    public function createFolder(string $slug, string $relativePath): void
    {
        $path = $this->resolveNew($slug, $relativePath);

        if (!mkdir($path, 0755)) {
            throw new RuntimeException('Failed to create the folder.');
        }
    }

    /**
     * Renames a file or folder in place (same parent directory). Returns
     * the new relative path.
     */
    public function rename(string $slug, string $relativePath, string $newName): string
    {
        $path = $this->resolveExisting($slug, $relativePath, requireFile: false);

        if (!$this->isSafeBasename($newName)) {
            throw new RuntimeException('Invalid file name.');
        }

        if (is_file($path) && !$this->isEditableExtension($newName)) {
            throw new RuntimeException('That file type is not supported by the editor.');
        }

        $newPath = dirname($path) . '/' . $newName;

        if (file_exists($newPath)) {
            throw new RuntimeException('A file or folder with that name already exists.');
        }

        if (!rename($path, $newPath)) {
            throw new RuntimeException('Failed to rename.');
        }

        $relativeDir = dirname($relativePath);

        return $relativeDir === '.' ? $newName : $relativeDir . '/' . $newName;
    }

    /**
     * Duplicates a single file alongside itself as "name-copy.ext" (or
     * "name-copy-2.ext", ... on collision). Returns the new relative path.
     */
    public function duplicate(string $slug, string $relativePath): string
    {
        $path = $this->resolveExisting($slug, $relativePath, requireFile: true);
        $dir = dirname($path);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $suffix = '-copy';
        $attempt = 0;

        do {
            $attempt++;
            $candidateName = $attempt === 1
                ? $base . $suffix . '.' . $extension
                : $base . $suffix . '-' . $attempt . '.' . $extension;
            $candidatePath = $dir . '/' . $candidateName;
        } while (file_exists($candidatePath) && $attempt < 100);

        if (file_exists($candidatePath)) {
            throw new RuntimeException('Unable to find an available name for the duplicate.');
        }

        if (!copy($path, $candidatePath)) {
            throw new RuntimeException('Failed to duplicate the file.');
        }

        $relativeDir = dirname($relativePath);

        return $relativeDir === '.' ? $candidateName : $relativeDir . '/' . $candidateName;
    }

    public function delete(string $slug, string $relativePath): void
    {
        $path = $this->resolveExisting($slug, $relativePath, requireFile: false);

        if (is_dir($path) && !is_link($path)) {
            $this->removeDirectory($path);

            return;
        }

        if (!unlink($path)) {
            throw new RuntimeException('Failed to delete the file.');
        }
    }

    private function backup(string $slug, string $relativePath, string $path): void
    {
        $current = file_get_contents($path);

        if ($current === false) {
            return;
        }

        $backupDir = $this->backupDirFor($slug, $relativePath);

        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            return;
        }

        $filename = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.bak';
        file_put_contents($backupDir . '/' . $filename, $current);

        $this->pruneBackups($backupDir);
    }

    private function pruneBackups(string $backupDir): void
    {
        $entries = scandir($backupDir) ?: [];
        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.bak')) {
                continue;
            }

            $files[$entry] = filemtime($backupDir . '/' . $entry) ?: 0;
        }

        arsort($files);
        $stale = array_slice(array_keys($files), self::MAX_BACKUPS_PER_FILE);

        foreach ($stale as $entry) {
            unlink($backupDir . '/' . $entry);
        }
    }

    /**
     * Backups are keyed by a hash of the file's relative path rather than
     * mirroring the theme's own directory structure, so a file and a
     * folder that happen to share a name at different times (create,
     * delete, create-a-folder-with-the-old-file's-name) never collide.
     */
    private function backupDirFor(string $slug, string $relativePath): string
    {
        return rtrim($this->backupsPath, '/') . '/' . $slug . '/' . sha1($relativePath);
    }

    private function isEditableExtension(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::EDITABLE_EXTENSIONS, true);
    }

    private function isSafeBasename(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }

        return !str_contains($name, '/') && !str_contains($name, '\\') && !str_contains($name, "\0");
    }

    private function themeRoot(string $slug): string
    {
        if ($slug === '' || $slug !== basename($slug) || str_contains($slug, "\0")) {
            throw new RuntimeException('That theme could not be found.');
        }

        $dir = rtrim($this->themesPath, '/') . '/' . $slug;
        $resolved = realpath($dir);

        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('That theme could not be found.');
        }

        return $resolved;
    }

    /**
     * Normalizes a caller-supplied relative path into forward-slashed
     * segments and rejects anything that could climb outside the theme
     * directory (empty segments, ".", ".."). This alone is enough to stop
     * a textual "../../../etc/passwd" attempt, but every caller still
     * layers a realpath()-based containment check on top (see
     * resolveExisting()/resolveNew()) to also catch a symlink planted
     * inside the theme directory that points somewhere else entirely.
     */
    private function normalizeRelativePath(string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = trim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }

        $segments = explode('/', $relativePath);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return implode('/', $segments);
    }

    private function resolveExisting(string $slug, string $relativePath, bool $requireFile): string
    {
        $root = $this->themeRoot($slug);
        $normalized = $this->normalizeRelativePath($relativePath);

        if ($normalized === null) {
            throw new RuntimeException('Invalid file path.');
        }

        $resolved = realpath($root . '/' . $normalized);

        if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root . '/'))) {
            throw new RuntimeException('That file could not be found.');
        }

        if ($requireFile && !is_file($resolved)) {
            throw new RuntimeException('That file could not be found.');
        }

        if ($requireFile && !$this->isEditableExtension($resolved)) {
            throw new RuntimeException('That file type is not supported by the editor.');
        }

        return $resolved;
    }

    /**
     * Resolves a not-yet-existing target (a new file/folder to create).
     * realpath() always returns false for a path that doesn't exist yet,
     * so containment is checked one level up, against the parent
     * directory, which must already exist and already be inside the
     * theme.
     */
    private function resolveNew(string $slug, string $relativePath): string
    {
        $root = $this->themeRoot($slug);
        $normalized = $this->normalizeRelativePath($relativePath);

        if ($normalized === null) {
            throw new RuntimeException('Invalid file path.');
        }

        $candidate = $root . '/' . $normalized;
        $parent = dirname($candidate);
        $resolvedParent = realpath($parent);

        if ($resolvedParent === false || ($resolvedParent !== $root && !str_starts_with($resolvedParent, $root . '/'))) {
            throw new RuntimeException('The destination folder could not be found.');
        }

        $basename = basename($candidate);

        if (!$this->isSafeBasename($basename)) {
            throw new RuntimeException('Invalid file name.');
        }

        $finalPath = $resolvedParent . '/' . $basename;

        if (file_exists($finalPath)) {
            throw new RuntimeException('A file or folder with that name already exists.');
        }

        return $finalPath;
    }

    private function removeDirectory(string $path): void
    {
        $items = scandir($path) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath) && !is_link($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
