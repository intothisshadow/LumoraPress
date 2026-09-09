<?php

/**
 * Validates, stores, and serves file uploads from a FileUpload-type Contact Form field.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.11.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\ContactForms;

use Closure;
use RuntimeException;

/**
 * Uploads land under storage/contact-form-uploads/{formId}/, never under
 * content/uploads/ — an anonymous, un-moderated public submission has no
 * business appearing in the Media Library alongside an administrator's own
 * curated files. storage/ is already denied to direct web access by its own
 * .htaccess (see storage/sessions/logs), so a stored file is only ever
 * reachable through the authenticated admin download route in
 * admin/views/contact-forms/submissions.php.
 *
 * A deliberately small, fixed allow-list (not MediaService's own, much
 * wider one) since these come from anonymous, unauthenticated visitors
 * rather than a logged-in Administrator/Editor.
 */
final class ContactFormUploadService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'zip'];

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'application/zip',
    ];

    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    private readonly Closure $moveUploadedFile;

    /**
     * @param Closure(string, string): bool|null $moveUploadedFile Overrides the
     *     default move_uploaded_file() call for tests — PHP's real
     *     move_uploaded_file() only ever succeeds against a file that
     *     arrived via an actual HTTP upload, the same reason MediaService
     *     takes this same override.
     */
    public function __construct(
        private readonly string $storageRoot,
        ?Closure $moveUploadedFile = null,
    ) {
        $this->moveUploadedFile = $moveUploadedFile ?? move_uploaded_file(...);
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @throws RuntimeException When the file is missing, oversized, or not an allowed type.
     */
    public function store(int $formId, array $file): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed.');
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new RuntimeException('File exceeds the maximum upload size.');
        }

        $extension = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('File type is not allowed.');
        }

        $mimeType = mime_content_type($file['tmp_name']) ?: '';

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('File type is not allowed.');
        }

        $originalName = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', pathinfo($file['name'], PATHINFO_FILENAME)) ?: 'file';
        $originalName = substr($originalName, 0, 60);
        $storedName = bin2hex(random_bytes(8)) . '-' . $originalName . '.' . $extension;

        $directory = $this->formDirectory($formId);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the upload directory.');
        }

        if (!($this->moveUploadedFile)($file['tmp_name'], $directory . '/' . $storedName)) {
            throw new RuntimeException('Unable to store the uploaded file.');
        }

        return $storedName;
    }

    /**
     * Null when $storedName doesn't resolve to a real file — a stale
     * reference (the file was deleted from disk out of band) rather than an
     * error, since callers only display or stream what actually exists.
     */
    public function resolvePath(int $formId, string $storedName): ?string
    {
        // basename() strips any path traversal attempt out of a value that
        // ultimately originates from stored submission data.
        $path = $this->formDirectory($formId) . '/' . basename($storedName);

        return is_file($path) ? $path : null;
    }

    public function delete(int $formId, string $storedName): void
    {
        $path = $this->resolvePath($formId, $storedName);

        if ($path !== null) {
            unlink($path);
        }
    }

    /**
     * Called when a form itself is deleted, mirroring
     * ContactSubmissionService::deleteByFormId() — an orphaned upload
     * directory would otherwise linger on disk forever.
     */
    public function deleteAllForForm(int $formId): void
    {
        $directory = $this->formDirectory($formId);

        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            unlink($directory . '/' . $entry);
        }

        rmdir($directory);
    }

    private function formDirectory(int $formId): string
    {
        return rtrim($this->storageRoot, '/') . '/' . $formId;
    }
}
