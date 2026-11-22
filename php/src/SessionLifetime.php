<?php
declare(strict_types=1);
namespace Omnigo;

/** Session lifetime is separate from the browser's maximum cookie lifetime. */
final class SessionLifetime
{
    // A reserved BIGINT value keeps existing expiry queries and revocation compatible.
    public const UNTIL_REVOKED = 253402300799;
    public const TEMPORARY_SECONDS = 28800;
    public const COOKIE_SECONDS = 400 * 86400;

    public static function expiry(bool $persistent): int
    {
        return $persistent ? self::UNTIL_REVOKED : time() + self::TEMPORARY_SECONDS;
    }
    public static function persistent(int $expires): bool
    {
        return $expires === self::UNTIL_REVOKED;
    }
    public static function cookieExpiry(bool $persistent): int
    {
        return $persistent ? time() + self::COOKIE_SECONDS : 0;
    }
}
