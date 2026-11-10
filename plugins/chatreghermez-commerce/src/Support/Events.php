<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Support;

/**
 * Domain events are published ONLY after the database change is committed.
 * Listeners (SMS, CRM, analytics) hook `crc_event`; they never run inside a transaction.
 */
final class Events
{
    /** @param array<string,mixed> $payload */
    public static function dispatch(string $name, array $payload = []): void
    {
        do_action('crc_event', $name, $payload);
        do_action('crc_event_' . $name, $payload);
    }
}
