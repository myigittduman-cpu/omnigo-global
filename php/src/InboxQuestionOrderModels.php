<?php
declare(strict_types=1);
namespace Omnigo;

/** Private, product-only evidence from exact orders confirmed by marketplace APIs. */
final class InboxQuestionOrderModels
{
    private const BRANDS = [
        'profilo'=>'Profilo','regal'=>'Regal','bosch'=>'Bosch','siemens'=>'Siemens','vestel'=>'Vestel',
        'altus'=>'Altus','beko'=>'Beko','arcelik'=>'Arçelik','samsung'=>'Samsung','lg'=>'LG',
        'grundig'=>'Grundig','hoover'=>'Hoover','seg'=>'SEG',
    ];
    private ?array $orders=null;
    private ?string $master=null;

    public function __construct(private ?string $keyPath=null, private ?string $mapPath=null) {}

    public static function token(string $master,int $company,string $provider,string $number): string
    {
        if(strlen($master)!==32||$company<1||!in_array($provider,['idefix','koctas'],true)||$number==='')return '';
        $key=hash_hkdf('sha256',$master,32,'omnigo-inbox-question-order-models:v1');
        return hash_hmac('sha256',json_encode([$company,$provider,$number],JSON_THROW_ON_ERROR),$key);
    }

    public function find(int $company,string $provider,string $number): ?array
    {
        if(!in_array($provider,['idefix','koctas'],true)||$number==='')return null;
        $this->load();
        if($this->master===null||!$this->orders)return null;
        $identity=$this->orders[self::token($this->master,$company,$provider,$number)]??null;
        if(!is_string($identity)||!preg_match('~^([a-z0-9]+):([A-Z0-9][A-Z0-9./-]{2,24})$~D',$identity,$parts))return null;
        $brand=self::BRANDS[$parts[1]]??null;
        return $brand?['model'=>$identity,'title'=>$brand.' '.$parts[2]]:null;
    }

    private function load(): void
    {
        if($this->orders!==null)return;
        $this->orders=[];
        $root=dirname(__DIR__);
        $map=$this->mapPath??$root.'/resources/inbox-question-order-models.json';
        $key=$this->keyPath??$root.'/storage/backup.key';
        if(!is_file($map)||!is_readable($map)||!is_readable($key))return;
        $bytes=file_get_contents($map,false,null,0,524289);
        if(!is_string($bytes)||strlen($bytes)>524288)return;
        $stored=json_decode($bytes,true,32);
        if(($stored['version']??null)!==1||!is_array($stored['orders']??null))return;
        $master=file_get_contents($key,false,null,0,33);
        if(!is_string($master)||strlen($master)!==32)return;
        $this->master=$master;
        $this->orders=$stored['orders'];
    }
}
