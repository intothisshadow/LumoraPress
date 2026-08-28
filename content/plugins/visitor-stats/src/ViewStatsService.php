<?php

/**
 * Referrer/browser/device/country breakdown recording and GeoLite2 CSV import for the Visitor & Post View Statistics plugin (LPP-014).
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

namespace LumoraPress\Plugins\VisitorStats;

use LumoraPress\Core\Database\Database;
use RuntimeException;
use ZipArchive;

/**
 * Four site-wide daily-aggregate breakdown tables (never per-post — a
 * per-post x per-dimension cross product would grow unbounded) plus the
 * geoip_ranges lookup table the country breakdown depends on. Every
 * recorder here is called, at most, once per guest post view — see
 * visitor-stats.php's single_post_viewed listener, the only caller.
 * Takes Database directly (like PostViewService) rather than reaching
 * into ActiveKernel internally, so it stays trivially unit-testable
 * against the PHP Test Suite's SQLite-backed fixtures.
 *
 * Privacy: this class never persists a raw IP address, a raw
 * User-Agent string, or a full referrer URL. The raw IP passed to
 * countryForIp() is used only for the in-memory range lookup below and
 * is never written anywhere by this class.
 */
final class ViewStatsService
{
    /**
     * Rows processed per importGeoCsvBatch() call by default — small
     * enough that even a slow shared host completes one batch well
     * within a typical webserver/proxy request timeout, large enough
     * that a real ~450k-row Blocks CSV finishes in a low double-digit
     * number of "Continue" clicks, not hundreds.
     */
    private const DEFAULT_IMPORT_BATCH_SIZE = 20000;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    public function recordReferrer(string $referrerDomain): void
    {
        $this->upsertBreakdown($this->referrersTable(), 'referrer_domain', $referrerDomain);
    }

    public function recordBrowser(string $browser): void
    {
        $this->upsertBreakdown($this->browsersTable(), 'browser', $browser);
    }

    public function recordDevice(string $deviceType): void
    {
        $this->upsertBreakdown($this->devicesTable(), 'device_type', $deviceType);
    }

    public function recordCountry(string $countryCode): void
    {
        $this->upsertBreakdown($this->countriesTable(), 'country_code', $countryCode);
    }

    /**
     * A portable check-then-insert/update, same reasoning as
     * PostViewService::recordView() — SQLite-portable for unit tests,
     * never a MySQL-only `ON DUPLICATE KEY UPDATE`.
     */
    private function upsertBreakdown(string $table, string $column, string $value): void
    {
        $today = date('Y-m-d');

        $exists = ((int) $this->database->fetchColumn(
            "SELECT COUNT(*) FROM {$table} WHERE view_date = :view_date AND {$column} = :value",
            ['view_date' => $today, 'value' => $value],
        )) > 0;

        if ($exists) {
            $this->database->execute(
                "UPDATE {$table} SET views = views + 1 WHERE view_date = :view_date AND {$column} = :value",
                ['view_date' => $today, 'value' => $value],
            );
        } else {
            $this->database->execute(
                "INSERT INTO {$table} (view_date, {$column}, views) VALUES (:view_date, :value, 1)",
                ['view_date' => $today, 'value' => $value],
            );
        }
    }

    /**
     * Resolves an IPv4 address to a country code via the imported
     * geoip_ranges table — null if no ranges have ever been imported, or
     * the address falls in a gap the dataset doesn't cover (matches
     * this ticket's "degrades to absent, not broken" requirement).
     * IPv6 addresses always resolve to null in v1 (see README.md).
     */
    public function countryForIp(string $ipAddress): ?string
    {
        $ipLong = ip2long($ipAddress);

        if ($ipLong === false) {
            return null;
        }

        $ipInt = $ipLong < 0 ? $ipLong + 4294967296 : $ipLong;

        $row = $this->database->fetchOne(
            'SELECT network_end, country_code FROM ' . $this->geoipRangesTable() . '
              WHERE network_start <= :ip
              ORDER BY network_start DESC
              LIMIT 1',
            ['ip' => $ipInt],
        );

        if ($row === null || (int) $row['network_end'] < $ipInt) {
            return null;
        }

        return (string) $row['country_code'];
    }

