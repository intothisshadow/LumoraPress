<?php

/**
 * The list of available content export formats, extensible by plugins through the export_formats filter.
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

use LumoraPress\Core\Hooks\HookManager;

final class ExportFormatRegistry
{
    /** @var array<string, ExportFormatWriter> */
    private array $writers = [];

    /**
     * $hooks filters the writer list, so a plugin can add, replace, or
     * remove a format. Anything the filter returns that isn't an
     * ExportFormatWriter is ignored rather than breaking the screen.
     *
     * @param array<int, ExportFormatWriter> $writers
     */
    public function __construct(array $writers, ?HookManager $hooks = null)
    {
        $filtered = $hooks !== null ? $hooks->applyFilters('export_formats', $writers) : $writers;

        foreach (is_array($filtered) ? $filtered : $writers as $writer) {
            if ($writer instanceof ExportFormatWriter) {
                $this->writers[$writer->id()] = $writer;
            }
        }
    }

    /**
     * @return array<string, ExportFormatWriter>
     */
    public function all(): array
    {
        return $this->writers;
    }

    public function get(string $id): ?ExportFormatWriter
    {
        return $this->writers[$id] ?? null;
    }
}
