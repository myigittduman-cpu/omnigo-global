<?php
declare(strict_types=1);
namespace Omnigo;

/** A fixed host and a GET-only allowlist prevent accidental marketplace writes and SSRF. */
final class KoctasHttpClient
{
    public function __construct(private string $apiKey) {}
    public function get(string $path,array $query,int $deadline): array
    {
        if (!in_array($path,['/api/account','/api/orders','/api/returns'],true)) throw new \LogicException('Okuma uç noktası izinli değil.');
        if (!preg_match('/^[a-f0-9-]{36}$/iD',$this->apiKey)) throw new SyncFailure(401);
        if (!function_exists('curl_init') || $deadline<=time()) throw new SyncFailure(503);
        $url='https://koctas.mirakl.net'.$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $curl=curl_init($url); $body=''; $retryAfter=0;
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>min(25,$deadline-time()),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: '.$this->apiKey,'User-Agent: Omnigo-ReadOnlyPortal/0.6.0'],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use(&$body): int { if (strlen($body)+strlen($chunk)>4194304) return 0; $body.=$chunk; return strlen($chunk); },
            CURLOPT_HEADERFUNCTION=>static function($handle,string $header) use(&$retryAfter): int { if (stripos($header,'Retry-After:')===0) { $value=trim(substr($header,12)); $retryAfter=ctype_digit($value)?(int)$value:max(0,(strtotime($value)?:time())-time()); } return strlen($header); }
        ]);
        try {
            $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($ok===false || $status!==200) throw new SyncFailure($status?:503,$retryAfter);
            $result=json_decode($body,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($result)) throw new SyncFailure(502);
            return $result;
        } catch (\JsonException) { throw new SyncFailure(502); }
        finally { curl_close($curl); }
    }
}
