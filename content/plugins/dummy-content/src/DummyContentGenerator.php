<?php

/**
 * Core logic for the bundled Dummy Content plugin (LPP-005): generates and removes realistic placeholder content via the shared import layer.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\DummyContent;

use DateTimeImmutable;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\User;
use LumoraPress\Models\UserRole;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\ContentImportRegistry;
use LumoraPress\Services\Import\CommentImporter;
use LumoraPress\Services\Import\ImportedComment;
use LumoraPress\Services\Import\ImportedMedia;
use LumoraPress\Services\Import\ImportedPage;
use LumoraPress\Services\Import\ImportedPost;
use LumoraPress\Services\Import\ImportedUser;
use LumoraPress\Services\Import\MediaImporter;
use LumoraPress\Services\Import\PageImporter;
use LumoraPress\Services\Import\PostImporter;
use LumoraPress\Services\Import\UserImporter;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PostService;
use LumoraPress\Services\TagService;
use LumoraPress\Services\UserService;
use RuntimeException;

/**
 * Deliberately hook-agnostic and constructed directly with real Kernel
 * services (unlike FontAwesomeService's static singleton +
 * ActiveConfig::instance() pattern) — this class's only caller is the
 * admin screen, which already has $kernel by the time it runs, so there
 * is no need for the load-time-before-Kernel workaround Font Awesome
 * needs. See dummy-content.php's own docblock.
 *
 * Every created record is tagged under one batch via ContentImportRegistry
 * (source = self::SOURCE) so removeAll() can delete exactly what this
 * class created and nothing else. generate() refuses to run again while
 * a previous batch still exists — the ticket's "idempotent, re-running
 * does not duplicate" requirement is met by this hard guard rather than
 * diffing against existing content: the admin must explicitly "Remove
 * All Generated Content" before generating again.
 */
final class DummyContentGenerator
{
    public const SOURCE = 'dummy_content';

    /** @var array<int, string> */
    private const FIRST_NAMES = [
        'Alex', 'Bailey', 'Casey', 'Dana', 'Ellis', 'Frankie', 'Gray', 'Harper',
        'Indigo', 'Jules', 'Kai', 'Lane', 'Morgan', 'Nico', 'Oakley', 'Parker',
        'Quinn', 'Reese', 'Sage', 'Tatum',
    ];

    /** @var array<int, string> */
    private const LAST_NAMES = [
        'Alder', 'Blackwood', 'Carver', 'Dunmore', 'Ellery', 'Fenwick', 'Grayson',
        'Holloway', 'Ivory', 'Journey', 'Kestrel', 'Larkspur', 'Marlowe', 'Nightingale',
        'Osprey', 'Pryce', 'Quill', 'Rivers', 'Sparrow', 'Thistle',
    ];

    /** @var array<int, string> */
    private const WORDS = [
        'lantern', 'harbor', 'quiet', 'signal', 'orchard', 'velvet', 'compass',
        'ember', 'meadow', 'paper', 'winter', 'copper', 'ridge', 'hollow',
        'thread', 'tide', 'granite', 'willow', 'lantern', 'echo', 'drift',
        'amber', 'canyon', 'frost', 'garden', 'harvest', 'ink', 'journey',
        'kindred', 'linen', 'moonlight', 'north', 'opal', 'pilgrim', 'quartz',
        'river', 'salt', 'timber', 'umbrella', 'valley', 'wander', 'yarn',
    ];

    /** @var array<int, string> */
    private const TAG_NAMES = [
        'news', 'guide', 'opinion', 'update', 'release', 'tips', 'faq',
        'showcase', 'interview', 'retrospective', 'beta', 'roadmap',
        'feedback', 'changelog', 'misc',
    ];

    /** @var array<string, array{posts: int, pages: int, comments: int}> */
    private const VOLUME_PRESETS = [
        'small' => ['posts' => 5, 'pages' => 2, 'comments' => 10],
        'medium' => ['posts' => 20, 'pages' => 5, 'comments' => 40],
        'large' => ['posts' => 50, 'pages' => 10, 'comments' => 100],
    ];

