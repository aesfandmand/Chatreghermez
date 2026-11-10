<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Domain;

/** "Notify me" queue: when a slot frees up, one person per hour is offered it (first in line first). */
final class WaitQueue
{
    public const INTERVAL = 3600;

    /**
     * @param list<array{id:int,created_at:int,notified_at:int}> $entries waiting entries for ONE slot
     * @return array{id:int,created_at:int,notified_at:int}|null who to notify now; null if nobody or the hour is not over
     */
    public static function next(array $entries, int $lastNotifiedAt, int $now): ?array
    {
        if ($lastNotifiedAt > 0 && $now - $lastNotifiedAt < self::INTERVAL) {
            return null;
        }
        $waiting = array_values(array_filter($entries, static fn (array $e): bool => (int) $e['notified_at'] === 0));
        usort($waiting, static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
        return $waiting[0] ?? null;
    }
}
