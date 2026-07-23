<?php

declare(strict_types=1);

namespace LumoraPress\Core\Errors;

use ErrorException;
use Throwable;

/**
 * Central error and exception handler.
 *
 * Converts PHP errors into exceptions, logs all failures to disk, and
 * renders either a generic public message or a detailed debug report
 * depending on configuration. Never exposes stack traces or filesystem
 * paths to visitors outside of debug mode.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly string $logDirectory,
        private readonly bool $debug = false,
    ) {
    }

    public function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_error_handler($this->handleError(...));
        set_exception_handler($this->handleException(...));
        register_shutdown_function($this->handleShutdown(...));
    }

    /**
     * @throws ErrorException
     */
    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleException(Throwable $exception): void
    {
        $this->log($exception);
        $this->render($exception);
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null) {
            return;
        }

        $fatalSeverities = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

        if (!in_array($error['type'], $fatalSeverities, true)) {
            return;
        }

        $exception = new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line'],
        );

        $this->log($exception);
        $this->render($exception);
    }

    private function log(Throwable $exception): void
    {
        $entry = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString(),
        );

        $logFile = rtrim($this->logDirectory, '/') . '/error.log';

        if (is_dir($this->logDirectory) && is_writable($this->logDirectory)) {
            error_log($entry, 3, $logFile);
        }
    }

    private function render(Throwable $exception): void
    {
        if (!headers_sent()) {
            http_response_code(500);
        }

        echo $this->debug ? $this->renderDebug($exception) : $this->renderFriendly();
    }

    private function renderFriendly(): string
    {
        return <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>Something went wrong</title>
            </head>
            <body>
                <h1>Something went wrong</h1>
                <p>An unexpected error occurred. Please try again later.</p>
            </body>
            </html>
            HTML;
    }

    private function renderDebug(Throwable $exception): string
    {
        $class = htmlspecialchars($exception::class, ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $exception->getLine();
        $trace = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>{$class}: {$message}</title>
            </head>
            <body>
                <h1>{$class}</h1>
                <p>{$message}</p>
                <p><code>{$file}:{$line}</code></p>
                <pre>{$trace}</pre>
            </body>
            </html>
            HTML;
    }
}
