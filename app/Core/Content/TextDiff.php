<?php

/**
 * A minimal line-based diff for the Revisions (LP-017) Compare screen.
 *
 * @package LumoraPress
 * @subpackage Content
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Content;

/**
 * A minimal line-based diff for the Revisions (LP-017) "Compare" screen —
 * good enough to show what changed between two post/page bodies without
 * pulling in a diff library the project doesn't otherwise need. Uses the
 * standard longest-common-subsequence backtrack, the same algorithm behind
 * `diff`/`git diff`, just line-granular rather than word-granular.
 */
final class TextDiff
{
    /**
     * @return array<int, array{type: string, line: string}> type is one of
     *     'unchanged', 'added', 'removed'
     */
    public static function compare(string $from, string $to): array
    {
        $fromLines = self::splitLines($from);
        $toLines = self::splitLines($to);

        $lcs = self::longestCommonSubsequence($fromLines, $toLines);

        $result = [];
        $i = 0;
        $j = 0;

        foreach ($lcs as [$fromIndex, $toIndex]) {
            while ($i < $fromIndex) {
                $result[] = ['type' => 'removed', 'line' => $fromLines[$i]];
                $i++;
            }

            while ($j < $toIndex) {
                $result[] = ['type' => 'added', 'line' => $toLines[$j]];
                $j++;
            }

            $result[] = ['type' => 'unchanged', 'line' => $fromLines[$fromIndex]];
            $i++;
            $j++;
        }

        while ($i < count($fromLines)) {
            $result[] = ['type' => 'removed', 'line' => $fromLines[$i]];
            $i++;
        }

        while ($j < count($toLines)) {
            $result[] = ['type' => 'added', 'line' => $toLines[$j]];
            $j++;
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private static function splitLines(string $text): array
    {
        return explode("\n", str_replace("\r\n", "\n", $text));
    }

    /**
     * @param array<int, string> $from
     * @param array<int, string> $to
     * @return array<int, array{0: int, 1: int}> pairs of matching (fromIndex, toIndex)
     */
    private static function longestCommonSubsequence(array $from, array $to): array
    {
        $fromCount = count($from);
        $toCount = count($to);

        $lengths = array_fill(0, $fromCount + 1, array_fill(0, $toCount + 1, 0));

        for ($i = $fromCount - 1; $i >= 0; $i--) {
            for ($j = $toCount - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $from[$i] === $to[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $pairs = [];
        $i = 0;
        $j = 0;

        while ($i < $fromCount && $j < $toCount) {
            if ($from[$i] === $to[$j]) {
                $pairs[] = [$i, $j];
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $pairs;
    }
}
