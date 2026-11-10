<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Import;

use Chatreghermez\Commerce\Audit\AuditLog;
use Chatreghermez\MediaRental\Media\SlotService;

/** Writes an analyzed sheet to the database. New media are drafts with price 0 (the supervisor completes them in the panel). */
final class MediaImporter
{
    /** @param array<string,mixed> $analysis from SheetImporter::analyze @return array{created:int,skipped:int} */
    public static function commit(array $analysis, string $city, int $actorId): array
    {
        global $wpdb;
        $media = $wpdb->prefix . 'crc_media';
        $slots = $wpdb->prefix . 'crc_media_slots';
        $year = (int) $analysis['fiscal_year'];
        $canon = self::canonicalBrands($analysis);
        $created = $skipped = 0;

        foreach ((array) $analysis['items'] as $it) {
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$media} WHERE code = %s", $it['code']))) {
                $skipped++; // never overwrite a media that already exists
                continue;
            }
            $w = $it['width_m'] !== null ? (int) round($it['width_m'] * 100) : 0;
            $h = $it['height_m'] !== null ? (int) round($it['height_m'] * 100) : 0;
            $wpdb->insert($media, [
                'code' => $it['code'], 'name' => mb_substr((string) $it['location'], 0, 200), 'media_type' => (string) $it['type'],
                'width_cm' => $w, 'height_cm' => $h, 'orientation' => (string) ($it['orientation'] ?: ($w && $h ? ($w >= $h ? 'landscape' : 'portrait') : '')), 'lit' => empty($it['lit']) ? 0 : 1,
                'city' => $city, 'status' => 'draft', 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $id = (int) $wpdb->insert_id;
            if ($id === 0) {
                $skipped++;
                continue;
            }
            SlotService::ensure($id, $year);
            foreach ((array) $it['months'] as $month => $cell) {
                if ($cell['status'] === 'free') {
                    continue;
                }
                $state = $cell['status'] === 'blocked' ? 'blocked' : 'final';
                $brand = $cell['status'] === 'reserved' ? ($canon[MediaSheetRules::dedupeKey((string) $cell['brand'])] ?? (string) $cell['brand']) : '';
                $wpdb->update($slots, ['state' => $state, 'brand' => mb_substr($brand, 0, 160)], ['media_id' => $id, 'fiscal_year' => $year, 'month' => (int) $month]);
            }
            $created++;
        }
        AuditLog::record($actorId, 'media.imported', 'media_import', (string) $year, null, ['created' => $created, 'skipped' => $skipped, 'city' => $city]);
        return ['created' => $created, 'skipped' => $skipped];
    }

    /** @return array<string,string> dedupe key => the most frequent spelling */
    private static function canonicalBrands(array $analysis): array
    {
        $count = [];
        foreach ((array) $analysis['items'] as $it) {
            foreach ((array) $it['months'] as $cell) {
                if ($cell['status'] === 'reserved') {
                    $k = MediaSheetRules::dedupeKey((string) $cell['brand']);
                    $count[$k][(string) $cell['brand']] = ($count[$k][(string) $cell['brand']] ?? 0) + 1;
                }
            }
        }
        $out = [];
        foreach ($count as $k => $names) {
            arsort($names);
            $out[$k] = (string) array_key_first($names);
        }
        return $out;
    }
}
