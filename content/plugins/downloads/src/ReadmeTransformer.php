<?php

/**
 * Turns a repository README into a download description, and builds the screenshot gallery block.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.22.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

/**
 * Pure string functions, no database or network. The gallery lives in a recognisable block at the
 * end of the description so an update can regenerate it without touching the README text above it.
 */
final class ReadmeTransformer
{
    public const GALLERY_HEADING = '## Screenshots';

    /** The heading plus the one line of linked thumbnails that galleryBlock() writes. */
    private const GALLERY_BLOCK_PATTERN = '#^[ \t]*\#\#[ \t]+Screenshots[ \t]*\R+(?:\[!\[[^\]]*\]\([^)\s]*\)\]\([^)\s]*\)[ \t]*)+#m';

    /**
     * Small words a title keeps in lower case, and the ones that can be glued onto the end of
     * the word before them in a file name ("LadyofSorrows").
     */
    private const SMALL_WORDS = ['of', 'the', 'and', 'in', 'on', 'for', 'with', 'from', 'a', 'an', 'at', 'to', 'by'];

    private const GLUED_WORDS = ['with', 'from', 'and', 'the', 'for', 'of', 'in', 'on'];

    /**
     * Words that end in a glued small word but are whole words, so they are never split ("Dragon", not "Drag on").
     */
    private const WHOLE_WORDS = [
        'dragon', 'crimson', 'dolphin', 'penguin', 'jackson', 'johnson', 'anderson', 'hampton', 'kingston', 'dungeon',
        'pigeon', 'surgeon', 'wagon', 'weapon', 'carbon', 'cannon', 'cotton', 'button', 'ribbon', 'salmon', 'season',
        'reason', 'person', 'lesson', 'lemon', 'demon', 'falcon', 'beacon', 'bacon', 'baron', 'canyon', 'mutton',
        'skeleton', 'singleton', 'phantom', 'napoleon', 'marathon', 'horizon', 'frontin', 'robin', 'merlin', 'goblin',
        'cousin', 'muffin', 'raisin', 'origin', 'virgin', 'margin', 'basin', 'cabin', 'satin', 'austin', 'franklin',
        'kevin', 'martin', 'colin', 'justin', 'dustin', 'brain', 'queen', 'screen',
    ];

    /**
     * The README's first heading, without formatting, or $fallback when there is none. A heading
     * that is really an identifier ("Type_TAG_Fandom_Title") is turned into readable words.
     */
    public static function title(string $readme, string $fallback): string
    {
        return self::readableTitle(self::firstHeading($readme) ?? $fallback);
    }

    /**
     * Turns a file-name style title into words: "FocusWriter-Theme_FAN_XenaWarriorPrincess_SunlightonSteel"
     * becomes "FocusWriter Theme - Xena Warrior Princess: Sunlight on Steel". The first part is the kind
     * of thing it is, a short all-capitals tag after it (FAN) is dropped, and the rest are the subject
     * and the name. A title with spaces, or with no underscore, is already readable and is left alone.
     *
     * Words glued together in lower case ("Sunlighton") can only be split by guessing, so the result is a
     * suggestion; it is shown for editing before anything is saved.
     */
    public static function readableTitle(string $title): string
    {
        // Capitals are what make a name like this recognisable; plain lower-case snake_case is left as written.
        if (preg_match('/\s/', $title) === 1 || !str_contains($title, '_') || preg_match('/[A-Z]/', $title) !== 1) {
            return $title;
        }

        $segments = array_values(array_filter(explode('_', $title), static fn (string $segment): bool => $segment !== ''));

        if (count($segments) < 2) {
            return $title;
        }

        // A short all-capitals tag right after the kind ("FAN") says nothing a reader needs.
        if (count($segments) > 2 && preg_match('/^[A-Z]{2,4}$/', $segments[1]) === 1) {
            array_splice($segments, 1, 1);
        }

        $kind = str_replace('-', ' ', (string) array_shift($segments));
        $parts = array_map(static fn (string $segment): string => self::spellOut($segment), $segments);

        return $kind . ' - ' . implode(': ', $parts);
    }

