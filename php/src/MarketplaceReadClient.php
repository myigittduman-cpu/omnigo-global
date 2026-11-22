<?php
declare(strict_types=1);
namespace Omnigo;
final class MarketplaceReadClient
{
    public function __construct(private ?\Closure $transport=null){}
    public function pttavmDetail(array $config,string $number,int $deadline): array
    {
        if($deadline<=time()||!preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$number))throw new SyncFailure(422);
        $url='https://integration-api.pttavm.com/api/v1/orders/'.$number;
        $headers=['Api-Key: '.$config['apiKey'],'Access-Token: '.$config['accessToken'],'X-Correlation-Id: '.bin2hex(random_bytes(16)),'Accept: application/json','Content-Type: application/json'];
        $response=$this->transport?($this->transport)($url,$headers,$deadline):(new PttavmReadTransport())->get($url,$headers,$deadline);
        if(($response['status']??0)!==200)throw new SyncFailure((int)($response['status']??503),(int)($response['retryAfter']??0));
        try{$rows=json_decode($response['body']??'',true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){throw new SyncFailure(502);}
        if(!is_array($rows)||!array_is_list($rows)||count($rows)!==1||($rows[0]['siparisNo']??null)!==$number)throw new SyncFailure(422);
        if(isset($config['storeName'])&&mb_strtolower(trim((string)($rows[0]['tedarikciFirmaAdi']??'')))!==mb_strtolower(trim($config['storeName'])))throw new SyncFailure(403);
        return $rows[0];
    }
    public function orders(string $provider,array $config,string $from,string $to,int $page,int $deadline): array
    {
        if($deadline<=time()||$page<1||$page>1000)throw new SyncFailure(503);
        if($provider==='idefix'){
            $query=['vendor'=>$config['vendorId'],'startDate'=>str_replace('-','/',$from),'endDate'=>str_replace('-','/',$to),'page'=>$page,'limit'=>20,'sortByField'=>'id','sortDirection'=>'asc'];
            $url='https://merchantapi.idefix.com/oms/'.rawurlencode($config['vendorId']).'/list?'.http_build_query($query);
            $headers=['X-API-KEY: '.base64_encode($config['apiKey'].':'.$config['apiSecret'])];
        }elseif($provider==='pttavm'){
            $zone=new \DateTimeZone('Europe/Istanbul');
            try{$start=new \DateTimeImmutable($from,$zone);$end=new \DateTimeImmutable($to,$zone);}catch(\Throwable){throw new SyncFailure(422);}
            if($page!==1||$end<$start||$end->getTimestamp()-$start->getTimestamp()>40*86400)throw new SyncFailure(422);
            $url='https://integration-api.pttavm.com/api/v1/orders/search?'.http_build_query(['startDate'=>str_replace(' ','T',$from),'endDate'=>str_replace(' ','T',$to),'isActiveOrders'=>'false']);
            $headers=['Api-Key: '.$config['apiKey'],'Access-Token: '.$config['accessToken'],'X-Correlation-Id: '.bin2hex(random_bytes(16))];
        }else throw new SyncFailure(422);
        $headers[]='Accept: application/json';$headers[]='Content-Type: application/json';
        $response=$this->transport?($this->transport)($url,$headers,$deadline):($provider==='pttavm'?(new PttavmReadTransport())->get($url,$headers,$deadline):$this->request($url,$headers,$deadline));
        if(($response['status']??0)!==200)throw new SyncFailure((int)($response['status']??503),(int)($response['retryAfter']??0));
        try{$value=json_decode($response['body']??'',true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){throw new SyncFailure(502);}
        if($provider==='idefix'){
            if(!is_array($value['items']??null)||!array_is_list($value['items'])||count($value['items'])>20||!is_int($value['pageCount']??null)||$value['pageCount']<0||$value['pageCount']>1000||(int)($value['currentPage']??0)!==$page)throw new SyncFailure(422);
            foreach($value['items'] as $item)if((string)($item['vendorId']??'')!==$config['vendorId'])throw new SyncFailure(422);
            return ['items'=>$value['items'],'complete'=>$page>=max(1,$value['pageCount'])];
        }
        if(!is_array($value)||!array_is_list($value)||count($value)>=5000)throw new SyncFailure(422);
        return ['items'=>$value,'complete'=>true];
    }
    private function request(string $url,array $headers,int $deadline): array
    {
        if(!function_exists('curl_init'))throw new SyncFailure(503);
        $curl=curl_init($url);$body='';$retry=0;
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(25,$deadline-time())),
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>8388608)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HEADERFUNCTION=>static function($handle,string $header)use(&$retry):int{if(preg_match('/^Retry-After:\s*(.+)$/i',trim($header),$m))$retry=min(604800,is_numeric(trim($m[1]))?max(0,(int)ceil((float)$m[1])):max(0,(strtotime($m[1])?:time())-time()));return strlen($header);}]);
        try{if(curl_exec($curl)===false)throw new SyncFailure(503,$retry);return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body,'retryAfter'=>$retry];}finally{curl_close($curl);}
    }
}
