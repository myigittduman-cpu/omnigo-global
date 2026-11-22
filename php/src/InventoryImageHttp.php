<?php
declare(strict_types=1);
namespace Omnigo;

/** Unauthenticated HTTPS GETs; DNS is validated and pinned on every redirect. */
final class InventoryImageHttp
{
    public static function publicAddress(string $address):bool
    {
        // The transport resolves A records only. Exclude special-use IPv4 ranges
        // that PHP's private/reserved flags do not consistently cover.
        if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)return false;
        $value=ip2long($address);
        foreach(['100.64.0.0/10','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $range){[$network,$bits]=explode('/',$range);$mask=-1<<(32-(int)$bits);if(($value&$mask)===(ip2long($network)&$mask))return false;}
        return true;
    }
    public function get(string $url,int $deadline,int $limit=5242880):string
    {
        for($redirect=0;$redirect<4;$redirect++){
            if(!InventoryImageCandidates::valid($url)||time()+1>=$deadline)throw new \DomainException('Görsel kaynağı henüz doğrulanamadı.');
            $host=strtolower((string)parse_url($url,PHP_URL_HOST));$addresses=gethostbynamel($host)?:[];
            if(!$addresses||array_filter($addresses,static fn($ip)=>!self::publicAddress($ip)))throw new \DomainException('Görsel kaynağına güvenli bağlantı kurulamadı.');
            $body='';$location='';$retry=0;$curl=curl_init($url);
            curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$addresses[0]],CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>max(1,min(12,$deadline-time())),CURLOPT_USERAGENT=>'Omnigo-Product-Images/1.0',CURLOPT_HTTPHEADER=>['Accept: image/png,image/jpeg,image/webp,text/html;q=0.8'],
                CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$body,$limit){if(strlen($body)+strlen($chunk)>$limit)return 0;$body.=$chunk;return strlen($chunk);},
                CURLOPT_HEADERFUNCTION=>static function($handle,$line)use(&$location,&$retry){if(preg_match('/^Location:\s*(.*?)\s*$/i',$line,$m))$location=$m[1];if(preg_match('/^Retry-After:\s*(\d+)/i',$line,$m))$retry=min(86400,(int)$m[1]);return strlen($line);}
            ]);
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
            if($ok!==false&&in_array($status,[301,302,303,307,308],true)){
                if(str_starts_with($location,'/'))$location='https://'.$host.$location;
                $url=$location;continue;
            }
            if($ok===false||$status<200||$status>=300)throw new SyncFailure($status?:503,$retry);
            return $body;
        }
        throw new \DomainException('Görsel kaynağı yönlendirmesi tamamlanamadı.');
    }
}
