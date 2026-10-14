<?php

/**
 * Turns events that would otherwise go unnoticed (a post awaiting review, a new version, failing mail) into inbox notifications.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\PressConfig;
use LumoraPress\Models\Page;
use LumoraPress\Models\Post;
use Throwable;

/**
 * Every public method is safe to call from a hook: a failure to notify is logged and swallowed,
 * never allowed to break the save, update check or mail send that triggered it.
 */
final class NotificationEventService
{
    private const MAIL_FAILED_INTERVAL_SECONDS = 3600;

    /**
     * @param \Closure(): ?int $currentUserId The signed-in user, so people are not notified about their own actions.
     */
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly UserService $users,
        private readonly PressConfig $config,
        private readonly \Closure $currentUserId,
    ) {
    }

    public function onPostSaved(Post $post, ?Post $previous = null): void
    {
        $this->guard(fn () => $this->reviewTransition(
            'post',
            $post->id,
            $post->title,
            $post->authorId,
            $post->status->value,
            $previous?->status->value,
            static fn (): string => post_permalink($post),
        ));
    }

    public function onPageSaved(Page $page, ?Page $previous = null): void
    {
        $this->guard(fn () => $this->reviewTransition(
            'page',
            $page->id,
            $page->title,
            $page->authorId,
            $page->status->value,
            $previous?->status->value,
            static fn (): string => page_permalink($page),
        ));
    }

    /**
     * One notification per new version per component. The version last announced is remembered
     * in options so re-checking, or checking from another screen, never repeats it.
     *
     * @param array{latest_version: string, plugins?: array<string, array{version: string}>, themes?: array<string, array{version: string}>} $release
     * @param array<string, string> $installedPlugins slug => installed version
     * @param array<string, string> $installedThemes slug => installed version
     */
    public function announceUpdates(array $release, string $installedCore, array $installedPlugins, array $installedThemes): void
    {
        $this->guard(function () use ($release, $installedCore, $installedPlugins, $installedThemes): void {
            $latest = (string) $release['latest_version'];
            $announcedCore = (string) $this->config->option('notification_announced_core', '');

            if (version_compare($latest, $installedCore, '>') && ($announcedCore === '' || version_compare($latest, $announcedCore, '>'))) {
                $this->notifyAdministrators(
                    'update_available',
                    sprintf(__('Lumora Press %s is available'), $latest),
                    admin_url('maintenance/updates'),
                );
                $this->config->setOption('notification_announced_core', $latest);
            }

            $this->announceComponents('plugin', $release['plugins'] ?? [], $installedPlugins, 'notification_announced_plugins', admin_url('plugins'));
            $this->announceComponents('theme', $release['themes'] ?? [], $installedThemes, 'notification_announced_themes', admin_url('appearance/themes'));
        });
    }

    /**
     * Called whenever Mailer::send() returns false. At most one notification an hour, so a broken
     * mail setup cannot flood the inbox; the rate is kept in an option rather than memory because
     * every failing request would otherwise start from zero.
     */
    public function onMailFailed(): void
    {
        $this->guard(function (): void {
            $lastAt = (int) $this->config->option('notification_mail_failed_at', '0');

            if (time() - $lastAt < self::MAIL_FAILED_INTERVAL_SECONDS) {
                return;
            }

            // Recorded first so a slow or failing notify still cannot be retried by the next failure.
            $this->config->setOption('notification_mail_failed_at', (string) time());

            $this->notifyAdministrators(
                'mail_failed',
                __('The site could not send an email. Password resets, comment alerts and sign-up confirmations may not be reaching anyone.'),
                admin_url('maintenance/system-information'),
            );
        });
    }

    /**
     * @param \Closure(): string $publicUrl
     */
    private function reviewTransition(string $kind, int $id, string $title, int $authorId, string $status, ?string $previousStatus, \Closure $publicUrl): void
    {
        if ($status === $previousStatus) {
            return;
        }

        $editUrl = admin_url($kind === 'post' ? 'posts/new' : 'pages/new') . '?id=' . $id;

        if ($status === 'pending_review') {
            $author = $this->users->findById($authorId);
            $message = $kind === 'post'
                ? sprintf(__('"%1$s" was submitted for review by %2$s'), $title, $author->displayName ?? __('a contributor'))
                : sprintf(__('The page "%1$s" was submitted for review by %2$s'), $title, $author->displayName ?? __('a contributor'));

            $this->notifications->notifyUsersWithCapability($this->users, 'edit_others_posts', $kind . '_pending_review', $message, $editUrl, $authorId);

            return;
        }

        if ($previousStatus !== 'pending_review' || ($this->currentUserId)() === $authorId || $this->users->findById($authorId) === null) {
            return;
        }

        [$message, $url] = match ($status) {
            'published' => [
                $kind === 'post' ? sprintf(__('Your post "%s" was published'), $title) : sprintf(__('Your page "%s" was published'), $title),
                $publicUrl(),
            ],
            'scheduled' => [
                $kind === 'post' ? sprintf(__('Your post "%s" was scheduled for publication'), $title) : sprintf(__('Your page "%s" was scheduled for publication'), $title),
                $editUrl,
            ],
            'draft' => [
                $kind === 'post' ? sprintf(__('Your post "%s" was returned to Draft'), $title) : sprintf(__('Your page "%s" was returned to Draft'), $title),
                $editUrl,
            ],
            default => [null, ''],
        };

        if ($message !== null) {
            $this->notifications->notify($authorId, $kind . '_review_outcome', $message, $url);
        }
    }

    /**
     * @param array<string, array{version: string}> $available
     * @param array<string, string> $installed
     */
    private function announceComponents(string $kind, array $available, array $installed, string $optionKey, string $url): void
    {
        $decoded = json_decode((string) $this->config->option($optionKey, ''), true);
        $announced = is_array($decoded) ? $decoded : [];
        $changed = false;

        foreach ($available as $slug => $component) {
            $version = (string) ($component['version'] ?? '');
            $previouslyAnnounced = (string) ($announced[$slug] ?? '');

            if ($version === '' || !isset($installed[$slug]) || !version_compare($version, $installed[$slug], '>')) {
                continue;
            }

            if ($previouslyAnnounced !== '' && !version_compare($version, $previouslyAnnounced, '>')) {
                continue;
            }

            $this->notifyAdministrators(
                'update_available',
                $kind === 'plugin'
                    ? sprintf(__('Version %2$s of the plugin "%1$s" is available'), $slug, $version)
                    : sprintf(__('Version %2$s of the theme "%1$s" is available'), $slug, $version),
                $url,
            );
            $announced[$slug] = $version;
            $changed = true;
        }

        if ($changed) {
            $this->config->setOption($optionKey, (string) json_encode($announced));
        }
    }

    private function notifyAdministrators(string $type, string $message, string $url): void
    {
        $this->notifications->notifyUsersWithCapability($this->users, 'manage_options', $type, $message, $url);
    }

    private function guard(\Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            error_log('[NotificationEventService] ' . $exception::class . ': ' . $exception->getMessage());
        }
    }
}
