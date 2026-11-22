<?php
declare(strict_types=1);
namespace Omnigo;

final class N11Credentials
{
    private function __construct(public readonly string $apiKey,public readonly string $apiSecret) {}
    public static function fromArray(array $input): self
    {
        foreach(['apiKey','apiSecret'] as $field) {
            if(!is_string($input[$field]??null))throw new \InvalidArgumentException('n11 API anahtarını ve API şifresini girin.');
            $input[$field]=trim($input[$field]);
            if(!preg_match('/^[\x21-\x7e]{1,2048}$/D',$input[$field]))throw new \InvalidArgumentException('n11 API bilgilerinin biçimini kontrol edin.');
        }
        return new self($input['apiKey'],$input['apiSecret']);
    }
    public function __debugInfo(): array {return ['apiKey'=>'[gizli]','apiSecret'=>'[gizli]'];}
}
