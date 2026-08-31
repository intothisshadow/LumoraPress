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
         * LP-066/LP-067: null means "use the site default editor" — see
         * EditorPreferenceService::activeEditor().
         */
        public readonly ?ContentFormat $preferredEditor = null,
        /**
         * LP-032: non-null means this account is in the Trash (soft
         * deleted). A trashed user cannot authenticate — see
         * UserService::verifyCredentials() and Auth::user().
         */
        public readonly ?\DateTimeImmutable $trashedAt = null,
        /**
         * LP-032: an uploaded avatar's media id, or null to fall back to
         * Gravatar — see UserService::gravatarUrl().
         */
        public readonly ?int $avatarMediaId = null,
        /**
         * LP-083: raw JSON-encoded box order/collapse state for the Post
         * and Page editor sidebars, keyed by screen type. Kept as an
         * undecoded string here — UserService::getEditorLayoutPreferences()
         * decodes and slices it per screen type, since only the caller
         * knows which screen it's asking about.
         */
        public readonly ?string $editorLayoutPreferences = null,
        /**
         * LP-087: the admin color scheme this user chose. Always has a
         * value (defaults to Auto — follow the OS/browser setting) since,
         * unlike preferredEditor, there is no site-wide theme setting to
         * defer to via null.
         */
        public readonly ThemePreference $themePreference = ThemePreference::Auto,
        /**
         * LP-097/LP-098: raw JSON-encoded Grid/List view-mode choice per
         * admin list screen ('media', 'plugins', ...), the same
         * one-JSON-blob-column-keyed-by-screen-type shape
         * editorLayoutPreferences uses above. Kept as an undecoded string
         * here — UserService::getListViewMode() decodes and slices it per
         * screen, since only the caller knows which screen it's asking
         * about.
         */
        public readonly ?string $listViewPreferences = null,
        /**
         * LP-120: raw JSON-encoded array of Media Manager folder ids this
         * user has collapsed in the sidebar folder tree — absent ids are
         * expanded by default. Kept as an undecoded string here —
         * UserService::getCollapsedMediaFolders() decodes it, since
         * nothing else in this model needs to inspect it.
         */
        public readonly ?string $folderTreeState = null,
        /**
         * LPP-006: raw JSON-encoded list of this user's most recently
         * inserted emoji (newest first), for the Emoji Picker's "Recently
         * used" category. Kept as an undecoded string here — the same
         * "only the caller decodes it" reasoning folderTreeState/
         * listViewPreferences above already use — since this model has no
         * business logic of its own.
         */
        public readonly ?string $recentEmoji = null,
    ) {
    }

    public function can(string $capability): bool
    {
        return $this->role->can($capability);
    }
}
