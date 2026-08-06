<?php

/**
 * The roles available to registered users, from most to least privileged.
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

/**
 * The roles available to registered users, from most to least privileged.
 * Guest represents an unauthenticated visitor and has no stored account.
 */
enum UserRole: string
{
    case Administrator = 'administrator';
    case Editor = 'editor';
    case Author = 'author';
    case Contributor = 'contributor';
    case Subscriber = 'subscriber';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::Editor => 'Editor',
            self::Author => 'Author',
            self::Contributor => 'Contributor',
            self::Subscriber => 'Subscriber',
            self::Guest => 'Guest',
        };
    }

    /**
     * @return array<int, string>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Administrator => [
                'manage_options', 'manage_users', 'manage_plugins', 'manage_themes',
                'edit_others_posts', 'publish_posts', 'edit_posts', 'delete_posts',
                'moderate_comments', 'upload_files',
            ],
            self::Editor => [
                'edit_others_posts', 'publish_posts', 'edit_posts', 'delete_posts',
                'moderate_comments', 'upload_files',
            ],
            self::Author => ['publish_posts', 'edit_posts', 'delete_posts', 'upload_files'],
            self::Contributor => ['edit_posts'],
            self::Subscriber, self::Guest => [],
        };
    }

    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }
}