    private static function spellOut(string $segment): string
    {
        $tokens = preg_split('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|-/', $segment) ?: [$segment];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
        $words = [];

        foreach ($tokens as $index => $token) {
            // A glued small word is only split off a word that another word follows.
            $peeled = $index < count($tokens) - 1 ? self::peelGluedWords($token) : [$token];

            foreach ($peeled as $position => $word) {
                $isSmall = in_array(strtolower($word), self::SMALL_WORDS, true);
                $words[] = ($isSmall && ($words !== [] || $position > 0)) ? strtolower($word) : $word;
            }
        }

        return implode(' ', $words);
    }

    /**
     * "Dragonandthe" becomes ["Dragon", "and", "the"]. Guards keep real words whole: a list of known
     * ones, a word that would lose its "-ion"/"-oon" ending, and a stem too short to be a word.
     *
     * @return array<int, string>
     */
    private static function peelGluedWords(string $token): array
    {
        $peeled = [];

        while (true) {
            $lower = strtolower($token);

            if (in_array($lower, self::WHOLE_WORDS, true)) {
                break;
            }

            $matched = false;

            foreach (self::GLUED_WORDS as $word) {
                if (!str_ends_with($lower, $word)) {
                    continue;
                }

                $stem = substr($token, 0, -strlen($word));
                $last = strtolower(substr($stem, -1));
                $minimum = in_array($word, ['in', 'on'], true) ? 5 : 3;

                if (strlen($stem) < $minimum) {
                    continue;
                }

                // "-ion" and "-oon" words (Region, Moon) and "-eon" words (Pigeon) end in the small word by spelling.
                if (($word === 'on' && in_array($last, ['i', 'o', 'e'], true)) || ($word === 'of' && $last === 'o')) {
                    continue;
                }

                // Likewise "-ain", "-oin", "-uin" words (Porcelain, Coin, Penguin) end in "in" by spelling.
                if ($word === 'in' && in_array($last, ['a', 'o', 'u', 'i'], true)) {
                    continue;
                }

                array_unshift($peeled, $word);
                $token = $stem;
                $matched = true;

                break;
            }

            if (!$matched) {
                break;
            }
        }

        return [$token, ...$peeled];
    }

