<?php

/**
 * Imports a download from a public GitHub repository, and updates it from the repository later.
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

use InvalidArgumentException;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Services\FolderService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\ThumbnailService;
use RuntimeException;

/**
 * A copy of several images is too slow for one request, so an import or update is a "job" held in
 * the session that the browser steps through one operation per request. Each operation records its
 * result as it goes, so an interrupted update leaves the download consistent with what was applied.
 * Nothing the browser sends is trusted for what to fetch: the files are chosen from a fresh GitHub
 * listing taken when the job begins.
 */
final class GitHubImportService
{
    private const SESSION_KEY = 'lp_github_import_jobs';

    private const MAX_JOBS = 5;

    private const REPO_CACHE_OPTION = 'downloads_github_repos_cache';

    private const REPO_CACHE_SECONDS = 900;

    public const ACCOUNT_OPTION = 'downloads_github_account';

    public function __construct(
        private readonly GitHubClient $client,
        private readonly GitHubSourceStore $store,
        private readonly DownloadService $downloads,
        private readonly MediaService $media,
        private readonly FolderService $folders,
        private readonly PressConfig $config,
        private readonly ?ThumbnailService $thumbnails,
        private readonly string $uploadsPath,
        private readonly string $tempDir,
    ) {
    }

    public function account(): string
    {
        return trim((string) $this->config->option(self::ACCOUNT_OPTION, ''));
    }

    public function setAccount(string $account): bool
    {
        $account = trim($account);

        if ($account !== '' && !GitHubRepositoryRef::isValidOwner($account)) {
            return false;
        }

        $this->config->setOption(self::ACCOUNT_OPTION, $account);
        $this->config->setOption(self::REPO_CACHE_OPTION, '');

        return true;
    }

    /**
     * The account's public repositories for the picker, cached briefly so opening the screen
     * doesn't spend one of GitHub's few unauthenticated requests every time.
     *
     * @return array{repos: array<int, array{name: string, description: string, fork: bool, archived: bool, defaultBranch: string}>, imported: array<string, int>, fetchedAt: int}
     */
    public function repositoryChoices(bool $refresh = false): array
    {
        $owner = $this->account();

        if ($owner === '') {
            return ['repos' => [], 'imported' => [], 'fetchedAt' => 0];
        }

        $cached = json_decode((string) $this->config->option(self::REPO_CACHE_OPTION, ''), true);
        $usable = is_array($cached) && ($cached['owner'] ?? '') === $owner && is_array($cached['repos'] ?? null)
            && time() - (int) ($cached['fetchedAt'] ?? 0) < self::REPO_CACHE_SECONDS;

        if (!$refresh && $usable) {
            $repos = $cached['repos'];
            $fetchedAt = (int) $cached['fetchedAt'];
        } else {
            $repos = $this->client->listRepositories($owner);
            $fetchedAt = time();
            $this->config->setOption(self::REPO_CACHE_OPTION, json_encode(['owner' => $owner, 'fetchedAt' => $fetchedAt, 'repos' => $repos]));
        }

        return ['repos' => $repos, 'imported' => $this->store->importedRepositories($owner), 'fetchedAt' => $fetchedAt];
    }

    /**
     * Everything the preview screen shows. Nothing is saved.
     *
     * @return array{ref: GitHubRepositoryRef, repoName: string, branch: string, title: string, description: string, hasReadme: bool, screenshots: array<int, array{path: string, name: string, sha: string, size: int, tooLarge: bool}>, existingDownloadId: ?int}
     */
    public function preview(GitHubRepositoryRef $ref): array
    {
        $repo = $this->client->fetchRepository($ref);
        $branch = $repo['defaultBranch'];
        $readme = $this->client->fetchReadme($ref, $branch);
        $screenshots = $this->client->listScreenshots($ref, $branch);

        return [
            'ref' => $ref,
            'repoName' => $repo['name'],
            'branch' => $branch,
            'title' => $readme !== null ? ReadmeTransformer::title($readme, $repo['name']) : $repo['name'],
            'description' => $readme !== null ? $this->cleanReadme($readme, $ref, $branch, $screenshots) : '',
            'hasReadme' => $readme !== null,
            'screenshots' => array_map(static fn (array $shot): array => $shot + ['tooLarge' => $shot['size'] > GitHubClient::MAX_IMAGE_BYTES], $screenshots),
            'existingDownloadId' => $this->store->downloadIdForRepository($ref),
        ];
    }

