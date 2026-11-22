<?php
declare(strict_types=1);
namespace Omnigo;

/** Display-only identity, bound to the tenant, provider, claim and source order. */
final class ReturnBuyerIdentity
{
    public function __construct(private DeliveryCipher $cipher){}
    public function protectN11(int $company,array $claim): array
    {
        $name=ReturnRequestMapper::text($claim['buyerName']??'',200);
        unset($claim['buyerName']);
        if($name!=='')$claim['buyerIdentity']=$this->cipher->seal($company,
            $this->scope('n11',(string)$claim['claimReturnId'],(string)$claim['orderNumber']),['name'=>$name]);
        return $claim;
    }
    public function name(int $company,string $provider,array $item): ?string
    {
        if($provider!=='n11'||empty($item['buyerIdentity']))return null;
        try{
            $value=$this->cipher->open($company,$this->scope($provider,(string)$item['id'],(string)$item['orderNumber']),$item['buyerIdentity']);
            return ReturnRequestMapper::text($value['name']??'',200)?:null;
        }catch(\Throwable){return null;}
    }
    private function scope(string $provider,string $claim,string $order): string
    {
        return 'return-buyer:'.hash('sha256',json_encode([$provider,$claim,$order],JSON_THROW_ON_ERROR));
    }
}
