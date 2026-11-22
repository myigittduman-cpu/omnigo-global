<?php
declare(strict_types=1);
namespace Omnigo;

/** Official cursor endpoint, GET only. Credentials are never included in errors or URLs. */
final class TrendyolReadClient
{
    public function __construct(private ?\Closure $transport=null){}
    public function orders(array $config,int $from,int $to,?string $cursor,int $deadline): array
    {
        $seller=(string)($config['supplierId']??'');
        if(!preg_match('/^[1-9][0-9]{0,15}$/D',$seller)||$from<0||$to<$from||$to-$from>14*86400000||$deadline<=time())throw new SyncFailure(422);
        $query=['lastModifiedStartDate'=>$from,'lastModifiedEndDate'=>$to,'size'=>200];
        if($cursor!==null){if(strlen($cursor)>8192||$cursor==='')throw new SyncFailure(422);$query['nextCursor']=$cursor;}
        $url='https://apigw.trendyol.com/integration/order/sellers/'.$seller.'/orders/stream?'.http_build_query($query);
        $headers=['Accept: application/json','User-Agent: '.$seller.' - OmnigoGlobal','Authorization: Basic '.base64_encode($config['apiKey'].':'.$config['apiSecret'])];
        $response=$this->transport?($this->transport)($url,$headers,$deadline):$this->request($url,$headers,$deadline);
        if(($response['status']??0)!==200)throw new SyncFailure((int)($response['status']??503),(int)($response['retryAfter']??0));
        try{$body=json_decode($response['body']??'',true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){throw new SyncFailure(502);}
        if(!is_array($body['content']??null)||!array_is_list($body['content'])||count($body['content'])>200||!is_bool($body['hasMore']??null))throw new SyncFailure(422);
        if($body['hasMore']&&(!is_string($body['nextCursor']??null)||$body['nextCursor']===''||strlen($body['nextCursor'])>8192||$body['nextCursor']===$cursor))throw new SyncFailure(422);
        foreach($body['content'] as $package){
            if(!is_array($package))throw new SyncFailure(422);
            TrendyolPackageOwner::verify($package,$seller);
        }
        return ['items'=>$body['content'],'complete'=>!$body['hasMore'],'cursor'=>$body['hasMore']?$body['nextCursor']:null];
    }
    private function request(string $url,array $headers,int $deadline): array
    {
        if(!function_exists('curl_init'))throw new SyncFailure(503);
        $curl=curl_init($url);$body='';$retry=0;
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(25,$deadline-time())),
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>16777216)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HEADERFUNCTION=>static function($handle,string $header)use(&$retry):int{if(preg_match('/^Retry-After:\s*(.+)$/i',trim($header),$m))$retry=min(604800,is_numeric(trim($m[1]))?max(0,(int)ceil((float)$m[1])):max(0,(strtotime($m[1])?:time())-time()));return strlen($header);}]);
        try{if(curl_exec($curl)===false)throw new SyncFailure(503,$retry);return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body,'retryAfter'=>$retry];}finally{curl_close($curl);}
    }
}
