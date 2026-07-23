<?php

declare(strict_types=1);

namespace LumoraPress\Core;

/**
 * Checks the PHP version, required extensions, and writable-directory
 * prerequisites before the installer attempts anything else — so a
 * missing `pdo_mysql` extension or an unwritable `config/` directory
 * produces a clear, specific message instead of a generic "Installation
 * failed: ..." further down the wizard.
 *
 * The PHP version and extension-loaded check are constructor-injectable
 * (defaulting to the real `PHP_VERSION` constant and `extension_loaded()`)
 * purely so both failure branches are unit-testable without needing to
 * actually run under an unsupported PHP version or with an extension
 * disabled — production code never needs to pass either argument.
 */
final class RequirementsCheck
{
    private const MINIMUM_PHP_VERSION = '8.2.0';

    /** @var array<int, string> */
    private const REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'session', 'json'];

    public function __construct(
        private readonly string $root,
        private readonly string $phpVersion = PHP_VERSION,
        private readonly ?\Closure $extensionLoaded = null,
    ) {
    }

    /**
     * @return array<int, string> Human-readable problem descriptions.
     *                            An empty array means every requirement is met.
     */
    public function check(): array
    {
        $problems = [];

        if (version_compare($this->phpVersion, self::MINIMUM_PHP_VERSION, '<')) {
            $problems[] = sprintf(
                'PHP %s or newer is required (this server is running PHP %s).',
                self::MINIMUM_PHP_VERSION,
                $this->phpVersion,
            );
        }

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!$this->isExtensionLoaded($extension)) {
                $problems[] = "The PHP \"{$extension}\" extension is required but not enabled.";
            }
        }

        foreach ($this->requiredDirectories() as $label => $path) {
            if (!is_dir($path)) {
                $problems[] = "The \"{$label}\" directory does not exist ({$path}).";
            } elseif (!is_writable($path)) {
                $problems[] = "The \"{$label}\" directory is not writable by the web server ({$path}).";
            }
        }

        return $problems;
    }

    private function isExtensionLoaded(string $extension): bool
    {
        return ($this->extensionLoaded ?? extension_loaded(...))($extension);
    }

    /**
     * @return array<string, string> label => absolute path
     */
    private function requiredDirectories(): array
    {
        return [
            'config' => $this->root . '/config',
            'storage/logs' => $this->root . '/storage/logs',
            'storage/sessions' => $this->root . '/storage/sessions',
            'storage/cache' => $this->root . '/storage/cache',
            'content/uploads' => $this->root . '/content/uploads',
        ];
    }
}
