<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\ContentFormat;

/**
 * Centralizes "which content editor should this screen open in" (LP-066
 * Site Default Editor, LP-067 Allow Users to Choose Editor — merged from
 * LP-065, see DECISIONS.md). Two options live in PressConfig
 * ('default_editor', the site-wide choice; 'lock_editor_to_default',
 * whether per-user overrides are honored at all); a user's own choice
 * lives on their own row (`users.preferred_editor`, null meaning "use the
 * site default" — see UserService::updateEditorPreference()).
 *
 * Precedence (activeEditor()): locked → site default; not locked and the
 * user has a registered preference → that preference; otherwise → site
 * default.
 */
final class EditorPreferenceService
{
    private const OPTION_DEFAULT_EDITOR = 'default_editor';

    private const OPTION_LOCK_TO_DEFAULT = 'lock_editor_to_default';

    public function __construct(
        private readonly PressConfig $config,
        private readonly UserService $users,
        private readonly HookManager $hooks,
    ) {
    }

    /**
     * Every editor a post/page can be authored in — core's three plus
     * anything a plugin added via the 'registered_editors' filter (LP-066's
     * "editors registered by plugins" Technical Note). Value/label pairs
     * rather than ContentFormat instances directly: a plugin can't add a
     * new case to a native PHP enum, so a plain array is the only shape
     * that's actually extensible. Core content is still always rendered
     * via ContentFormat, though — a plugin-added "editor" is expected to
     * ultimately produce Markdown, HTML, or Plain-formatted content;
     * this list is about which authoring UI is offered, not a fourth
     * storage format.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function registeredEditors(): array
    {
        $builtIn = array_map(
            static fn (ContentFormat $format): array => ['value' => $format->value, 'label' => $format->label()],
            ContentFormat::cases(),
        );

        return $this->hooks->applyFilters('registered_editors', $builtIn);
    }

    public function isRegistered(ContentFormat $format): bool
    {
        foreach ($this->registeredEditors() as $editor) {
            if ($editor['value'] === $format->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The site-wide default editor. Falls back to Plain Text — never to a
     * hardcoded Markdown assumption — when nothing is configured yet
     * would resolve to an unregistered/disabled editor (LP-066's "Gracefully
     * fall back to the Plain Text Editor if the configured editor is
     * unavailable or disabled"). A fresh install with no 'default_editor'
     * option set yet defaults to Markdown, matching this app's pre-existing
     * hardcoded new-post behavior, so upgrading an existing site changes
     * nothing until an administrator visits Settings > General.
     */
    public function defaultEditor(): ContentFormat
    {
        $configured = ContentFormat::tryFrom((string) $this->config->option(self::OPTION_DEFAULT_EDITOR, ContentFormat::Markdown->value));

        if ($configured !== null && $this->isRegistered($configured)) {
            return $configured;
        }

        return ContentFormat::Plain;
    }

    public function isLockedToDefault(): bool
    {
        return (string) $this->config->option(self::OPTION_LOCK_TO_DEFAULT, '0') === '1';
    }

    /**
     * The editor a given user's next new post/page should open in — see
     * this class's own docblock for the precedence order. $userId is
     * nullable so call sites that don't have an authenticated user in
     * scope (unlikely in admin, but cheaper than forcing every caller to
     * null-check first) still get a sane answer.
     */
    public function activeEditor(?int $userId): ContentFormat
    {
        if ($this->isLockedToDefault() || $userId === null) {
            return $this->defaultEditor();
        }

        $user = $this->users->findById($userId);

        if ($user?->preferredEditor !== null && $this->isRegistered($user->preferredEditor)) {
            return $user->preferredEditor;
        }

        return $this->defaultEditor();
    }

    public function saveSiteSettings(ContentFormat $defaultEditor, bool $lockToDefault): void
    {
        $this->config->setOption(self::OPTION_DEFAULT_EDITOR, $defaultEditor->value);
        $this->config->setOption(self::OPTION_LOCK_TO_DEFAULT, $lockToDefault ? '1' : '0');
    }
}
