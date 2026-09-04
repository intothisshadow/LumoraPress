<?php

/**
 * The outcome of a single manual update attempt, as recorded in the update log.
 *
 * @package LumoraPress
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Models;

enum UpdateStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::RolledBack => 'Rolled Back',
        };
    }
}
