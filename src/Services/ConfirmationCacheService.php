<?php
namespace WFOT\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class ConfirmationCacheService
{
    private const DEFAULT_LAST_MODIFIED_CANDIDATES = [
        'Receipt Timestamp',
        'Last Modified',
        'Last Modified Time',
        'Last Modified Date',
        'Modified',
        'Updated'
    ];

    public static function confirmationsDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/confirmations';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public static function metadataPath(string $bookingId): string
    {
        return rtrim(self::confirmationsDir(), '/') . '/' . $bookingId . '.json';
    }

    public static function loadMetadata(string $bookingId): ?array
    {
        $path = self::metadataPath($bookingId);
        if (!file_exists($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function storeMetadata(string $bookingId, ?string $sourceLastModified): void
    {
        $payload = [
            'booking_id' => $bookingId,
            'generated_at' => self::now()->format(DateTimeInterface::ATOM),
            'source_last_modified' => $sourceLastModified,
        ];
        file_put_contents(self::metadataPath($bookingId), json_encode($payload, JSON_PRETTY_PRINT));
    }

    public static function extractLastModified(array $booking): ?string
    {
        $fields = $booking['fields'] ?? [];
        $configuredField = env('BOOKING_LAST_MODIFIED_FIELD');
        $candidates = array_values(array_unique(array_filter(array_merge(
            $configuredField ? [$configuredField] : [],
            self::DEFAULT_LAST_MODIFIED_CANDIDATES
        ))));

        foreach ($candidates as $candidate) {
            if (!empty($fields[$candidate])) {
                return $fields[$candidate];
            }
        }
        return null;
    }

    public static function requiresRefresh(?array $metadata, ?string $latestSourceValue, string $pdfPath, int $graceSeconds): bool
    {
        if (!file_exists($pdfPath)) {
            return true;
        }
        if ($latestSourceValue === null) {
            return $metadata === null; // no reference -> only refresh if we never cached metadata
        }
        if (!$metadata || empty($metadata['source_last_modified'])) {
            return true;
        }

        $latest = self::toDateTime($latestSourceValue);
        $cached = self::toDateTime($metadata['source_last_modified']);

        if (!$latest || !$cached) {
            return true;
        }

        return ($latest->getTimestamp() - $cached->getTimestamp()) > $graceSeconds;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function toDateTime(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }
}