    private static function firstHeading(string $readme): ?string
    {
        $lines = preg_split('/\R/', self::withoutFences($readme)) ?: [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^#[ \t]+(.+?)[ \t]*#*[ \t]*$/', $line, $matches) === 1) {
                return self::plainText($matches[1]) ?: null;
            }

            if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $line, $matches) === 1) {
                return self::plainText($matches[1]) ?: null;
            }

            // A "Title" line followed by "=====" is a heading too.
            if (isset($lines[$index + 1]) && trim($line) !== '' && preg_match('/^=+[ \t]*$/', $lines[$index + 1]) === 1) {
                return self::plainText($line) ?: null;
            }
        }

        return null;
    }

    /**
     * Removes the images that point at the repository's own screenshot files (they are added
     * separately as the gallery), along with the empty wrappers they leave behind and a
     * "Screenshots" section that is left holding nothing but short labels.
     *
     * @param array<int, string> $screenshotPaths repository-relative paths, e.g. "screenshots/one.jpg"
     */
    public static function stripScreenshots(string $readme, array $screenshotPaths): string
    {
        if ($screenshotPaths === []) {
            return $readme;
        }

        $removed = 0;
        $isScreenshot = static function (string $url) use ($screenshotPaths): bool {
            $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?? $url));

            foreach ($screenshotPaths as $screenshotPath) {
                if ($path === $screenshotPath || str_ends_with($path, '/' . $screenshotPath)) {
                    return true;
                }
            }

            return false;
        };

        $text = self::mapOutsideFences($readme, static function (string $segment) use ($isScreenshot, &$removed): string {
            $segment = preg_replace_callback('#<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>#i', static function (array $m) use ($isScreenshot, &$removed): string {
                if (!$isScreenshot($m[1])) {
                    return $m[0];
                }

                $removed++;

                return '';
            }, $segment) ?? $segment;

            $segment = preg_replace_callback('#!\[[^\]]*\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)#', static function (array $m) use ($isScreenshot, &$removed): string {
                if (!$isScreenshot($m[1])) {
                    return $m[0];
                }

                $removed++;

                return '';
            }, $segment) ?? $segment;

            return $segment;
        });

        if ($removed === 0) {
            return $readme;
        }

        // Linked images leave "[](url)" or "<a ...></a>" shells; wrappers like <p> may now be empty.
        do {
            $before = $text;
            $text = preg_replace('#(?<!!)\[\s*\]\([^)]*\)#', '', $text) ?? $text;
            $text = preg_replace('#<(a|p|div|picture|center|span)\b[^>]*>\s*</\1>#i', '', $text) ?? $text;
        } while ($text !== $before);

        $text = self::dropEmptiedScreenshotSections($text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) . "\n";
    }

    /**
     * Relative links and images stop working once the text leaves the repository, so they are
     * pointed back at GitHub.
     */
    public static function rewriteRelativeUrls(string $readme, GitHubRepositoryRef $ref, string $branch): string
    {
        $absolute = static function (string $url, bool $isImage) use ($ref, $branch): string {
            if ($url === '' || $url[0] === '#' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url) === 1) {
                return $url;
            }

            [$path, $fragment] = array_pad(explode('#', $url, 2), 2, null);
            $path = ltrim(preg_replace('#^\./#', '', $path) ?? $path, '/');

            // A path that climbs out of its folder can't be resolved without the document's own location.
            if ($path === '' || str_contains($path, '../')) {
                return $url;
            }

            $rewritten = $isImage ? $ref->rawUrl($branch, $path) : $ref->blobUrl($branch, $path);

            return $fragment !== null ? $rewritten . '#' . $fragment : $rewritten;
        };

        return self::mapOutsideFences($readme, static function (string $segment) use ($absolute): string {
            $segment = preg_replace_callback('#(!?)\[([^\]]*)\]\(\s*<?([^)\s>]+)>?((?:\s+"[^"]*")?)\s*\)#', static fn (array $m): string
                => $m[1] . '[' . $m[2] . '](' . $absolute($m[3], $m[1] === '!') . $m[4] . ')', $segment) ?? $segment;

            return preg_replace_callback('#\b(src|href)(\s*=\s*)(["\'])([^"\']*)\3#i', static fn (array $m): string
                => $m[1] . $m[2] . $m[3] . $absolute($m[4], strtolower($m[1]) === 'src') . $m[3], $segment) ?? $segment;
        });
    }

    /**
     * The Markdown renderer escapes raw HTML, so a README's simple HTML (centered headers,
     * badges, line breaks) would show up as literal tags. This turns the common cases into
     * Markdown and drops the layout-only wrappers, keeping their text.
     */
    public static function flattenHtml(string $readme): string
    {
        $attribute = static function (string $attributes, string $name): string {
            return preg_match('/\b' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attributes, $m) === 1 ? ($m[1] !== '' ? $m[1] : ($m[2] ?? '')) : '';
        };

        $text = self::mapOutsideFences($readme, static function (string $segment) use ($attribute): string {
            $segment = preg_replace('/<!--.*?-->/s', '', $segment) ?? $segment;
            $segment = preg_replace_callback('#<img\b([^>]*)>#i', static function (array $m) use ($attribute): string {
                $source = $attribute($m[1], 'src');

                return $source === '' ? '' : '![' . $attribute($m[1], 'alt') . '](' . $source . ')';
            }, $segment) ?? $segment;
            $segment = preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#is', static function (array $m) use ($attribute): string {
                $href = $attribute($m[1], 'href');

                return $href === '' ? $m[2] : '[' . trim($m[2]) . '](' . $href . ')';
            }, $segment) ?? $segment;
            $segment = preg_replace_callback('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', static fn (array $m): string
                => "\n\n" . str_repeat('#', (int) $m[1]) . ' ' . trim(strip_tags($m[2])) . "\n\n", $segment) ?? $segment;
            $segment = preg_replace('#<(strong|b)\b[^>]*>(.*?)</\1>#is', '**$2**', $segment) ?? $segment;
            $segment = preg_replace('#<(em|i)\b[^>]*>(.*?)</\1>#is', '*$2*', $segment) ?? $segment;
            $segment = preg_replace('#<code\b[^>]*>(.*?)</code>#is', '`$1`', $segment) ?? $segment;
            $segment = preg_replace('#<br\s*/?>#i', "  \n", $segment) ?? $segment;
            $segment = preg_replace('#<hr\s*/?>#i', "\n\n---\n\n", $segment) ?? $segment;
            $segment = preg_replace('#<summary\b[^>]*>(.*?)</summary>#is', "\n\n**$1**\n\n", $segment) ?? $segment;
            $segment = preg_replace('#<li\b[^>]*>#i', "\n- ", $segment) ?? $segment;
            $segment = preg_replace('#</?(?:p|div|center|picture|source|details|section|article|header|footer|main|aside|nav|ul|ol|li|table|thead|tbody|tfoot|tr|td|th|figure|figcaption)\b[^>]*>#i', "\n", $segment) ?? $segment;

            return preg_replace('#</?(?:span|kbd|sub|sup|small|big|u|s|strike|font)\b[^>]*>#i', '', $segment) ?? $segment;
        });

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) . "\n";
    }

    /**
     * The "Screenshots" block, or an empty string when there are no images. Plain Markdown
     * (a linked thumbnail for each image, on one line so they sit side by side), since the
     * Markdown renderer won't pass raw HTML through.
     *
     * @param array<int, array{fullUrl: string, thumbUrl: string, alt: string, width: int, height: int}> $images
     */
    public static function galleryBlock(array $images): string
    {
        if ($images === []) {
            return '';
        }

        $links = [];

        foreach ($images as $image) {
            // Brackets and parentheses would end the Markdown image or link early.
            $alt = trim(preg_replace('/[\[\]()\r\n]+/', ' ', $image['alt']) ?? '');
            $links[] = '[![' . $alt . '](' . $image['thumbUrl'] . ')](' . $image['fullUrl'] . ')';
        }

        return self::GALLERY_HEADING . "\n\n" . implode(' ', $links);
    }

    /**
     * Swaps the gallery block in $description for one built from $images, adding it at the end
     * when there isn't one yet.
     *
     * @param array<int, array{fullUrl: string, thumbUrl: string, alt: string, width: int, height: int}> $images
     */
    public static function replaceGallery(string $description, array $images): string
    {
        $block = self::galleryBlock($images);
        $text = self::withoutGallery($description);

        return $block === '' ? $text : ($text === '' ? $block : $text . "\n\n" . $block);
    }

    public static function withoutGallery(string $description): string
    {
        return trim(preg_replace(self::GALLERY_BLOCK_PATTERN, '', $description) ?? $description);
    }

    private static function plainText(string $markup): string
    {
        $text = strip_tags($markup);
        $text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        // Underscores inside a word belong to the word (file names, snake_case); only emphasis ones are markup.
        $text = preg_replace('/[*`~]+|(?<![A-Za-z0-9])_+|_+(?![A-Za-z0-9])/', '', $text) ?? $text;

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * A "Screenshots" heading whose section is down to a few short labels ("Desktop", "Mobile")
     * after the images were taken out has nothing left worth keeping.
     */
    private static function dropEmptiedScreenshotSections(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $result = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (preg_match('/^(#{1,6})[ \t]+Screenshots?[ \t]*#*[ \t]*$/i', $lines[$i], $heading) !== 1) {
                $result[] = $lines[$i];

                continue;
            }

            $level = strlen($heading[1]);
            $end = $count;

            for ($j = $i + 1; $j < $count; $j++) {
                if (preg_match('/^(#{1,6})[ \t]/', $lines[$j], $next) === 1 && strlen($next[1]) <= $level) {
                    $end = $j;

                    break;
                }
            }

            $isEmpty = true;

            for ($j = $i + 1; $j < $end; $j++) {
                $label = trim(preg_replace('/^#+[ \t]*|[ \t]*#+$/', '', strip_tags($lines[$j])) ?? '');

                if ($label !== '' && mb_strlen($label) > 24) {
                    $isEmpty = false;

                    break;
                }
            }

            if ($isEmpty) {
                $i = $end - 1;

                continue;
            }

            $result[] = $lines[$i];
        }

        return implode("\n", $result);
    }

    /**
     * Applies $callback to the text between fenced code blocks only, so code samples are never rewritten.
     *
     * @param callable(string): string $callback
     */
    private static function mapOutsideFences(string $text, callable $callback): string
    {
        $segments = preg_split('/^([ \t]*(?:```|~~~).*)$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $inFence = false;
        $output = '';

        foreach ($segments as $index => $segment) {
            if ($index % 2 === 1) {
                $inFence = !$inFence;
                $output .= $segment;

                continue;
            }

            $output .= $inFence ? $segment : $callback($segment);
        }

        return $output;
    }

    /** The text with every fenced code block left out, for looking for headings in prose only. */
    private static function withoutFences(string $text): string
    {
        $kept = '';
        self::mapOutsideFences($text, static function (string $segment) use (&$kept): string {
            $kept .= $segment . "\n";

            return $segment;
        });

        return $kept;
    }
}
