<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Support;

/** Fixed-window limiter over an injected counter store (transients in WP, array in tests). */
final class RateLimiter
{
    /** @param callable(string,int):int $hit increments the key inside a window of N seconds and returns the new count */
    public function __construct(private $hit)
    {
    }

    public function allow(string $key, int $max, int $windowSeconds): bool
    {
        return ($this->hit)($key, $windowSeconds) <= $max;
    }

    /** WordPress-backed instance (transients). */
    public static function wordpress(): self
    {
        return new self(static function (string $key, int $window): int {
            $k = 'crc_rl_' . md5($key);
            $n = (int) get_transient($k) + 1;
            set_transient($k, $n, $window);
            return $n;
        });
    }
}
