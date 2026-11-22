<?php
declare(strict_types=1);
namespace Omnigo;

final class MobilePushHttp
{
    public function __construct(private mixed $transport=null) {}
    public function post(string $url,array $headers,string $body,bool $http2=false): array
    {
        $host=parse_url($url,PHP_URL_HOST);
        if (!in_array($host,['oauth2.googleapis.com','fcm.googleapis.com','api.push.apple.com','api.sandbox.push.apple.com'],true)
            || parse_url($url,PHP_URL_SCHEME)!=='https') throw new \RuntimeException('Bildirim sunucusu geçersiz.');
        if($this->transport)return ($this->transport)($url,$headers,$body,$http2);
        if ($http2 && !(curl_version()['features'] & CURL_VERSION_HTTP2)) throw new \RuntimeException('APNs için HTTP/2 desteği gerekiyor.');
        $retry=0;$response='';$curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_PROXY=>'',
            CURLOPT_HTTP_VERSION=>$http2?CURL_HTTP_VERSION_2_0:CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$retry):int {
                if (stripos($line,'Retry-After:')===0) {
                    $value=trim(substr($line,12));$retry=ctype_digit($value)?(int)$value:max(0,(strtotime($value)?:time())-time());
                } return strlen($line);
            },CURLOPT_WRITEFUNCTION=>static function($ch,string $part)use(&$response):int {
                if(strlen($response)+strlen($part)>65536)return 0;$response.=$part;return strlen($part);
            }]);
        try {
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($ok===false) throw new \RuntimeException('Bildirim hizmetine bağlanılamadı.');
            return ['status'=>$status,'body'=>json_decode($response,true)?:[],'retryAfter'=>min(86400,$retry)];
        } finally {curl_close($curl);}
    }
}