    /**
     * @param array<int, string> $selectedPaths
     * @return array{token: string, total: int}
     */
    public function beginImport(int $userId, GitHubRepositoryRef $ref, string $title, string $description, ?int $categoryId, array $selectedPaths, bool $allowDuplicate): array
    {
        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException('A title is required.');
        }

        if (!$allowDuplicate && $this->store->downloadIdForRepository($ref) !== null) {
            throw new InvalidArgumentException('That repository has already been imported.');
        }

        $repo = $this->client->fetchRepository($ref);
        $branch = $repo['defaultBranch'];
        $readme = $this->client->fetchReadme($ref, $branch);
        $listing = $this->indexByPath($this->client->listScreenshots($ref, $branch));

        $operations = [];

        foreach (array_unique($selectedPaths) as $path) {
            if (isset($listing[$path]) && $listing[$path]['size'] <= GitHubClient::MAX_IMAGE_BYTES) {
                $operations[] = ['op' => 'add'] + $listing[$path];
            }
        }

        [$folderId, $createdFolder] = $this->findOrCreateFolder($repo['name']);

        return $this->storeJob([
            'mode' => 'import',
            'userId' => $userId,
            'owner' => $ref->owner,
            'repo' => $ref->repo,
            'branch' => $branch,
            'title' => $title,
            'description' => $description,
            'categoryId' => $categoryId,
            'readmeHash' => hash('sha256', (string) $readme),
            'folderId' => $folderId,
            'createdFolder' => $createdFolder,
            'operations' => $operations,
            'next' => 0,
            'added' => [],
            'failed' => [],
        ]);
    }

    /**
     * What an update would change, read fresh from GitHub. Nothing is saved.
     *
     * @return array<string, mixed>
     */
    public function checkForUpdates(int $downloadId): array
    {
        $download = $this->downloads->findById($downloadId);
        $source = $this->store->find($downloadId);

        if ($download === null || $source === null) {
            throw new InvalidArgumentException('This download was not imported from GitHub.');
        }

        $ref = $source['ref'];
        $repo = $this->client->fetchRepository($ref);
        $branch = $repo['defaultBranch'];
        $readme = $this->client->fetchReadme($ref, $branch);
        $listing = $this->indexByPath($this->client->listScreenshots($ref, $branch));
        $mapped = [];

        foreach ($this->store->images($downloadId) as $image) {
            if ($this->media->find($image['mediaId']) !== null) {
                $mapped[$image['repoPath']] = $image;
            }
        }

        $new = [];
        $changed = [];

        foreach ($listing as $path => $entry) {
            if ($entry['size'] > GitHubClient::MAX_IMAGE_BYTES) {
                continue;
            }

            if (!isset($mapped[$path])) {
                $new[] = $entry;
            } elseif ($mapped[$path]['blobSha'] !== $entry['sha']) {
                $changed[] = $entry + ['mediaId' => $mapped[$path]['mediaId']];
            }
        }

        $removed = [];

        foreach ($mapped as $path => $image) {
            if (!isset($listing[$path])) {
                $removed[] = ['path' => $path, 'mediaId' => $image['mediaId']];
            }
        }

        $newTitle = $readme !== null ? ReadmeTransformer::title($readme, $repo['name']) : $download->title;
        $readmeChanged = $readme !== null && hash('sha256', $readme) !== $source['readmeHash'];

        $result = [
            'ref' => $ref,
            'branch' => $source['branch'],
            'newBranch' => $branch,
            'branchChanged' => $branch !== $source['branch'],
            'syncedAt' => $source['syncedAt'],
            'readmeChanged' => $readmeChanged,
            'readmeHash' => hash('sha256', (string) $readme),
            'newDescription' => $readme !== null ? $this->cleanReadme($readme, $ref, $branch, array_values($listing)) : '',
            'descriptionEditedLocally' => hash('sha256', ReadmeTransformer::withoutGallery($download->description)) !== $source['descriptionHash'],
            'newTitle' => $newTitle,
            'titleChanged' => $readmeChanged && $newTitle !== $download->title,
            'titleEditedLocally' => hash('sha256', $download->title) !== $source['titleHash'],
            'newImages' => $new,
            'changedImages' => $changed,
            'removedImages' => $removed,
        ];

        $result['hasChanges'] = $result['branchChanged'] || $readmeChanged || $new !== [] || $changed !== [] || $removed !== [];

        return $result;
    }

    /**
     * @param array{description?: bool, title?: bool, branch?: bool, add?: array<int, string>, replace?: array<int, string>, remove?: array<int, string>} $choices
     * @return array{token: string, total: int}
     */
    public function beginUpdate(int $userId, int $downloadId, array $choices): array
    {
        $check = $this->checkForUpdates($downloadId);
        $download = $this->downloads->findById($downloadId);

        if ($download === null) {
            throw new InvalidArgumentException('This download no longer exists.');
        }

        $operations = [];
        $newByPath = array_column($check['newImages'], null, 'path');
        $changedByPath = array_column($check['changedImages'], null, 'path');
        $removedByPath = array_column($check['removedImages'], null, 'path');

        foreach (array_unique($choices['add'] ?? []) as $path) {
            if (isset($newByPath[$path])) {
                $operations[] = ['op' => 'add'] + $newByPath[$path];
            }
        }

        foreach (array_unique($choices['replace'] ?? []) as $path) {
            if (isset($changedByPath[$path])) {
                $operations[] = ['op' => 'replace'] + $changedByPath[$path];
            }
        }

        foreach (array_unique($choices['remove'] ?? []) as $path) {
            if (isset($removedByPath[$path])) {
                $operations[] = ['op' => 'remove'] + $removedByPath[$path];
            }
        }

        $folderId = $download->folderId;
        $createdFolder = false;

        if ($folderId === null || $this->folders->findById($folderId) === null) {
            [$folderId, $createdFolder] = $this->findOrCreateFolder($check['ref']->repo);
        }

        $images = $this->store->images($downloadId);

        return $this->storeJob([
            'mode' => 'update',
            'userId' => $userId,
            'downloadId' => $downloadId,
            'owner' => $check['ref']->owner,
            'repo' => $check['ref']->repo,
            'branch' => $check['newBranch'],
            'applyDescription' => ($choices['description'] ?? false) && $check['readmeChanged'],
            'applyTitle' => ($choices['title'] ?? false) && $check['titleChanged'],
            'applyBranch' => ($choices['branch'] ?? false) && $check['branchChanged'],
            'newDescription' => $check['newDescription'],
            'newTitle' => $check['newTitle'],
            'readmeHash' => $check['readmeHash'],
            'folderId' => $folderId,
            'createdFolder' => $createdFolder,
            'firstMediaBefore' => $images[0]['mediaId'] ?? null,
            'operations' => $operations,
            'next' => 0,
            'added' => [],
            'failed' => [],
        ]);
    }

    /**
     * What linking an existing download to a repository would do: which of its images are
     * already in the repository (matched by file content, nothing downloaded), and which
     * repository screenshots it doesn't have yet. Nothing is saved.
     *
     * @return array{ref: GitHubRepositoryRef, repoName: string, branch: string, title: string, hasReadme: bool, zipUrl: string, switchesLink: bool, replacesFile: bool, matched: array<int, array{path: string, name: string, mediaId: int, mediaName: string}>, unmatched: array<int, array{path: string, name: string, sha: string, size: int}>}
     */
    public function previewLink(int $downloadId, GitHubRepositoryRef $ref): array
    {
        $download = $this->linkableDownload($downloadId);
        $repo = $this->client->fetchRepository($ref);
        $readme = $this->client->fetchReadme($ref, $repo['defaultBranch']);
        $listing = $this->client->listScreenshots($ref, $repo['defaultBranch']);
        $local = $this->localImageHashes($download);
        $matched = [];
        $unmatched = [];

        foreach ($listing as $entry) {
            $mediaId = $local[$entry['sha']] ?? null;
            $row = $mediaId !== null ? $this->media->find($mediaId) : null;

            if ($row !== null) {
                $matched[] = ['path' => $entry['path'], 'name' => $entry['name'], 'mediaId' => $mediaId, 'mediaName' => (string) $row['file_name']];
            } else {
                $unmatched[] = $entry;
            }
        }

        return [
            'ref' => $ref,
            'repoName' => $repo['name'],
            'branch' => $this->recordedBranch($download, $ref, $repo['defaultBranch']),
            'zipUrl' => $ref->archiveUrl($repo['defaultBranch']),
            'switchesLink' => $this->archiveBranch($download, $ref) === null,
            'replacesFile' => $download->type === DownloadType::File,
            'title' => $readme !== null ? ReadmeTransformer::title($readme, $repo['name']) : $repo['name'],
            'hasReadme' => $readme !== null,
            'matched' => $matched,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Attaches a repository to a download that wasn't imported from one, so it gains the
     * update screen. Its title, description, and files are left exactly as they are. With
     * $switchLink its download link also becomes GitHub's zip of the default branch, the way an
     * import sets it, unless it already points at one of this repository's zips. The recorded fingerprints are those of what an import would have
     * produced, so a title or description that differs counts as edited and is offered
     * unticked, and the README is treated as not yet applied so the first check can offer it.
     */
    public function linkDownload(int $downloadId, GitHubRepositoryRef $ref, bool $switchLink = false): void
    {
        $download = $this->linkableDownload($downloadId);
        $repo = $this->client->fetchRepository($ref);
        $branch = $repo['defaultBranch'];
        $readme = $this->client->fetchReadme($ref, $branch);
        $listing = $this->client->listScreenshots($ref, $branch);
        $local = $this->localImageHashes($download);

        $title = $readme !== null ? ReadmeTransformer::title($readme, $repo['name']) : $repo['name'];
        $readmePart = $readme !== null ? ReadmeTransformer::withoutGallery($this->cleanReadme($readme, $ref, $branch, $listing)) : '';

        $this->store->record($downloadId, $ref, $this->recordedBranch($download, $ref, $branch), hash('sha256', $title), hash('sha256', $readmePart), '');

        if ($switchLink && $this->archiveBranch($download, $ref) === null) {
            $zip = $ref->archiveUrl($branch);

            // A File download is replaced by the link; its own file is removed only if nothing else uses it.
            if ($download->type === DownloadType::File) {
                $this->downloads->convertToUrl($downloadId, $zip);
            } else {
                $this->downloads->update($downloadId, $download->title, $download->description, $download->folderId, $zip, null, $download->categoryId);
            }
        }

        foreach ($listing as $entry) {
            if (isset($local[$entry['sha']])) {
                $this->store->saveImage($downloadId, $entry['path'], $entry['sha'], $local[$entry['sha']]);
            }
        }
    }

    private function linkableDownload(int $downloadId): Download
    {
        $download = $this->downloads->findById($downloadId);

        if ($download === null) {
            throw new InvalidArgumentException('That download no longer exists.');
        }

        if ($this->store->find($downloadId) !== null) {
            throw new InvalidArgumentException('This download is already linked to a GitHub repository.');
        }

        return $download;
    }

    /**
     * A link that already points at this repository's branch zip keeps that branch, so an update
     * can later offer to move it; any other link is left alone by recording the current default.
     */
    private function recordedBranch(Download $download, GitHubRepositoryRef $ref, string $defaultBranch): string
    {
        return $this->archiveBranch($download, $ref) ?? $defaultBranch;
    }

    /**
     * The branch of this repository's zip the download already links to, or null for any other link.
     */
    private function archiveBranch(Download $download, GitHubRepositoryRef $ref): ?string
    {
        $pattern = '#^https://github\.com/' . preg_quote($ref->owner, '#') . '/' . preg_quote($ref->repo, '#') . '/archive/refs/heads/(.+)\.zip$#i';

        if ($download->targetUrl !== null && preg_match($pattern, $download->targetUrl, $matches) === 1) {
            return rawurldecode($matches[1]);
        }

        return null;
    }

    /**
     * Git's own fingerprint of each candidate image, mapped to its Media item. GitHub reports the
     * same fingerprint for every file, so a local copy can be matched without downloading anything.
     * Candidates are the images in the download's Media folder, plus its thumbnail.
     *
     * @return array<string, int> blob hash => media id
     */
    private function localImageHashes(Download $download): array
    {
        $ids = [];

        if ($download->folderId !== null) {
            foreach ($this->media->query(['type' => 'image', 'folderIds' => [$download->folderId]], 200, 0)['items'] as $item) {
                $ids[] = (int) $item['id'];
            }
        }

        if ($download->thumbnailMediaId !== null) {
            $ids[] = $download->thumbnailMediaId;
        }

        $hashes = [];

        foreach (array_unique($ids) as $id) {
            $row = $this->media->find($id);
            $path = $row !== null && str_starts_with((string) $row['mime_type'], 'image/') ? $this->media->absolutePath($row) : '';

            if ($path === '' || !is_file($path) || filesize($path) > GitHubClient::MAX_IMAGE_BYTES) {
                continue;
            }

            $hashes[$this->gitBlobHash($path)] ??= $id;
        }

        return $hashes;
    }

    /**
     * SHA-1 here is Git's file fingerprint, which GitHub reports; it is not used for any security purpose.
     */
    private function gitBlobHash(string $path): string
    {
        $context = hash_init('sha1');
        hash_update($context, 'blob ' . filesize($path) . "\0");
        hash_update_file($context, $path);

        return hash_final($context);
    }

    /**
     * Runs the next operation of a job.
     *
     * @return array{done: int, total: int, finished: bool, failed: array<int, string>}
     */
    public function runStep(string $token, int $userId): array
    {
        $job = $this->loadJob($token, $userId);
        $total = count($job['operations']);

        if ($job['next'] < $total) {
            $operation = $job['operations'][$job['next']];
            $job['next']++;
            $ref = GitHubRepositoryRef::fromParts($job['owner'], $job['repo']);

            if ($ref === null) {
                throw new InvalidArgumentException('The job is invalid.');
            }

            try {
                $this->runOperation($job, $ref, $operation);
            } catch (GitHubImportException | RuntimeException | InvalidArgumentException $exception) {
                $job['failed'][] = $operation['path'] . ': ' . $exception->getMessage();
            }

            $this->saveJob($token, $job);
        }

        return ['done' => $job['next'], 'total' => $total, 'finished' => $job['next'] >= $total, 'failed' => $job['failed']];
    }

    /**
     * @return array{downloadId: int, failed: array<int, string>}
     */
    public function finish(string $token, int $userId): array
    {
        $job = $this->loadJob($token, $userId);

        try {
            $downloadId = $job['mode'] === 'import' ? $this->finishImport($job) : $this->finishUpdate($job);
        } catch (RuntimeException | InvalidArgumentException $exception) {
            if ($job['mode'] === 'import') {
                $this->rollbackImport($job);
            }

            $this->discardJob($token);

            throw new RuntimeException('The download could not be saved. Nothing was added.', 0, $exception);
        }

        $this->discardJob($token);

        return ['downloadId' => $downloadId, 'failed' => $job['failed']];
    }

    /** Throws away an import that was cancelled or abandoned part way, removing what it had copied. */
    public function cancel(string $token, int $userId): void
    {
        $job = $this->loadJob($token, $userId);

        if ($job['mode'] === 'import') {
            $this->rollbackImport($job);
        }

        $this->discardJob($token);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $operation
     */
    private function runOperation(array &$job, GitHubRepositoryRef $ref, array $operation): void
    {
        if ($operation['op'] === 'add') {
            $position = count($job['added']) + ($job['mode'] === 'update' ? count($this->store->images((int) $job['downloadId'])) : 0) + 1;
            $title = $job['mode'] === 'import' ? (string) $job['title'] : (string) ($this->downloads->findById((int) $job['downloadId'])?->title ?? $ref->repo);
            $media = $this->ingestImage($ref, (string) $job['branch'], $operation, (int) $job['folderId'], (int) $job['userId'], $title . ' screenshot ' . $position);

            if ($job['mode'] === 'update') {
                $this->store->saveImage((int) $job['downloadId'], (string) $operation['path'], (string) $operation['sha'], (int) $media['id']);
            }

            $job['added'][] = ['path' => $operation['path'], 'sha' => $operation['sha'], 'mediaId' => (int) $media['id']];

            return;
        }

        if ($operation['op'] === 'replace') {
            $existing = $this->media->find((int) $operation['mediaId']);

            if ($existing === null) {
                throw new RuntimeException('The existing image is gone.');
            }

            $this->replaceInPlace($ref, (string) $job['branch'], $operation, $existing);
            $this->store->saveImage((int) $job['downloadId'], (string) $operation['path'], (string) $operation['sha'], (int) $operation['mediaId']);

            return;
        }

        if ($operation['op'] === 'remove') {
            $this->media->delete((int) $operation['mediaId']);
            $this->store->removeImage((int) $job['downloadId'], (string) $operation['path']);
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function finishImport(array $job): int
    {
        $ref = GitHubRepositoryRef::fromParts($job['owner'], $job['repo']);

        if ($ref === null) {
            throw new InvalidArgumentException('The job is invalid.');
        }

        $added = $job['added'];
        usort($added, static fn (array $a, array $b): int => strnatcasecmp($a['path'], $b['path']));

        $readmePart = ReadmeTransformer::withoutGallery((string) $job['description']);
        $description = ReadmeTransformer::replaceGallery($readmePart, $this->galleryImages($added, (string) $job['title']));

        $download = $this->downloads->create(
            (string) $job['title'],
            $description,
            (int) $job['folderId'],
            DownloadType::Url,
            null,
            $ref->archiveUrl((string) $job['branch']),
            (int) $job['userId'],
            ContentFormat::Markdown,
            $job['categoryId'] !== null ? (int) $job['categoryId'] : null,
        );

        if ($added !== []) {
            $this->downloads->setThumbnail($download->id, (int) $added[0]['mediaId']);
        }

        $this->store->record($download->id, $ref, (string) $job['branch'], hash('sha256', (string) $job['title']), hash('sha256', $readmePart), (string) $job['readmeHash']);

        foreach ($added as $image) {
            $this->store->saveImage($download->id, (string) $image['path'], (string) $image['sha'], (int) $image['mediaId']);
        }

        return $download->id;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function finishUpdate(array $job): int
    {
        $downloadId = (int) $job['downloadId'];
        $download = $this->downloads->findById($downloadId);
        $source = $this->store->find($downloadId);

        if ($download === null || $source === null) {
            throw new InvalidArgumentException('This download no longer exists.');
        }

        $images = $this->store->images($downloadId);
        usort($images, static fn (array $a, array $b): int => strnatcasecmp($a['repoPath'], $b['repoPath']));

        $title = $job['applyTitle'] ? (string) $job['newTitle'] : $download->title;
        $readmePart = $job['applyDescription'] ? ReadmeTransformer::withoutGallery((string) $job['newDescription']) : ReadmeTransformer::withoutGallery($download->description);
        $gallery = $this->galleryImages(array_map(static fn (array $image): array => ['path' => $image['repoPath'], 'mediaId' => $image['mediaId']], $images), $title);
        // A description is only rewritten when this update changed something it shows; applying nothing
        // (or just linking a download that had its own text) must leave it exactly as it was.
        $rebuildDescription = $job['operations'] !== [] || $job['applyDescription'];
        $description = $rebuildDescription ? ReadmeTransformer::replaceGallery($readmePart, $gallery) : $download->description;

        $externalUrl = $job['applyBranch'] ? $source['ref']->archiveUrl((string) $job['branch']) : null;

        $this->downloads->update(
            $downloadId,
            $title,
            $description,
            $download->folderId ?? (int) $job['folderId'],
            $externalUrl,
            $job['applyDescription'] ? ContentFormat::Markdown : null,
            $download->categoryId,
        );

        $this->refreshThumbnail($download, $job['firstMediaBefore'] ?? null, $images[0]['mediaId'] ?? null);

        $this->store->record(
            $downloadId,
            $source['ref'],
            $job['applyBranch'] ? (string) $job['branch'] : $source['branch'],
            $job['applyTitle'] ? hash('sha256', $title) : $source['titleHash'],
            $job['applyDescription'] ? hash('sha256', $readmePart) : $source['descriptionHash'],
            $job['applyDescription'] || $job['applyTitle'] ? (string) $job['readmeHash'] : $source['readmeHash'],
        );

        return $downloadId;
    }

    /**
     * The thumbnail follows the first screenshot only while it still is that screenshot, or
     * when it points at an image that no longer exists; a thumbnail someone chose stays put.
     */
    private function refreshThumbnail(Download $download, ?int $firstBefore, ?int $firstAfter): void
    {
        $current = $download->thumbnailMediaId;
        $missing = $current !== null && $this->media->find($current) === null;

        if ($firstAfter !== null && ($current === null || $missing || ($current === $firstBefore && $firstAfter !== $firstBefore))) {
            $this->downloads->setThumbnail($download->id, $firstAfter);
        } elseif ($firstAfter === null && $missing) {
            $this->downloads->setThumbnail($download->id, null);
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function rollbackImport(array $job): void
    {
        foreach ($job['added'] as $image) {
            $this->media->delete((int) $image['mediaId']);
        }

        if ($job['createdFolder']) {
            $this->folders->delete((int) $job['folderId']);
        }
    }

    /**
     * @param array<int, array{path: string, mediaId: int}> $images
     * @return array<int, array{fullUrl: string, thumbUrl: string, alt: string, width: int, height: int}>
     */
    private function galleryImages(array $images, string $title): array
    {
        $gallery = [];

        foreach ($images as $image) {
            $media = $this->media->find((int) $image['mediaId']);

            if ($media === null) {
                continue;
            }

            $full = $this->media->url($media);
            $gallery[] = [
                'fullUrl' => $full,
                'thumbUrl' => $this->thumbnails?->url($media, 'medium') ?? $full,
                'alt' => $title . ' screenshot ' . (count($gallery) + 1),
                'width' => (int) ($media['width'] ?? 0),
                'height' => (int) ($media['height'] ?? 0),
            ];
        }

        return $gallery;
    }

    /**
     * @param array{path: string, name: string, sha: string, size: int} $entry
     * @return array<string, mixed> the new media row
     */
    private function ingestImage(GitHubRepositoryRef $ref, string $branch, array $entry, int $folderId, int $userId, string $alt): array
    {
        $temporary = $this->temporaryFile();

        try {
            $this->client->downloadFile($ref, $branch, $entry['path'], $temporary);
            [$mimeType, $width, $height, $extension] = $this->inspectImage($temporary, $entry['name']);

            $safeName = $this->media->sanitizeFilename(pathinfo($entry['name'], PATHINFO_FILENAME));
            $relativePath = date('Y') . '/' . date('m') . '/' . $safeName . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
            $destination = rtrim($this->uploadsPath, '/') . '/' . $relativePath;

            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) {
                throw new RuntimeException('Unable to create the uploads directory.');
            }

            if (!copy($temporary, $destination)) {
                throw new RuntimeException('Unable to copy the image into the uploads directory.');
            }

            $media = $this->media->registerExistingFile($relativePath, $entry['name'], $mimeType, (int) filesize($destination), $width, $height, $userId, $folderId);
            $this->media->updateMetadata((int) $media['id'], $alt, null, null, null);
            $media = $this->media->find((int) $media['id']) ?? $media;
            $this->thumbnails?->generate($media);

            return $media;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Overwrites the Media item's file where it sits, so its id and address, and everything
     * pointing at them, keep working.
     *
     * @param array{path: string, name: string, sha: string, size: int} $entry
     * @param array<string, mixed> $existing
     */
    private function replaceInPlace(GitHubRepositoryRef $ref, string $branch, array $entry, array $existing): void
    {
        $temporary = $this->temporaryFile();

        try {
            $this->client->downloadFile($ref, $branch, $entry['path'], $temporary);
            [, $width, $height, $extension] = $this->inspectImage($temporary, $entry['name']);

            if ($extension !== strtolower(pathinfo((string) $existing['file_path'], PATHINFO_EXTENSION))) {
                throw new RuntimeException('The image changed file type, so it was left as it was.');
            }

            if (!copy($temporary, $this->media->absolutePath($existing))) {
                throw new RuntimeException('Unable to write the updated image.');
            }

            $this->media->refreshImageFile((int) $existing['id'], $width, $height);
            $this->thumbnails?->regenerate((int) $existing['id']);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Checks a downloaded file is really an allowed image by its content, not its name.
     *
     * @return array{0: string, 1: int, 2: int, 3: string} MIME type, width, height, extension
     */
    private function inspectImage(string $path, string $name): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($extension, GitHubClient::IMAGE_EXTENSIONS, true) || !$this->media->isAllowedExtension($extension)) {
            throw new RuntimeException('File type is not allowed.');
        }

        $mimeType = mime_content_type($path) ?: '';
        $dimensions = getimagesize($path);

        if (!str_starts_with($mimeType, 'image/') || !$this->media->isAllowedMimeType($mimeType) || $dimensions === false) {
            throw new RuntimeException('A downloaded file was not a valid image.');
        }

        return [$mimeType, $dimensions[0], $dimensions[1], $extension];
    }

    private function temporaryFile(): string
    {
        $path = tempnam($this->tempDir, 'lpgh');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        return $path;
    }

    /**
     * Reuses an existing top-level folder of the same name rather than making a duplicate.
     *
     * @return array{0: int, 1: bool} folder id, and whether this call created it
     */
    private function findOrCreateFolder(string $name): array
    {
        foreach ($this->folders->listAll() as $folder) {
            if ($folder->parentId === null && strcasecmp($folder->name, $name) === 0) {
                return [$folder->id, false];
            }
        }

        return [$this->folders->create($name)->id, true];
    }

    /**
     * @param array<int, array{path: string, name: string, sha: string, size: int}> $screenshots
     */
    private function cleanReadme(string $readme, GitHubRepositoryRef $ref, string $branch, array $screenshots): string
    {
        $paths = array_column($screenshots, 'path');

        return ReadmeTransformer::rewriteRelativeUrls(ReadmeTransformer::flattenHtml(ReadmeTransformer::stripScreenshots($readme, $paths)), $ref, $branch);
    }

    /**
     * @param array<int, array{path: string, name: string, sha: string, size: int}> $entries
     * @return array<string, array{path: string, name: string, sha: string, size: int}>
     */
    private function indexByPath(array $entries): array
    {
        return array_column($entries, null, 'path');
    }

    /**
     * @param array<string, mixed> $job
     * @return array{token: string, total: int}
     */
    private function storeJob(array $job): array
    {
        $token = bin2hex(random_bytes(16));
        $jobs = $_SESSION[self::SESSION_KEY] ?? [];
        $jobs[$token] = $job;
        $_SESSION[self::SESSION_KEY] = array_slice($jobs, -self::MAX_JOBS, null, true);

        return ['token' => $token, 'total' => count($job['operations'])];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJob(string $token, int $userId): array
    {
        $job = $_SESSION[self::SESSION_KEY][$token] ?? null;

        if (!is_array($job) || (int) $job['userId'] !== $userId) {
            throw new InvalidArgumentException('That import has expired. Start it again.');
        }

        return $job;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function saveJob(string $token, array $job): void
    {
        $_SESSION[self::SESSION_KEY][$token] = $job;
    }

    private function discardJob(string $token): void
    {
        unset($_SESSION[self::SESSION_KEY][$token]);
    }
}
