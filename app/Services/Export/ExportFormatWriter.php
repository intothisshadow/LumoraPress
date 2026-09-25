<?php

/**
 * The contract every content export format (Lumora Press ZIP, WordPress WXR, a plugin's own) implements.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Export;

/**
 * A writer only ever sees the neutral ExportContent payload, never the
 * live services, so adding a format can't touch how content is read.
 * Register one through the `export_formats` filter.
 */
interface ExportFormatWriter
{
    /** A stable machine id, e.g. 'lumora_press' or 'wxr'. */
    public function id(): string;

    /** Shown in the Export screen's format list. */
    public function label(): string;

    /** One sentence for the Export screen describing what this format is for. */
    public function description(): string;

    /**
     * The ExportOptions content types this format can represent; the
     * Export screen disables every other checkbox for it.
     *
     * @return array<int, string>
     */
    public function supportedContentTypes(): array;

    /** Whether the "Include uploaded files" option means anything for this format. */
    public function bundlesUploads(): bool;

    public function mimeType(): string;

    public function downloadFileName(ExportContent $content): string;

    /**
     * Writes the finished export file and returns its absolute path. The
     * caller owns the file afterward and deletes it once downloaded.
     */
    public function write(ExportContent $content, ExportOptions $options): string;

    /**
     * Human-readable notes from the most recent write() about content
     * the format couldn't carry — empty for a lossless format.
     *
     * @return array<int, string>
     */
    public function notices(): array;
}
