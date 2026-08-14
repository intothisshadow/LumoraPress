<?php

/**
 * Registers Lumora Press's built-in widget types (LP-048): Text, Custom HTML, Search, Navigation Menu, Statistics, Social Links, and more.
 *
 * @package LumoraPress
 * @subpackage Widgets
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Widgets;

use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\PostStatus;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PostService;
use LumoraPress\Services\TagService;
use LumoraPress\Services\UserService;

/**
 * Registers Lumora Press's built-in widget types (LP-048) against a
 * WidgetManager — Text, Custom HTML, Search, Navigation Menu, Pages,
 * Categories, Recent Posts, Recent Comments, Archives, Tag Cloud, Meta,
 * Statistics, and Social Links. Themes remain free to register additional
 * widget types of their own (content/themes/default/functions.php no
 * longer needs to — these used to live there as a single Phase 1
 * proof-of-concept widget).
 *
 * Deliberately not shipped in this pass: an Image widget (would need its
 * own Media Manager picker UI, the same scope narrowing LP-040 applied to
 * featured images), a Calendar widget (a full month grid is a
 * significant UI on its own), and a classic external "RSS Feed" widget
 * that fetches an arbitrary admin-supplied feed URL server-side (a
 * server-side-request surface this app doesn't otherwise have — Meta's
 * feed link only ever points at this site's own feed).
 */
final class CoreWidgets
{
    /**
     * Social Links' fixed platform list (LP-048) — a curated set rather
     * than a free-form repeater (which would need its own "add/remove
     * row" UI, the same scope this project has avoided for widget/menu
     * item reordering elsewhere — see WidgetManager's own Move Up/Move
     * Down choice). Each entry's icon name/style matches Font Awesome 6's
     * actual icon slugs, rendered via lp_icon() — a no-op when the
     * (optional, deferred-by-default) Font Awesome plugin isn't active,
     * so this never depends on it being installed.
     *
     * @var array<string, array{label: string, icon: string, style: string}>
     */
    private const SOCIAL_LINK_PLATFORMS = [
        'website' => ['label' => 'Website', 'icon' => 'globe', 'style' => 'solid'],
        'email' => ['label' => 'Email', 'icon' => 'envelope', 'style' => 'solid'],
        'mastodon' => ['label' => 'Mastodon', 'icon' => 'mastodon', 'style' => 'brands'],
        'bluesky' => ['label' => 'Bluesky', 'icon' => 'bluesky', 'style' => 'brands'],
        'twitter' => ['label' => 'Twitter/X', 'icon' => 'x-twitter', 'style' => 'brands'],
        'github' => ['label' => 'GitHub', 'icon' => 'github', 'style' => 'brands'],
        'youtube' => ['label' => 'YouTube', 'icon' => 'youtube', 'style' => 'brands'],
        'instagram' => ['label' => 'Instagram', 'icon' => 'instagram', 'style' => 'brands'],
        'discord' => ['label' => 'Discord', 'icon' => 'discord', 'style' => 'brands'],
    ];

