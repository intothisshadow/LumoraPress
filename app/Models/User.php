<?php

/**
 * The User domain model.
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

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly string $displayName,
        public readonly UserRole $role,
        /**
         * Null means "use the site default editor" — see
         * EditorPreferenceService::activeEditor().
         */
        public readonly ?ContentFormat $preferredEditor = null,
        /**
         * Non-null means this account is in the Trash (soft deleted). A
         * trashed user cannot authenticate — see
         * UserService::verifyCredentials() and Auth::user().
         */
        public readonly ?\DateTimeImmutable $trashedAt = null,
        /**
         * An uploaded avatar's media id, or null to fall back to Gravatar
         * — see UserService::gravatarUrl().
         */
        public readonly ?int $avatarMediaId = null,
        /**
         * Raw JSON-encoded box order/collapse state for the Post and Page
         * editor sidebars, keyed by screen type — kept undecoded here;
         * see UserService::getEditorLayoutPreferences().
         */
        public readonly ?string $editorLayoutPreferences = null,
        /**
         * The admin color scheme this user chose. Always has a value
         * (defaults to Auto) since, unlike preferredEditor, there's no
         * site-wide theme setting to defer to via null.
         */
        public readonly ThemePreference $themePreference = ThemePreference::Auto,
        /**
         * Raw JSON-encoded Grid/List view-mode choice per admin list
         * screen — kept undecoded here; see UserService::getListViewMode().
         */
        public readonly ?string $listViewPreferences = null,
        /**
         * Raw JSON-encoded Media Manager folder ids collapsed in the
         * sidebar tree — absent ids are expanded by default. Kept
         * undecoded here; see UserService::getCollapsedMediaFolders().
         */
        public readonly ?string $folderTreeState = null,
        /**
         * Raw JSON-encoded list of this user's most recently inserted
         * emoji (newest first), for the Emoji Picker's "Recently used"
         * category. Kept undecoded here for the same reason as above.
         */
        public readonly ?string $recentEmoji = null,
    ) {
    }

    public function can(string $capability): bool
    {
        return $this->role->can($capability);
    }
}
