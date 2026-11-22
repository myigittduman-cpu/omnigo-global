<?php
declare(strict_types=1);
namespace Omnigo;

/** Customer menu presentation only. It does not grant access to a page. */
final class CustomerNavigationLayout
{
    public const STANDARD = 'standard';
    public const LOGISTICS = 'logistics';
    public const MANAGER = 'store_manager';

    public static function validate(mixed $value): string
    {
        Validation::choice($value, [self::STANDARD, self::LOGISTICS, self::MANAGER]);
        return self::STANDARD;
    }

    public static function forRole(?string $role): string
    {
        return self::STANDARD;
    }

    public static function effective(mixed $saved, ?string $role): string
    {
        return self::STANDARD;
    }

    public static function stored(array $policy): array
    {
        return array_filter((array)($policy['navigationLayouts'] ?? []), static fn(mixed $layout): bool =>
            in_array($layout, [self::STANDARD, self::LOGISTICS, self::MANAGER], true)
        );
    }
}
