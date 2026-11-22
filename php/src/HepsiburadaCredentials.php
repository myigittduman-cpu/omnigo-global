<?php
declare(strict_types=1);
namespace Omnigo;

final readonly class HepsiburadaCredentials
{
    private function __construct(public string $merchantId,public string $username,public string $serviceKey,public string $userAgent) {}
    public static function fromArray(array $input): self
    {
        $values=[];
        foreach(['merchantId','username','serviceKey','userAgent'] as $field){
            $value=$input[$field]??null;
            if(!is_string($value)||!preg_match('/^[\x21-\x7e]{1,2048}$/D',trim($value)))throw new \InvalidArgumentException('Hepsiburada bağlantı alanlarını kontrol edin.');
            $values[$field]=trim($value);
        }
        if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$values['merchantId']))throw new \InvalidArgumentException('Merchant ID, Hepsiburada mağaza numarası olmalıdır.');
        if(str_contains($values['username'],':')||strlen($values['username'])>200||strlen($values['userAgent'])>200)throw new \InvalidArgumentException('API kullanıcı adını ve User-Agent bilgisini kontrol edin.');
        return new self(strtolower($values['merchantId']),$values['username'],$values['serviceKey'],$values['userAgent']);
    }
    public function __debugInfo(): array { return ['merchantId'=>$this->merchantId,'credentials'=>'[gizli]']; }
}
