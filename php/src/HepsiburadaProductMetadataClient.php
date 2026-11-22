<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only MPOP catalogue, independent of the authoritative listing stock feed. */
final class HepsiburadaProductMetadataClient
{
    public function page(array $config,int $page,int $deadline):array
    {
        if($page<0||$page>10000||empty($config['enabled'])||time()+2>=$deadline)throw new SyncFailure(503);
        $credentials=HepsiburadaCredentials::fromArray($config);
        $host=($config['environment']??'production')==='sandbox'?'mpop-sit.hepsiburada.com':'mpop.hepsiburada.com';
        $url='https://'.$host.'/product/api/products/all-products-of-merchant/'.rawurlencode($credentials->merchantId).'?'.http_build_query(['page'=>$page,'size'=>100]);
        $body='';$retry=0;$handle=curl_init($url);
        curl_setopt_array($handle,[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>max(1,min(12,$deadline-time())),CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Basic '.base64_encode($credentials->username.':'.$credentials->serviceKey),'User-Agent: '.$credentials->userAgent],CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>8388608)return 0;$body.=$chunk;return strlen($chunk);},CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$retry){if(preg_match('/^Retry-After:\s*(\d+)/i',$line,$m))$retry=min(3600,(int)$m[1]);return strlen($line);}]);
        $ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
        if($ok===false||$status<200||$status>=300)throw new SyncFailure($status?:503,$retry);
        try{$data=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new SyncFailure(502);}
        if(!is_array($data))throw new SyncFailure(502);
        return $data;
    }
}
