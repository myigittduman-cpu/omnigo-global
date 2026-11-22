<?php
declare(strict_types=1);
namespace Omnigo;

/** Bounded, read-only SOAP/GET requests to fixed marketplace hosts. */
final class QuestionReadTransport
{
    public function __construct(private ?\Closure $transport=null){}
    public function read(string $url,array $headers,int $deadline,?string $xml=null): string
    {
        if($deadline<=time())throw new SyncFailure(503);
        if($this->transport)$response=($this->transport)($url,$headers,$deadline,$xml);
        else{
            $curl=curl_init($url);$body='';$retry=0;
            curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(25,$deadline-time())),
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>4194304)return 0;$body.=$chunk;return strlen($chunk);},
                CURLOPT_HEADERFUNCTION=>static function($handle,string $header)use(&$retry):int{if(preg_match('/^Retry-After:\s*(.+)$/i',trim($header),$m))$retry=min(604800,is_numeric(trim($m[1]))?max(0,(int)ceil((float)$m[1])):max(0,(strtotime($m[1])?:time())-time()));return strlen($header);}]);
            if($xml!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$xml]);
            try{if(curl_exec($curl)===false)throw new SyncFailure(503,$retry);$response=['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body,'retryAfter'=>$retry];}finally{curl_close($curl);}
        }
        if(($response['status']??0)!==200)throw new SyncFailure((int)($response['status']??503),(int)($response['retryAfter']??0));
        if(!is_string($response['body']??null)||strlen($response['body'])>4194304)throw new SyncFailure(502);
        return $response['body'];
    }
}