    public static function register(
        WidgetManager $widgets,
        PostService $posts,
        PageService $pages,
        CategoryService $categories,
        TagService $tags,
        CommentService $comments,
        UserService $users,
    ): void {
        $widgets->registerWidget('text', 'Text', static function (array $settings): void {
            $title = (string) ($settings['title'] ?? '');
            $text = (string) ($settings['text'] ?? '');

            echo '<section class="lp-widget lp-widget--text">';
            self::renderTitle($title);
            echo '<div class="lp-widget__content">' . nl2br(esc_html($text)) . '</div>';
            echo '</section>';
        });

        $widgets->registerWidget('custom_html', 'Custom HTML', static function (array $settings): void {
            $title = (string) ($settings['title'] ?? '');
            $html = (string) ($settings['html'] ?? '');

            echo '<section class="lp-widget lp-widget--custom-html">';
            self::renderTitle($title);
            // Trusted admin-authored markup, same trust level as
            // custom_css() (only manage_themes/manage_options
            // administrators can set a widget's settings) — not escaped,
            // by design, same as that helper.
            echo '<div class="lp-widget__content">' . $html . '</div>';
            echo '</section>';
        });

        $widgets->registerWidget('search', 'Search', static function (array $settings): void {
            $title = (string) ($settings['title'] ?? '');

            echo '<section class="lp-widget lp-widget--search">';
            self::renderTitle($title);
            echo '<form class="lp-search-form" role="search" method="get" action="' . esc_url(site_url('search')) . '">'
                . '<label class="lp-search-form__label" for="lp-widget-search-q">Search</label>'
                . '<input class="lp-search-form__input" type="search" id="lp-widget-search-q" name="q" placeholder="Search&hellip;">'
                . '<button class="lp-search-form__button" type="submit">Search</button>'
                . '</form>';
            echo '</section>';
        });

        $widgets->registerWidget('nav_menu', 'Navigation Menu', static function (array $settings): void {
            $title = (string) ($settings['title'] ?? '');
            $location = (string) ($settings['location'] ?? '');

            if ($location === '' || !has_nav_menu($location)) {
                return;
            }

            echo '<section class="lp-widget lp-widget--nav-menu">';
            self::renderTitle($title);
            nav_menu($location, 'lp-widget__nav-menu');
            echo '</section>';
        });

        $widgets->registerWidget('pages', 'Pages', static function (array $settings) use ($pages): void {
            $title = (string) ($settings['title'] ?? '');
            $limit = max(1, (int) ($settings['limit'] ?? 10));
            $list = $pages->paginatePublished(1, $limit)['pages'];

            if ($list === []) {
                return;
            }

            echo '<section class="lp-widget lp-widget--pages">';
            self::renderTitle($title);
            echo '<ul class="lp-widget__list">';

            foreach ($list as $page) {
                echo '<li><a href="' . esc_url(site_url('page/' . $page->slug)) . '">' . esc_html($page->title) . '</a></li>';
            }

            echo '</ul></section>';
        });

        $widgets->registerWidget('categories', 'Categories', static function (array $settings) use ($categories): void {
            $title = (string) ($settings['title'] ?? '');
            $showCount = ($settings['show_count'] ?? '') === '1';
            $list = $categories->listAllWithPostCounts();

            if ($list === []) {
                return;
            }

            echo '<section class="lp-widget lp-widget--categories">';
            self::renderTitle($title);
            echo '<ul class="lp-widget__list">';

            foreach ($list as $entry) {
                echo '<li><a href="' . esc_url(category_permalink($entry['category'])) . '">' . esc_html($entry['category']->name) . '</a>'
                    . ($showCount ? ' <span class="lp-widget__count">(' . (int) $entry['postCount'] . ')</span>' : '')
                    . '</li>';
            }

            echo '</ul></section>';
        });

        $widgets->registerWidget('recent_posts', 'Recent Posts', static function (array $settings) use ($posts): void {
            $title = (string) ($settings['title'] ?? '');
            $limit = max(1, (int) ($settings['limit'] ?? 5));
            $list = $posts->paginatePublished(1, $limit)['posts'];

            if ($list === []) {
                return;
            }

            echo '<section class="lp-widget lp-widget--recent-posts">';
            self::renderTitle($title);
            echo '<ul class="lp-widget__list">';

            foreach ($list as $post) {
                echo '<li><a href="' . esc_url(post_permalink($post)) . '">' . esc_html($post->title) . '</a></li>';
            }

            echo '</ul></section>';
        });

        $widgets->registerWidget('recent_comments', 'Recent Comments', static function (array $settings) use ($comments, $posts, $pages): void {
            $title = (string) ($settings['title'] ?? '');
            $limit = max(1, (int) ($settings['limit'] ?? 5));
            $list = $comments->recentApproved($limit);

            if ($list === []) {
                return;
            }

            echo '<section class="lp-widget lp-widget--recent-comments">';
            self::renderTitle($title);
            echo '<ul class="lp-widget__list">';

            foreach ($list as $entry) {
                // A real Post/Page lookup (rather than the
                // postname_permalink() slug-only helper) so a custom
                // permalink structure using %category%/%author% resolves
                // correctly here too — this widget's default limit (5)
                // keeps the extra query cheap.
                if ($entry['contentType'] === 'page') {
                    $entryPage = $pages->findBySlug($entry['contentSlug']);
                    $url = ($entryPage !== null ? page_permalink($entryPage) : site_url('page/' . $entry['contentSlug']))
                        . '#comment-' . $entry['comment']->id;
                } else {
                    $entryPost = $posts->findBySlug($entry['contentSlug']);
                    $url = ($entryPost !== null ? post_permalink($entryPost) : site_url('post/' . $entry['contentSlug']))
                        . '#comment-' . $entry['comment']->id;
                }

                echo '<li>' . esc_html($entry['comment']->guestName) . ' on <a href="' . esc_url($url) . '">' . esc_html($entry['contentTitle']) . '</a></li>';
            }

            echo '</ul></section>';
        });

        $widgets->registerWidget('archives', 'Archives', static function (array $settings) use ($posts): void {
            $title = (string) ($settings['title'] ?? '');
            $limit = max(1, (int) ($settings['limit'] ?? 12));
            $list = $posts->monthlyArchiveCounts($limit);

            if ($list === []) {
                return;
            }

            echo '<section class="lp-widget lp-widget--archives">';
            self::renderTitle($title);
            echo '<ul class="lp-widget__list">';

            foreach ($list as $entry) {
                $label = the_date(new \DateTimeImmutable(sprintf('%04d-%02d-01', $entry['year'], $entry['month'])));
                $url = site_url(sprintf('archive/%d/%d', $entry['year'], $entry['month']));
                echo '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a> <span class="lp-widget__count">(' . (int) $entry['count'] . ')</span></li>';
            }

            echo '</ul></section>';
        });

        $widgets->registerWidget('tag_cloud', 'Tag Cloud', static function (array $settings) use ($tags): void {
            $title = (string) ($settings['title'] ?? '');
            $list = array_filter($tags->listAllWithPostCounts(), static fn (array $entry): bool => $entry['postCount'] > 0);

            if ($list === []) {
                return;
            }

            $maxCount = max(array_column($list, 'postCount'));

            echo '<section class="lp-widget lp-widget--tag-cloud">';
            self::renderTitle($title);
            echo '<div class="lp-widget__tag-cloud">';

            foreach ($list as $entry) {
                // Scales font size from 0.85em (least-used tag) to 1.6em
                // (most-used) — a classic "tag cloud" visual, not just a
                // plain list.
                $scale = $maxCount > 0 ? $entry['postCount'] / $maxCount : 0;
                $fontSize = 0.85 + ($scale * 0.75);
                echo '<a class="lp-widget__tag-cloud-item" data-style-font-size="' . round($fontSize, 2) . 'em" href="'
                    . esc_url(tag_permalink($entry['tag'])) . '">' . esc_html($entry['tag']->name) . '</a> ';
            }

            echo '</div></section>';
        });

        $widgets->registerWidget('meta', 'Meta', static function (array $settings): void {
            $title = (string) ($settings['title'] ?? '');

            echo '<section class="lp-widget lp-widget--meta">';
            self::renderTitle($title);
            echo '<ul class="lp-widget__list">'
                . '<li><a href="' . esc_url(admin_url('login')) . '">Log in</a></li>'
                . '<li><a href="' . esc_url(home_url('feed')) . '">Entries RSS</a></li>'
                . '</ul></section>';
        });

        $widgets->registerWidget('statistics', 'Statistics', static function (array $settings) use ($posts, $pages, $comments, $users): void {
            $title = (string) ($settings['title'] ?? '');

            $stats = [
                'Posts' => $posts->countByStatus(PostStatus::Published),
                'Pages' => $pages->countByStatus(PageStatus::Published),
                'Comments' => $comments->countByStatus(CommentStatus::Approved),
                'Users' => $users->countAll(),
            ];

            echo '<section class="lp-widget lp-widget--statistics">';
            self::renderTitle($title);
            echo '<ul class="lp-widget__list lp-widget__stats">';

            foreach ($stats as $label => $count) {
                echo '<li><span class="lp-widget__stats-label">' . esc_html($label) . '</span> '
                    . '<span class="lp-widget__count">' . (int) $count . '</span></li>';
            }

            echo '</ul></section>';
        });

        $widgets->registerWidget('social_links', 'Social Links', static function (array $settings): void {
            $title = (string) ($settings['title'] ?? '');
            $links = [];

            foreach (self::SOCIAL_LINK_PLATFORMS as $key => $platform) {
                $value = trim((string) ($settings[$key] ?? ''));

                if ($value === '') {
                    continue;
                }

                $href = $key === 'email' ? 'mailto:' . $value : $value;
                $icon = lp_icon($platform['icon'], ['style' => $platform['style'], 'label' => $platform['label']]);
                // Without an icon (Font Awesome plugin inactive), the
                // platform name renders as visible text instead of being
                // screen-reader-only — an icon-shaped circle is too small
                // to hold a readable label, so the link falls back to a
                // plain text-link style entirely (--text modifier below).
                $linkClass = 'lp-widget__social-link lp-widget__social-link--' . esc_attr($key)
                    . ($icon === '' ? ' lp-widget__social-link--text' : '');

                $links[] = '<a class="' . $linkClass . '" '
                    . 'href="' . esc_url($href) . '"'
                    . ($key === 'email' ? '' : ' rel="me noopener" target="_blank"')
                    . '>' . $icon . '<span' . ($icon !== '' ? ' class="lp-visually-hidden"' : '') . '>'
                    . esc_html($platform['label']) . '</span></a>';
            }

            if ($links === []) {
                return;
            }

            echo '<section class="lp-widget lp-widget--social-links">';
            self::renderTitle($title);
            echo '<div class="lp-widget__social-links">' . implode('', $links) . '</div>';
            echo '</section>';
        });
    }

    private static function renderTitle(string $title): void
    {
        if ($title !== '') {
            echo '<h3 class="lp-widget__title">' . esc_html($title) . '</h3>';
        }
    }
}
