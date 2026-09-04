<?php

/**
 * Static bridge exposing the bootstrapped EditorPreferenceService to the procedural editor-preference helpers (LP-066/LP-067).
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core;

use LumoraPress\Services\EditorPreferenceService;
use RuntimeException;

/**
 * Static bridge exposing the bootstrapped EditorPreferenceService to the
 * procedural editor-preference helpers — a theme or plugin has no other
 * route to Kernel-wired services of its own.
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
