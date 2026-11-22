<?php
declare(strict_types=1);
namespace Omnigo;

/** Fixed HTTPS origins: test credentials never require a caller-supplied URL. */
enum HepsiburadaEnvironment: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';

    public function orderOrigin(): string
    {
        return match ($this) {
            self::Production => 'https://oms-external.hepsiburada.com',
            self::Sandbox => 'https://oms-external-sit.hepsiburada.com',
        };
    }
}
