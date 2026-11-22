<?php
declare(strict_types=1);
namespace Omnigo;

/** Server-only pilot registration. Both the tenant id and its current name must match. */
final class MarketplaceStockPilot
{
    private function __construct(private array $companies) {}

    public static function load(?string $path=null): self
    {
        $path??=dirname(__DIR__).'/storage/marketplace-stock-pilot.json';
        if(!is_file($path)||!is_readable($path))return new self([]);
        $raw=file_get_contents($path,false,null,0,32769);
        if($raw===false||strlen($raw)>32768)return new self([]);
        $config=json_decode($raw,true,8);
        return new self(is_array($config['companies']??null)?$config['companies']:[]);
    }

    public function enabled(array $company): bool
    {
        $hash=$this->companies[(int)$company['id']]??null;
        return is_string($hash)&&hash_equals(hash('sha256',(string)$company['name']),$hash);
    }
}
