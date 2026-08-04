<?php

declare(strict_types=1);

namespace LumoraPress\Core;

use LumoraPress\Services\EditorPreferenceService;
use RuntimeException;

/**
 * Static bridge exposing the bootstrapped EditorPreferenceService to the
 * procedural get_default_editor()/get_active_editor()/registered_editors()
 * helpers (LP-066/LP-067) — mirrors ActiveConfig/ActiveTheme: admin views
 * reach the service directly via $kernel->editorPreferences, but a theme
 * or plugin has no such route to Kernel-wired services of its own.
 */
final class ActiveEditorPreference
{
    private static ?EditorPreferenceService $instance = null;

    public static function set(EditorPreferenceService $service): void
    {
        self::$instance = $service;
    }

    public static function instance(): EditorPreferenceService
    {
        if (self::$instance === null) {
            throw new RuntimeException('Editor preference service has not been initialized.');
        }

        return self::$instance;
    }
}
