<?php

declare(strict_types=1);

namespace LumoraPress\Models;

/**
 * The outcome of a single manual update attempt, recorded in the
 * update_log table.
 */
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
