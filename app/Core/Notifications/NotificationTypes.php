<?php

/**
 * The registry of notification types the inbox, the type filter and My Profile's mute list all read from.
 *
 * @package LumoraPress
 * @subpackage Notifications
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Notifications;

/**
 * Static so a plugin can call register_notification_type() from its main file, before any
 * service exists, the same shape as ThemeSupport. A type that was never registered still
 * displays (as its raw name, with no action button) so a plugin that is later deactivated
 * never leaves unreadable rows behind.
 */
final class NotificationTypes
{
    /** @var array<string, array{label: string, actionLabel: string, icon: string, capability: ?string, target: ?string, description: string}> */
    private static array $types = [];

    /**
     * @param string $actionLabel Button text for the notification's destination ("Review comments"); empty means the notification never shows a button.
     * @param ?string $capability Capability needed to open the destination and to receive this type; null means everyone who gets one.
     * @param ?string $target 'post_edit' or 'page_edit' when the link is an edit screen whose item can be deleted; null skips the existence check.
     * @param string $description Sentence shown next to the mute checkbox on My Profile; defaults to the label.
     */
    public static function register(
        string $type,
        string $label,
        string $actionLabel = '',
        string $icon = '',
        ?string $capability = null,
        ?string $target = null,
        string $description = '',
    ): void {
        $type = substr($type, 0, 50);

        if ($type === '') {
            return;
        }

        self::$types[$type] = [
            'label' => $label,
            'actionLabel' => $actionLabel,
            'icon' => $icon,
            'capability' => $capability,
            'target' => $target,
            'description' => $description !== '' ? $description : $label,
        ];
    }

    public static function has(string $type): bool
    {
        return isset(self::$types[$type]);
    }

    /**
     * @return array{label: string, actionLabel: string, icon: string, capability: ?string, target: ?string, description: string}|null
     */
    public static function get(string $type): ?array
    {
        return self::$types[$type] ?? null;
    }

    /**
     * @return array<string, array{label: string, actionLabel: string, icon: string, capability: ?string, target: ?string, description: string}>
     */
    public static function all(): array
    {
        return self::$types;
    }

    /** The registered label, or the raw type name for one nobody registered. */
    public static function label(string $type): string
    {
        return self::$types[$type]['label'] ?? $type;
    }

    public static function registerCore(): void
    {
        $core = [
            ['comment_reply', __('Reply to your comment'), __('View comment'), '↩️', null, null, __('Someone replied to a comment you wrote')],
            ['comment_mention', __('Mention in a comment'), __('View comment'), '@', null, null, __('Someone mentioned you in a comment')],
            ['comment_on_your_content', __('Comment on your content'), __('View comment'), '💬', null, null, __('Someone commented on your post or page')],
            ['comment_on_watched', __('Comment on a watched thread'), __('View comment'), '👁️', null, null, __('Someone commented on a thread you are watching')],
            ['comment_approved', __('Your comment was approved'), __('View comment'), '✅', null, null, __('A comment of yours that was held for moderation was approved')],
            ['comment_moderation', __('Comment awaiting moderation'), __('Review comments'), '🛡️', 'moderate_comments', null, __('A comment is waiting for moderation')],
            ['comment_reported', __('Reported comment'), __('Review comments'), '🚩', 'moderate_comments', null, __('Visitors reported a comment')],
            ['post_pending_review', __('Post submitted for review'), __('Review post'), '📝', 'edit_others_posts', 'post_edit', __('A post was submitted for review')],
            ['page_pending_review', __('Page submitted for review'), __('Review page'), '📄', 'edit_others_posts', 'page_edit', __('A page was submitted for review')],
            ['post_review_outcome', __('Outcome of a post review'), __('Open post'), '📬', null, 'post_edit', __('A post you submitted was published, scheduled, or sent back to draft')],
            ['page_review_outcome', __('Outcome of a page review'), __('Open page'), '📬', null, 'page_edit', __('A page you submitted was published, scheduled, or sent back to draft')],
            ['update_available', __('Update available'), __('View update'), '🔔', 'manage_options', null, __('A new version of Lumora Press, a plugin, or a theme is available')],
            ['mail_failed', __('Outgoing mail is failing'), __('Open System Information'), '✉️', 'manage_options', null, __('The site could not send an email')],
            ['registration_awaiting_approval', __('Sign-up awaiting approval'), __('Review sign-ups'), '🪪', 'manage_users', null, __('Someone signed up and is waiting for approval')],
            ['registration_paused', __('Sign-ups paused'), __('Open Registration settings'), '⏸️', 'manage_users', null, __('Sign-ups were paused because of a burst of submissions')],
        ];

        foreach ($core as [$type, $label, $actionLabel, $icon, $capability, $target, $description]) {
            self::register($type, $label, $actionLabel, $icon, $capability, $target, $description);
        }
    }

    public static function reset(): void
    {
        self::$types = [];
    }
}