    public function __construct(
        private readonly UserImporter $userImporter,
        private readonly PostImporter $postImporter,
        private readonly PageImporter $pageImporter,
        private readonly MediaImporter $mediaImporter,
        private readonly CommentImporter $commentImporter,
        private readonly UserService $users,
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly MediaService $media,
        private readonly CommentService $comments,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly ContentImportRegistry $registry,
        private readonly string $tempPath,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function existingBatchIds(): array
    {
        return $this->registry->batchesForSource(self::SOURCE);
    }

    /**
     * @return array{batchId: string, createdAt: ?DateTimeImmutable, counts: array<string, int>}|null
     */
    public function lastGeneratedSummary(): ?array
    {
        $batchIds = $this->existingBatchIds();

        if ($batchIds === []) {
            return null;
        }

        $batchId = $batchIds[0];

        return [
            'batchId' => $batchId,
            'createdAt' => $this->registry->batchCreatedAt($batchId),
            'counts' => $this->registry->countsForBatch($batchId),
        ];
    }

    /**
     * @param array{volume: string, users?: bool, posts?: bool, pages?: bool, media?: bool, comments?: bool} $options
     * @return array<string, int>
     */
    public function generate(array $options): array
    {
        if ($this->existingBatchIds() !== []) {
            throw new RuntimeException('Dummy content already exists. Remove it before generating again.');
        }

        $volume = self::VOLUME_PRESETS[$options['volume']] ?? self::VOLUME_PRESETS['small'];
        $batchId = $this->registry->newBatch();

        $users = ($options['users'] ?? true) ? $this->generateUsers($batchId) : [];
        $categoryNames = $this->generateCategories($batchId);
        $tagNames = $this->generateTags($batchId);

        $media = [];

        if ($options['media'] ?? true) {
            $mediaCount = max(3, (int) ceil($volume['posts'] / 3));
            $media = $this->generateMedia($batchId, $users, $mediaCount);
        }

        $postIds = [];

        if ($options['posts'] ?? true) {
            $postIds = $this->generatePosts($batchId, $volume['posts'], $users, $categoryNames, $tagNames, $media);
        }

        if ($options['pages'] ?? true) {
            $this->generatePages($batchId, $volume['pages'], $users);
        }

        if (($options['comments'] ?? true) && $postIds !== []) {
            $this->generateComments($batchId, $volume['comments'], $postIds, $users);
        }

        return $this->registry->countsForBatch($batchId);
    }

    /**
     * Deletes every batch this class has ever created — not just the
     * most recent one, in case an earlier run's rows were never cleared
     * — through each content type's own service delete() method, never
     * a raw query, then clears the now-stale registry rows.
     *
     * Deletion order matters: comments before the posts they belong to,
     * posts/pages before the users who authored them (neither
     * PostService::delete() nor UserService::delete() enforces this
     * itself), media/categories/tags last since nothing else here
     * references them by a foreign key that would break.
     *
     * @return array<string, int>
     */
    public function removeAll(): array
    {
        $totals = [];

        foreach ($this->existingBatchIds() as $batchId) {
            foreach ($this->registry->countsForBatch($batchId) as $type => $count) {
                $totals[$type] = ($totals[$type] ?? 0) + $count;
            }

            foreach (['comment', 'post', 'page', 'media', 'category', 'tag', 'user'] as $contentType) {
                foreach ($this->registry->idsForBatch($batchId, $contentType) as $entry) {
                    $this->deleteOne($entry['contentType'], $entry['contentId']);
                }
            }

            $this->registry->clearBatch($batchId);
        }

        return $totals;
    }

    private function deleteOne(string $contentType, int $id): void
    {
        match ($contentType) {
            'comment' => $this->comments->delete($id),
            'post' => $this->posts->delete($id),
            'page' => $this->pages->delete($id),
            'media' => $this->media->delete($id),
            'category' => $this->categories->delete($id),
            'tag' => $this->tags->delete($id),
            'user' => $this->users->delete($id),
            default => null,
        };
    }

    /**
     * @return array<string, User>
     */
    private function generateUsers(string $batchId): array
    {
        $roles = [
            UserRole::Administrator,
            UserRole::Editor,
            UserRole::Author,
            UserRole::Contributor,
            UserRole::Subscriber,
        ];
        $users = [];

        foreach ($roles as $role) {
            $firstName = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
            $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $username = strtolower($firstName . '.' . $lastName . '.' . $role->value . '.' . bin2hex(random_bytes(2)));

            $data = new ImportedUser(
                username: $username,
                email: $username . '@example.test',
                role: $role,
                displayName: $firstName . ' ' . $lastName,
            );

            $users[$role->value] = $this->userImporter->importOrReuse($batchId, self::SOURCE, $data);
        }

        return $users;
    }

    /**
     * @return array<int, string>
     */
    private function generateCategories(string $batchId): array
    {
        $names = [];

        $parent = $this->categories->create('Announcements', 'Site news and announcements');
        $this->registry->record($batchId, self::SOURCE, 'category', $parent->id);
        $names[] = $parent->name;

        $child = $this->categories->create('Release Notes', 'Version release notes', $parent->id);
        $this->registry->record($batchId, self::SOURCE, 'category', $child->id);
        $names[] = $child->name;

        foreach (['Tutorials', 'Reviews', 'Community'] as $name) {
            $category = $this->categories->create($name, '');
            $this->registry->record($batchId, self::SOURCE, 'category', $category->id);
            $names[] = $category->name;
        }

        return $names;
    }

    /**
     * @return array<int, string>
     */
    private function generateTags(string $batchId): array
    {
        $names = [];

        foreach (self::TAG_NAMES as $name) {
            $tag = $this->tags->create($name, '');
            $this->registry->record($batchId, self::SOURCE, 'tag', $tag->id);
            $names[] = $tag->name;
        }

        return $names;
    }

    /**
     * @param array<string, User> $users
     * @return array<int, array<string, mixed>>
     */
    private function generateMedia(string $batchId, array $users, int $count): array
    {
        $uploaderId = $users[UserRole::Administrator->value]->id ?? 1;
        $dimensions = [[1200, 800], [800, 1200], [1000, 1000], [1600, 900], [600, 400]];
        $media = [];

        if (!is_dir($this->tempPath) && !mkdir($this->tempPath, 0755, true) && !is_dir($this->tempPath)) {
            throw new RuntimeException('Unable to create the temporary directory for generated images.');
        }

        for ($i = 0; $i < $count; $i++) {
            [$width, $height] = $dimensions[$i % count($dimensions)];
            $tempFile = rtrim($this->tempPath, '/') . '/dummy-' . bin2hex(random_bytes(6)) . '.png';

            $this->renderPlaceholderImage($tempFile, $width, $height, $i);

            $data = new ImportedMedia(
                absolutePath: $tempFile,
                uploadedByUserId: $uploaderId,
                fileName: 'dummy-placeholder-' . ($i + 1) . '.png',
                altText: 'Placeholder image ' . ($i + 1),
            );

            $media[] = $this->mediaImporter->importFromLocalFile($batchId, self::SOURCE, $data);

            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }

        return $media;
    }

    private function renderPlaceholderImage(string $path, int $width, int $height, int $seed): void
    {
        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            throw new RuntimeException('Unable to create a placeholder image canvas.');
        }

        $palette = [[66, 133, 244], [219, 68, 55], [244, 180, 0], [15, 157, 88], [171, 71, 188]];
        [$r, $g, $b] = $palette[$seed % count($palette)];
        $background = imagecolorallocate($canvas, $r, $g, $b);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $background);

