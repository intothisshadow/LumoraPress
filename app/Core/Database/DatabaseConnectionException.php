<?php

/**
 * Thrown when Database::connect() cannot reach the configured MySQL/MariaDB server.
 *
 * @package LumoraPress
 * @subpackage Database
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Database;

use RuntimeException;

final class DatabaseConnectionException extends RuntimeException
{
}
