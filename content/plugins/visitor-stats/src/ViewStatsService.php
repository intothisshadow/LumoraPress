<?php

/**
 * Referrer/browser/device/country breakdown recording and GeoLite2 CSV import for the Visitor & Post View Statistics plugin.
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
 * Four site-wide daily-aggregate breakdown tables (never per-post, which would grow
 * unbounded) plus the geoip_ranges lookup table the country breakdown depends on. Takes
 * Database directly so it stays unit-testable against SQLite fixtures.
 *
 * Privacy: this class never persists a raw IP address, User-Agent string, or full referrer
 * URL. The raw IP passed to countryForIp() is used only for the in-memory range lookup.
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
     * Extracts the two CSVs this plugin needs from MaxMind's own
     * GeoLite2-Country CSV-format ZIP download. MaxMind nests them inside
     * a dated subfolder that varies release to release, so entries are
     * matched by basename rather than a fixed path. Requires the PHP
     * `zip` extension (not universally enabled on every host).
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
     * Parses MaxMind's GeoLite2-Country CSV export in one call, looping importGeoCsvBatch()
     * to completion. Fine for a small file or CLI/test context; a real ~450k-row browser
     * import should use importGeoCsvBatch() directly across repeated requests instead.
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
     * Processes one batch of rows from the Blocks CSV, starting at $byteOffset (an fseek()
     * position; the resume position is returned in `nextByteOffset`). Batching exists
     * because a real Blocks CSV is routinely ~450k rows, which can run past a shared host's
     * timeout in one request. $isFirstBatch (not $byteOffset === 0) is the caller's explicit
     * signal to truncate geoip_ranges once, so a cold start isn't confused with resuming.
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