    /**
     * How many ranges are currently loaded — drives the settings
     * screen's "N ranges loaded / Not installed" status line.
     */
    public function geoipRangeCount(): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->geoipRangesTable(),
        );
    }

    /**
     * Pulls the two CSV files this plugin needs straight out of MaxMind's
     * own GeoLite2-Country **CSV-format** ZIP download — the same file
     * `maxmind.com`'s download page offers, no local unzip step needed
     * first. MaxMind nests the CSVs inside a dated subfolder
     * (`GeoLite2-Country-CSV_YYYYMMDD/...`) that varies release to
     * release, so entries are matched by basename rather than a fixed
     * path; each matching entry is stream-copied straight to
     * $blocksDestination/$locationsDestination without ever writing the
     * ZIP's own folder structure to disk. Requires the PHP `zip`
     * extension (bundled with PHP by default, but not universally
     * enabled on every host) — throws a clear message if it's missing
     * rather than a bare fatal error.
     */
    public function extractGeoZip(string $zipPath, string $blocksDestination, string $locationsDestination): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException("This server's PHP doesn't have the zip extension enabled — upload the two CSV files directly instead.");
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open that file as a ZIP archive.');
        }

        try {
            $blocksEntry = $this->findZipEntryByBasename($zip, 'GeoLite2-Country-Blocks-IPv4.csv');
            $locationsEntry = $this->findZipEntryByBasename($zip, 'GeoLite2-Country-Locations-en.csv');

            if ($blocksEntry === null || $locationsEntry === null) {
                throw new RuntimeException('That ZIP file doesn\'t contain both GeoLite2-Country-Blocks-IPv4.csv and GeoLite2-Country-Locations-en.csv — make sure you downloaded the CSV format, not the .mmdb format.');
            }

            $this->extractZipEntryTo($zip, $blocksEntry, $blocksDestination);
            $this->extractZipEntryTo($zip, $locationsEntry, $locationsDestination);
        } finally {
            $zip->close();
        }
    }

    private function findZipEntryByBasename(ZipArchive $zip, string $basename): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);

            if ($entryName !== false && basename($entryName) === $basename) {
                return $entryName;
            }
        }

        return null;
    }

    private function extractZipEntryTo(ZipArchive $zip, string $entryName, string $destination): void
    {
        $source = $zip->getStream($entryName);

        if ($source === false) {
            throw new RuntimeException("Could not read {$entryName} from the ZIP archive.");
        }

        $target = fopen($destination, 'wb');

        if ($target === false) {
            fclose($source);

            throw new RuntimeException("Could not write to {$destination}.");
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
    }

    /**
     * Parses MaxMind's own GeoLite2-Country CSV export (not the binary
     * .mmdb format — see this plugin's README.md for why) in one call,
     * looping importGeoCsvBatch() to completion. Fine for a small file
     * or a CLI/test context with no request-lifetime limit; a real
     * ~450k-row Blocks CSV import triggered from the browser should use
     * importGeoCsvBatch() directly instead, across repeated requests —
     * see that method's own docblock for why a single synchronous
     * request isn't reliable for this at real GeoLite2 scale.
     *
     * @return int Number of ranges imported.
     */
    public function importGeoCsv(string $blocksCsvPath, string $locationsCsvPath): int
    {
        $totalImported = 0;
        $byteOffset = 0;
        $isFirstBatch = true;

        do {
            $result = $this->importGeoCsvBatch($blocksCsvPath, $locationsCsvPath, $byteOffset, $isFirstBatch, self::DEFAULT_IMPORT_BATCH_SIZE);
            $totalImported += $result['importedInBatch'];
            $byteOffset = $result['nextByteOffset'];
            $isFirstBatch = false;
        } while (!$result['done']);

        return $totalImported;
    }

    /**
     * Processes one batch of rows from the Blocks CSV, starting at
     * $byteOffset (an fseek() position — this method returns the exact
     * position the *next* call should resume from, in `nextByteOffset`,
     * the same "caller loops across requests, incrementing an offset
     * each time" shape ThumbnailService::queueForBulkRegeneration()
     * already uses). Exists because a real GeoLite2-Country-
     * Blocks-IPv4.csv is routinely ~450k rows — importing all of it as
     * one synchronous request/transaction can run past a shared host's
     * own webserver/proxy timeout even with set_time_limit(0) lifting
     * *PHP's* limit, found live (xenacentral.com: the import completed
     * successfully server-side — confirmed by the resulting range
     * count — but the response itself never made it back before the
     * connection was cut).
     *
     * $isFirstBatch (not $byteOffset === 0, which is also where the
     * very first *data* row after the header naturally starts) is the
     * caller's explicit signal to truncate geoip_ranges once, up front
     * — an entirely fresh call from a cold start, not "resume from the
     * beginning of a file for some other reason."
     *
     * The country-name lookup (readLocationsCsv()) and the Blocks
     * header/column-index resolution are both cheap and re-done on
     * every batch rather than threaded through as extra state the
     * caller would otherwise need to persist between requests
     * (Locations is a small, fixed-size file — one row per country, not
     * one per IP range) — only the byte offset itself needs to survive
     * from one request to the next, kept in the admin view's own hidden
     * form field, no server-side session/cache state required.
     *
     * @return array{importedInBatch: int, nextByteOffset: int, done: bool}
     */
    public function importGeoCsvBatch(string $blocksCsvPath, string $locationsCsvPath, int $byteOffset, bool $isFirstBatch, int $batchSize = self::DEFAULT_IMPORT_BATCH_SIZE): array
    {
        $countryByGeonameId = $this->readLocationsCsv($locationsCsvPath);
        $pdo = $this->database->pdo();

        $blocksHandle = fopen($blocksCsvPath, 'rb');

        if ($blocksHandle === false) {
            throw new RuntimeException('Could not open the Blocks CSV file.');
        }

        $header = fgetcsv($blocksHandle, null, ",", "\"", "\\");

        if ($header === false) {
            fclose($blocksHandle);

            throw new RuntimeException('The Blocks CSV file is empty.');
        }

        $networkColumn = array_search('network', $header, true);
        $geonameIdColumn = array_search('geoname_id', $header, true);
        $registeredCountryColumn = array_search('registered_country_geoname_id', $header, true);

        if ($networkColumn === false || $geonameIdColumn === false || $registeredCountryColumn === false) {
            fclose($blocksHandle);

            throw new RuntimeException('The Blocks CSV file is missing expected columns.');
        }

        if (fseek($blocksHandle, $isFirstBatch ? ftell($blocksHandle) : $byteOffset) !== 0) {
            fclose($blocksHandle);

            throw new RuntimeException('Could not seek to the requested position in the Blocks CSV file.');
        }

        try {
            $result = $this->database->transaction(function () use ($pdo, $blocksHandle, $networkColumn, $geonameIdColumn, $registeredCountryColumn, $countryByGeonameId, $batchSize, $isFirstBatch): array {
                if ($isFirstBatch) {
                    $pdo->exec('DELETE FROM ' . $this->geoipRangesTable());
                }

                $insert = $pdo->prepare(
                    'INSERT INTO ' . $this->geoipRangesTable() . ' (network_start, network_end, country_code) VALUES (:network_start, :network_end, :country_code)',
                );

                $imported = 0;
                $rowsRead = 0;
                $done = false;

                while ($rowsRead < $batchSize) {
                    $row = fgetcsv($blocksHandle, null, ",", "\"", "\\");

                    if ($row === false) {
                        $done = true;

                        break;
                    }

                    $rowsRead++;
                    $geonameId = trim((string) ($row[$geonameIdColumn] ?? ''));
                    $registeredGeonameId = trim((string) ($row[$registeredCountryColumn] ?? ''));
                    $countryCode = $countryByGeonameId[$geonameId] ?? $countryByGeonameId[$registeredGeonameId] ?? null;

                    if ($countryCode === null) {
                        continue;
                    }

                    $range = $this->cidrToRange((string) ($row[$networkColumn] ?? ''));

                    if ($range === null) {
                        continue;
                    }

                    $insert->execute([
                        'network_start' => $range[0],
                        'network_end' => $range[1],
                        'country_code' => $countryCode,
                    ]);

                    $imported++;
                }

                return ['imported' => $imported, 'done' => $done];
            });
        } finally {
            $nextByteOffset = ftell($blocksHandle);
            fclose($blocksHandle);
        }

        return [
            'importedInBatch' => $result['imported'],
            'nextByteOffset' => $nextByteOffset === false ? $byteOffset : $nextByteOffset,
            'done' => $result['done'],
        ];
    }

    /**
     * @return array<string, string> geoname_id => ISO country code
     */
    private function readLocationsCsv(string $locationsCsvPath): array
    {
        $handle = fopen($locationsCsvPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not open the Locations CSV file.');
        }

        $header = fgetcsv($handle, null, ",", "\"", "\\");

        if ($header === false) {
            fclose($handle);

            throw new RuntimeException('The Locations CSV file is empty.');
        }

        $geonameIdColumn = array_search('geoname_id', $header, true);
        $countryIsoColumn = array_search('country_iso_code', $header, true);

        if ($geonameIdColumn === false || $countryIsoColumn === false) {
            fclose($handle);

            throw new RuntimeException('The Locations CSV file is missing expected columns.');
        }

        $map = [];

        while (($row = fgetcsv($handle, null, ",", "\"", "\\")) !== false) {
            $geonameId = trim((string) ($row[$geonameIdColumn] ?? ''));
            $countryCode = trim((string) ($row[$countryIsoColumn] ?? ''));

            if ($geonameId !== '' && $countryCode !== '') {
                $map[$geonameId] = $countryCode;
            }
        }

        fclose($handle);

        return $map;
    }

    /**
     * Converts a CIDR block (e.g. "1.0.0.0/24") to an inclusive
     * [network_start, network_end] pair of unsigned 32-bit integers.
     * Returns null for anything that isn't a plain IPv4 CIDR (IPv6
     * ranges are skipped entirely — v1 is IPv4-only, see README.md).
     *
     * @return array{0: int, 1: int}|null
     */
    private function cidrToRange(string $cidr): ?array
    {
        if (!str_contains($cidr, '/')) {
            return null;
        }

        [$address, $prefixLength] = explode('/', $cidr, 2);
        $ipLong = ip2long($address);
        $prefixLength = (int) $prefixLength;

        if ($ipLong === false || $prefixLength < 0 || $prefixLength > 32) {
            return null;
        }

        $ipInt = $ipLong < 0 ? $ipLong + 4294967296 : $ipLong;
        $hostBits = 32 - $prefixLength;
        $mask = $hostBits === 32 ? 0 : (~0 << $hostBits) & 0xFFFFFFFF;

        $networkStart = $ipInt & $mask;
        $networkEnd = $networkStart | (~$mask & 0xFFFFFFFF);

        return [$networkStart, $networkEnd];
    }

    /**
     * Top rows for one breakdown table over the last $days days — a
     * bounded, capped list, same shape as PostViewService::mostViewed().
     *
     * @return array<int, array{value: string, views: int}>
     */
    private function topBreakdown(string $table, string $column, int $days, int $limit): array
    {
        $rows = $this->database->fetchAll(
            "SELECT {$column} AS value, SUM(views) AS views FROM {$table}
              WHERE view_date >= :since
              GROUP BY {$column}
              ORDER BY views DESC
              LIMIT " . max(1, $limit),
            ['since' => date('Y-m-d', strtotime('-' . max(0, $days) . ' days'))],
        );

        return array_map(static fn (array $row): array => [
            'value' => (string) $row['value'],
            'views' => (int) $row['views'],
        ], $rows);
    }

    /**
     * @return array<int, array{value: string, views: int}>
     */
    public function topReferrers(int $days, int $limit = 5): array
    {
        return $this->topBreakdown($this->referrersTable(), 'referrer_domain', $days, $limit);
    }

    /**
     * @return array<int, array{value: string, views: int}>
     */
    public function topBrowsers(int $days, int $limit = 5): array
    {
        return $this->topBreakdown($this->browsersTable(), 'browser', $days, $limit);
    }

    /**
     * @return array<int, array{value: string, views: int}>
     */
    public function topDevices(int $days, int $limit = 5): array
    {
        return $this->topBreakdown($this->devicesTable(), 'device_type', $days, $limit);
    }

    /**
     * @return array<int, array{value: string, views: int}>
     */
    public function topCountries(int $days, int $limit = 5): array
    {
        return $this->topBreakdown($this->countriesTable(), 'country_code', $days, $limit);
    }

    private function referrersTable(): string
    {
        return $this->tablePrefix . 'view_stats_referrers';
    }

    private function browsersTable(): string
    {
        return $this->tablePrefix . 'view_stats_browsers';
    }

    private function devicesTable(): string
    {
        return $this->tablePrefix . 'view_stats_devices';
    }

    private function countriesTable(): string
    {
        return $this->tablePrefix . 'view_stats_countries';
    }

    private function geoipRangesTable(): string
    {
        return $this->tablePrefix . 'geoip_ranges';
    }
}
