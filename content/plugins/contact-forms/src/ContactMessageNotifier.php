<?php

/**
 * Tells Administrators in the Notifications inbox when a contact form message arrives.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\ContactForms;

use LumoraPress\Services\NotificationService;
use LumoraPress\Services\UserService;
use Throwable;

/**
 * One running unread notice per Administrator, not one per message: while it is unread, each new
 * message only rewrites its count, so a bot or a busy day cannot flood the inbox. Reading the
 * notice ends the group and the next message starts a fresh one. The count is the number of
 * unread, non-spam messages, which is also what the Submissions screen shows.
 */
final class ContactMessageNotifier
{
    public const TYPE = 'contact_message';

    public function __construct(
        private readonly ContactSubmissionService $submissions,
        private readonly NotificationService $notifications,
        private readonly UserService $users,
    ) {
    }

    /**
     * Call after a non-spam submission is stored. Never throws: the visitor's message is already
     * saved, so a notification problem must not turn their success page into an error.
     */
    public function notify(ContactForm $form): void
    {
        try {
            $unread = $this->submissions->countUnread();
            $message = $unread === 1
                ? sprintf(__('New message from the contact form "%s"'), $form->title)
                : sprintf(__('%d unread contact form messages'), $unread);
            $url = admin_url('contact-forms/submissions') . '?status=inbox' . ($unread === 1 ? '&form_id=' . $form->id : '');

            foreach ($this->users->listAll() as $user) {
                if (!$user->can('manage_options') || in_array(self::TYPE, $this->notifications->mutedTypes($user->id), true)) {
                    continue;
                }

                $existing = $this->notifications->findUnreadOfType($user->id, self::TYPE);

                if ($existing !== null) {
                    $this->notifications->updateUnread((int) $existing['id'], $user->id, $message, $url);
                } else {
                    $this->notifications->notify($user->id, self::TYPE, $message, $url);
                }
            }
        } catch (Throwable $exception) {
            error_log('[ContactForms] ' . $exception::class . ': ' . $exception->getMessage());
        }
    }
}