        $textColor = imagecolorallocate($canvas, 255, 255, 255);
        // GD's built-in bitmap font (imagestring()) only supports ASCII/
        // Latin-1, so a plain "x" separator is used rather than a
        // multiplication-sign character that could render as a mangled
        // glyph or empty box depending on the font.
        $label = $width . 'x' . $height;
        imagestring($canvas, 5, max(0, (int) ($width / 2) - 40), max(0, (int) ($height / 2) - 8), $label, $textColor);

        imagepng($canvas, $path);
        imagedestroy($canvas);
    }

    /**
     * @param array<string, User> $users
     * @param array<int, string> $categoryNames
     * @param array<int, string> $tagNames
     * @param array<int, array<string, mixed>> $media
     * @return array<int, int>
     */
    private function generatePosts(string $batchId, int $count, array $users, array $categoryNames, array $tagNames, array $media): array
    {
        $statuses = [PostStatus::Published, PostStatus::Published, PostStatus::Draft, PostStatus::Scheduled];
        $formats = [ContentFormat::Markdown, ContentFormat::Html, ContentFormat::Plain];
        $authors = array_values($users);
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $status = $statuses[$i % count($statuses)];
            $format = $formats[$i % count($formats)];
            $author = $authors[$i % max(1, count($authors))] ?? null;
            $featuredImage = $media !== [] ? $media[$i % count($media)] : null;

            $publishedAt = match ($status) {
                PostStatus::Scheduled => new DateTimeImmutable('+' . random_int(1, 14) . ' days'),
                PostStatus::Published => new DateTimeImmutable('-' . random_int(0, 60) . ' days'),
                default => null,
            };

            $tagCount = min(count($tagNames), random_int(1, 3));
            $shuffledTags = $tagNames;
            shuffle($shuffledTags);

            $data = new ImportedPost(
                title: $this->randomSentence(4, 8, true),
                content: $this->randomContent($format, 3, 6),
                excerpt: $this->randomSentence(10, 20),
                authorId: $author?->id ?? 1,
                status: $status,
                publishedAt: $publishedAt,
                featuredImageId: $featuredImage !== null ? (int) $featuredImage['id'] : null,
                commentsOpen: true,
                contentFormat: $format,
                categories: [$categoryNames[array_rand($categoryNames)]],
                tags: array_slice($shuffledTags, 0, $tagCount),
                meta: $i % 5 === 0 ? [['key' => 'featured_rank', 'value' => (string) ($i + 1)]] : [],
            );

            $ids[] = $this->postImporter->import($batchId, self::SOURCE, $data)->id;
        }

        return $ids;
    }

    /**
     * @param array<string, User> $users
     */
    private function generatePages(string $batchId, int $count, array $users): void
    {
        $author = $users[UserRole::Editor->value] ?? reset($users) ?: null;
        $externalMap = [];
        $topLevelExternalIds = [];

        for ($i = 0; $i < $count; $i++) {
            $externalId = 'dummy-page-' . $i;
            $isChild = $i > 0 && $topLevelExternalIds !== [] && $i % 3 === 0;

            $data = new ImportedPage(
                title: $this->randomSentence(2, 5, true),
                content: $this->randomContent(ContentFormat::Markdown, 2, 4),
                excerpt: '',
                authorId: $author?->id ?? 1,
                status: PageStatus::Published,
                publishedAt: new DateTimeImmutable(),
                parentExternalId: $isChild ? $topLevelExternalIds[array_rand($topLevelExternalIds)] : null,
                contentFormat: ContentFormat::Markdown,
                externalId: $externalId,
            );

            $page = $this->pageImporter->import($batchId, self::SOURCE, $data, $externalMap);
            $externalMap[$externalId] = $page->id;

            if (!$isChild) {
                $topLevelExternalIds[] = $externalId;
            }
        }
    }

    /**
     * @param array<int, int> $postIds
     * @param array<string, User> $users
     */
    private function generateComments(string $batchId, int $count, array $postIds, array $users): void
    {
        $statuses = [
            CommentStatus::Approved,
            CommentStatus::Approved,
            CommentStatus::Pending,
            CommentStatus::Spam,
            CommentStatus::Trash,
        ];
        $registeredUsers = array_values($users);
        $externalMap = [];
        $lastExternalIdByPost = [];

        for ($i = 0; $i < $count; $i++) {
            $postId = $postIds[$i % count($postIds)];
            $status = $statuses[$i % count($statuses)];
            $externalId = 'dummy-comment-' . $i;
            $parentExternalId = ($i % 4 === 0 && isset($lastExternalIdByPost[$postId])) ? $lastExternalIdByPost[$postId] : null;

            $useRegistered = $registeredUsers !== [] && $i % 3 === 0;
            $registeredUser = $useRegistered ? $registeredUsers[array_rand($registeredUsers)] : null;
            $guestName = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)] . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)];

            $data = new ImportedComment(
                postId: $postId,
                content: $this->randomSentence(8, 20) . '.',
                guestName: $registeredUser?->displayName ?? $guestName,
                guestEmail: $registeredUser?->email ?? (strtolower(str_replace(' ', '.', $guestName)) . '@example.test'),
                status: $status,
                userId: $registeredUser?->id,
                parentExternalId: $parentExternalId,
                externalId: $externalId,
            );

            $comment = $this->commentImporter->import($batchId, self::SOURCE, $data, $externalMap);
            $externalMap[$externalId] = $comment->id;
            $lastExternalIdByPost[$postId] = $externalId;
        }
    }

    private function randomSentence(int $minWords, int $maxWords, bool $titleCase = false): string
    {
        $count = random_int($minWords, $maxWords);
        $words = [];

        for ($i = 0; $i < $count; $i++) {
            $words[] = self::WORDS[array_rand(self::WORDS)];
        }

        $sentence = implode(' ', $words);

        return $titleCase ? ucwords($sentence) : ucfirst($sentence);
    }

    private function randomContent(ContentFormat $format, int $minParagraphs, int $maxParagraphs): string
    {
        $count = random_int($minParagraphs, $maxParagraphs);
        $paragraphs = [];

        for ($i = 0; $i < $count; $i++) {
            $sentences = [];

            for ($s = 0, $sentenceCount = random_int(3, 6); $s < $sentenceCount; $s++) {
                $sentences[] = $this->randomSentence(6, 14) . '.';
            }

            $paragraphs[] = implode(' ', $sentences);
        }

        return $format === ContentFormat::Html
            ? implode("\n", array_map(static fn (string $paragraph): string => '<p>' . $paragraph . '</p>', $paragraphs))
            : implode("\n\n", $paragraphs);
    }
}
