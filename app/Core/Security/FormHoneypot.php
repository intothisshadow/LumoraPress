<?php

/**
 * Hidden honeypot fields that catch bots filling in every input they find.
 *
 * @package LumoraPress
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.21.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Security;

/**
 * Field names are derived from the install's `secret_key`, so a bot can't
 * hardcode a name that works against every Lumora Press site, and they
 * don't look like a "website" field that browsers and password managers
 * eagerly autofill (which would flag a real visitor as a bot).
 *
 * The names are stable per install rather than rotating, so a page served
 * from the HTTP cache still posts a name this class recognises. The legacy
 * `comment_website` name is still honoured for the same reason, and for
 * themes or plugins that render their own form markup.
 */
final class FormHoneypot
{
    private const LEGACY_FIELD = 'comment_website';

    private static ?string $secretKey = null;

    public static function setSecretKey(string $key): void
    {
        self::$secretKey = $key;
    }

    /**
     * @return list<string>
     */
    public static function fieldNames(): array
    {
        return [self::derive('a'), self::derive('b')];
    }

    public static function field(string $idPrefix, string $wrapperClass = 'lp-comment-form__honeypot'): string
    {
        [$first, $second] = self::fieldNames();
        $class = htmlspecialchars($wrapperClass, ENT_QUOTES, 'UTF-8');
        $prefix = htmlspecialchars($idPrefix, ENT_QUOTES, 'UTF-8');

        // tabindex/autocomplete keep keyboard users and autofill out of the trap.
        return '<p class="' . $class . '" aria-hidden="true">'
            . '<label for="' . $prefix . '-' . $first . '">Leave this field blank</label>'
            . '<input type="text" id="' . $prefix . '-' . $first . '" name="' . $first . '" tabindex="-1" autocomplete="off">'
            . '</p>'
            . '<p class="' . $class . '" aria-hidden="true">'
            . '<label for="' . $prefix . '-' . $second . '">Do not fill in this field</label>'
            . '<input type="text" id="' . $prefix . '-' . $second . '" name="' . $second . '" tabindex="-1" autocomplete="off">'
            . '</p>';
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function isTripped(array $post): bool
    {
        foreach ([...self::fieldNames(), self::LEGACY_FIELD] as $name) {
            $value = $post[$name] ?? '';

            if (is_array($value) || trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private static function derive(string $slot): string
    {
        return 'lp_' . substr(hash_hmac('sha256', 'honeypot-' . $slot, self::$secretKey ?? ''), 0, 10);
    }
}
