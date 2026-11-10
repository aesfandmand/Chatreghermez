<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Account;

/** One-time login codes: 6 digits, 2 minutes, max 5 wrong tries, resend after 60 s. Codes are stored only as keyed hashes. */
final class OtpPolicy
{
    public const TTL = 120;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_AFTER = 60;

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function hash(string $mobile, string $code, string $secret): string
    {
        return hash_hmac('sha256', $mobile . '|' . $code, $secret);
    }

    public static function verify(string $mobile, string $code, string $storedHash, string $secret): bool
    {
        return hash_equals($storedHash, self::hash($mobile, $code, $secret));
    }

    public static function canResend(int $lastSentAt, int $now): bool
    {
        return $now - $lastSentAt >= self::RESEND_AFTER;
    }

    public static function expired(int $createdAt, int $now): bool
    {
        return $now - $createdAt > self::TTL;
    }
}
