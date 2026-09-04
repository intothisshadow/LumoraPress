<?php

/**
 * Consistent JSON envelope for the REST API (LP-021), used by every ApiController endpoint.
 *
 * @package LumoraPress
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Http;

/**
 * Consistent JSON envelope for the REST API. Every ApiController endpoint
 * goes through these two methods so the response shape never drifts
 * per-endpoint.
 *
 * Success: {"data": ...} for a single resource, {"data": [...], "meta": {...}}
 * for a paginated collection. Failure: {"error": {"message": ..., "code": ...}}.
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed>|null $meta
     */
    public static function json(mixed $data, int $status = 200, ?array $meta = null): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');

        $body = ['data' => $data];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }

    public static function error(string $message, int $status, ?string $code = null): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode([
            'error' => [
                'message' => $message,
                'code' => $code ?? self::defaultCodeForStatus($status),
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    private static function defaultCodeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            422 => 'unprocessable',
            default => 'error',
        };
    }
}
