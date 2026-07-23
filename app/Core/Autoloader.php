<?php

declare(strict_types=1);

namespace LumoraPress\Core;

/**
 * Minimal PSR-4-style autoloader. No Composer dependency is required to
 * run Lumora Press, keeping it friendly to plain shared hosting.
 */
final class Autoloader
{
    /** @var array<string, string> */
    private array $prefixes = [];

    public function addNamespace(string $prefix, string $baseDirectory): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $this->prefixes[$prefix] = rtrim($baseDirectory, '/') . '/';
    }

    public function register(): void
    {
        spl_autoload_register($this->loadClass(...));
    }

    private function loadClass(string $class): void
    {
        foreach ($this->prefixes as $prefix => $baseDirectory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $baseDirectory . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
}
