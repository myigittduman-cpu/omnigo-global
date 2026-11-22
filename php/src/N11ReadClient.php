<?php
declare(strict_types=1);
namespace Omnigo;

/** Only the official GET shipmentPackages endpoint is accessible. No marketplace writes. */
final class N11ReadClient
{
    public function __construct(private ?\Closure $transport=null) {}
    public function packages(N11Credentials $credentials,int $start,int $end,int $page,int $deadline): array
    {
        if($start<0||$end<=$start||$end-$start>14*86400000||$page<0||$page>1000||$deadline<=time())throw new SyncFailure(422);
        $query=['startDate'=>$start,'endDate'=>$end,'page'=>$page,'size'=>100,'orderByField'=>'false','orderByDirection'=>'ASC','sender'=>'ALL'];
        $url='https://api.n11.com/rest/delivery/v1/shipmentPackages?'.http_build_query($query);
        $headers=['Accept: application/json','appKey: '.$credentials->apiKey,'appSecret: '.$credentials->apiSecret];
        $reply=$this->transport?($this->transport)($url,$headers,$deadline):$this->request($url,$headers,$deadline);
        if(($reply['status']??0)!==200)throw new SyncFailure((int)($reply['status']??503),max(0,(int)($reply['retryAfter']??0)));
        try{$data=json_decode($reply['body']??'',true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){throw new SyncFailure(502);}
        if(!is_array($data)||!is_array($data['content']??null)||!array_is_list($data['content'])||count($data['content'])>100
            ||!is_int($data['totalPages']??null)||$data['totalPages']<0||$data['totalPages']>1000)throw new SyncFailure(422);
        if(isset($data['page'])&&(int)$data['page']!==$page)throw new SyncFailure(422);
        return $data;
    }
    private function request(string $url,array $headers,int $deadline): array
    {
        if(!function_exists('curl_init'))throw new SyncFailure(503);
        $curl=curl_init($url);$response='';$retryAfter=0;
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(20,$deadline-time())),
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>4194304)return 0;$response.=$chunk;return strlen($chunk);},
            CURLOPT_HEADERFUNCTION=>static function($handle,string $header)use(&$retryAfter):int{
                if(stripos($header,'Retry-After:')===0){$value=trim(substr($header,12));$retryAfter=ctype_digit($value)?min(604800,(int)$value):max(0,min(604800,(strtotime($value)?:time())-time()));}
                return strlen($header);
            }]);
        try{if(curl_exec($curl)===false)throw new SyncFailure(503,$retryAfter);return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$response,'retryAfter'=>$retryAfter];}
        finally{curl_close($curl);}
    }
}
