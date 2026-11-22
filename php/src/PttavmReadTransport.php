<?php
declare(strict_types=1);
namespace Omnigo;

/** Bounded retries for interrupted read-only requests, never for HTTP or certificate errors. */
final class PttavmReadTransport
{
    public function __construct(private ?\Closure $attempt=null,private ?\Closure $pause=null){}
    public function get(string $url,array $headers,int $deadline): array
    {
        if(!str_starts_with($url,'https://integration-api.pttavm.com/api/v1/orders/search?')
            &&!preg_match('~^https://integration-api\.pttavm\.com/api/v1/orders/[A-Za-z0-9_-]{1,180}$~D',$url))throw new SyncFailure(422);
        for($attempt=0;$attempt<3;$attempt++){
            if($deadline<=time())throw new SyncFailure(503);
            $result=$this->attempt?($this->attempt)($url,$headers,$deadline):$this->request($url,$headers,$deadline);
            if(($result['error']??0)===0)return $result;
            $retryable=($result['status']??0)===0&&in_array($result['error'],[7,28,35,52,55,56],true);
            if(!$retryable||$attempt===2||$deadline-time()<2)throw new SyncFailure(503,(int)($result['retryAfter']??0));
            $delay=250000*(2**$attempt)+random_int(0,100000);
            if($this->pause)($this->pause)($delay);else usleep($delay);
        }
        throw new SyncFailure(503);
    }
    private function request(string $url,array $headers,int $deadline): array
    {
        if(!function_exists('curl_init'))throw new SyncFailure(503);
        $curl=curl_init($url);$body='';$retry=0;
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>min(8,max(1,$deadline-time())),CURLOPT_TIMEOUT=>max(1,min(25,$deadline-time())),
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>8388608)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HEADERFUNCTION=>static function($handle,string $header)use(&$retry):int{
                if(preg_match('/^Retry-After:\s*(.+)$/i',trim($header),$m))$retry=min(604800,is_numeric(trim($m[1]))?max(0,(int)ceil((float)$m[1])):max(0,(strtotime($m[1])?:time())-time()));
                return strlen($header);
            }]);
        try{curl_exec($curl);return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body,'retryAfter'=>$retry,'error'=>curl_errno($curl)];}
        finally{curl_close($curl);}
    }
}
