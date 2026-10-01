<?php

/**
 * An export format that can build its file over several requests.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.20.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Export;

/**
 * Optional extension of ExportFormatWriter. The Export screen uses it
 * when uploaded files are included, so a large library is added in
 * batches with progress shown in place instead of in one long request.
 * The state is plain JSON-safe data kept on disk between requests; a
 * format without this interface still builds in a single request.
 */
interface StagedExportFormatWriter extends ExportFormatWriter
{
    /**
     * Starts the file and returns the state for the remaining work.
     *
     * @return array{path: string, fileName: string, total: int, cursor: int, missing: int, queue: array<int, array{0: string, 1: string}>}
     */
    public function begin(ExportContent $content, ExportOptions $options): array;

    /**
     * Does the next batch of work.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed> the updated state; finished once cursor reaches total
     */
    public function addBatch(array $state): array;

    /**
     * Returns the finished file's absolute path and sets notices().
     *
     * @param array<string, mixed> $state
     */
    public function finish(array $state): string;
}
