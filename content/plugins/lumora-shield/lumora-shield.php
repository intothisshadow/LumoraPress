<?php

/**
 * The Lumora Shield plugin's main file (LPP-001): plugin metadata header and bootstrap.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

/*
 * Plugin Name: Lumora Shield
 * Plugin URI: https://lumorapress.org/plugins/lumora-shield
 * Description: Optional hardening and spam-prevention features beyond core's own basics — Stop User Enumeration (an optional "hide every author archive entirely" setting; the underlying username-existence oracle itself is closed unconditionally in core), Monitoring (logs blocked enumeration attempts, with optional email alerts), Comment Analysis (content/behavioral spam heuristics feeding the existing comment_is_spam filter), and Contact Form Protection (the same content heuristics feeding the Contact Forms plugin's own contact_form_is_spam filter).
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: security, spam, hardening
 * Requires at least: 0.4.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\LumoraShield;

require_once __DIR__ . '/src/LumoraShieldService.php';
require_once __DIR__ . '/src/CommentAnalyzer.php';

$lumoraShield = LumoraShieldService::instance();

/*
 * SiteController::author() calls this filter (default true, a no-op when
 * this plugin isn't active) after already 404ing a zero-published-post
 * author unconditionally in core — this listener only ever has one more
 * thing to decide: whether the administrator opted into hiding every
 * author archive outright (see LumoraShieldService::authorArchiveVisible()'s
 * own docblock).
 */
add_filter(
    'lumora_shield_author_archive_visible',
    static fn (bool $visible, \LumoraPress\Models\User $author, int $publishedPostCount): bool
        => $lumoraShield->authorArchiveVisible($visible, $publishedPostCount),
);

/*
 * Monitoring: SiteController::author() fires this on every blocked
 * request (a no-op unless something listens) — recorded here so an
 * administrator can see who's probing for valid usernames, subject to
 * this plugin's own logging/retention settings.
 */
add_action(
    'lumora_shield_enumeration_blocked',
    static function (string $slug, string $reason, string $ipAddress) use ($lumoraShield): void {
        $lumoraShield->recordEnumerationAttempt($slug, $reason, $ipAddress);
    },
);

/*
 * Comment Analysis: the same 'comment_is_spam' filter Akismet already
 * uses (SiteController::submitComment()/::submitPageComment(),
 * ApiController's comment-creation handler) — see
 * LumoraShieldService::commentIsSpam()'s own docblock.
 */
add_filter(
    'comment_is_spam',
    static fn (bool $default, string $guestName, string $guestEmail, ?string $guestUrl, string $content, string $ipAddress): bool
        => $lumoraShield->commentIsSpam($default, $guestName, $guestEmail, $guestUrl, $content, $ipAddress),
);

/*
 * Contact Form Protection: the Contact Forms plugin's own
 * 'contact_form_is_spam' filter (ContactFormSubmissionHandler::handle(),
 * the one gap that plugin's own already-thorough CSRF/honeypot/
 * FormTiming/rate-limit/CAPTCHA/Akismet stack didn't cover) — see
 * LumoraShieldService::contactFormIsSpam()'s own docblock. Registering
 * this listener is harmless even when Contact Forms itself is inactive,
 * since apply_filters() is simply never called by anything in that case.
 */
add_filter(
    'contact_form_is_spam',
    static fn (bool $default, array $data, string $ipAddress): bool
        => $lumoraShield->contactFormIsSpam($default, $data, $ipAddress),
);
