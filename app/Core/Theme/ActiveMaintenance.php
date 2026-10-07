<?php

/**
 * Static bridge exposing the maintenance gate's status to the is_maintenance_mode() template tag.
 *
 * @package LumoraPress
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.21.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use LumoraPress\Core\Http\MaintenanceGate;

/**
 * Same shape as ActiveAuth. Returns false before bootstrap has set the
 * gate, so a template tag called early never throws.
 */
final class ActiveMaintenance
{
    private static ?MaintenanceGate $gate = null;

    private static bool $rendering = false;

    public static function set(MaintenanceGate $gate): void
    {
        self::$gate = $gate;
    }

    /**
     * Set by the gate around rendering the maintenance page (including a
     * preview), so shared templates such as header.php can adapt.
     */
    public static function setRendering(bool $rendering): void
    {
        self::$rendering = $rendering;
    }

    public static function isRendering(): bool
    {
        return self::$rendering;
    }

    public static function isActive(): bool
    {
        return self::$gate !== null && self::$gate->isActive();
    }
}
