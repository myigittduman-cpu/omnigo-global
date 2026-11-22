<?php
declare(strict_types=1);
namespace Omnigo;

/** Catalog GETs only. Credentials and response bodies never enter error logs. */
final class InventoryCatalogClient
{
    public function page(string $provider, array $config, int $page, int $deadline): array
    {
        if($provider==='trendyol')return (new TrendyolCatalogReadClient())->page($config,$page,(int)($config['_inventoryPartition']??0),$config['_inventoryCursor']??null,$deadline);
        if ($page < 0 || $page > 10000 || empty($config['enabled'])) throw new SyncFailure(422);
        $headers=['Accept: application/json','Content-Type: application/json'];
        switch ($provider) {
            case 'n11':
                $url='https://api.n11.com/ms/product-query?'.http_build_query(['page'=>$page,'size'=>100]);
                $credentials=N11Credentials::fromArray($config);
                $headers[]='appkey: '.$credentials->apiKey; $headers[]='appsecret: '.$credentials->apiSecret; break;
            case 'hepsiburada':
                $credentials=HepsiburadaCredentials::fromArray($config);
                $url='https://listing-external.hepsiburada.com/listings/merchantid/'.rawurlencode($credentials->merchantId).'?'.http_build_query(['offset'=>$page*100,'limit'=>100]);
                $headers[]='Authorization: Basic '.base64_encode($credentials->username.':'.$credentials->serviceKey);
                $headers[]='User-Agent: '.$credentials->userAgent; break;
            case 'pazarama':
                $token=(new PazaramaAuthClient())->authenticate(PazaramaCredentials::fromArray($config),$deadline);
                $url='https://isortagimapi.pazarama.com/product/products?'.http_build_query(['Approved'=>'true','Page'=>$page+1,'Size'=>100]);
                $headers[]='Authorization: Bearer '.$token['accessToken']; break;
            case 'pttavm':
                $partition=(int)($config['_inventoryPartition']??0);
                $url='https://integration-api.pttavm.com/api/v1/products/search?'.http_build_query(['categoryId'=>0,'subCategoryId'=>0,'isActive'=>$partition<2?'true':'false','isInStock'=>$partition%2===0?'true':'false','merchantCategoryId'=>0,'searchPage'=>$page]);
                $headers[]='Api-Key: '.$config['apiKey']; $headers[]='Access-Token: '.$config['accessToken'];
                $headers[]='X-Correlation-Id: '.bin2hex(random_bytes(16)); break;
            case 'idefix':
                $url='https://merchantapi.idefix.com/pim/pool/'.rawurlencode($config['vendorId']).'/list?'.http_build_query(['page'=>$page+1,'limit'=>100]);
                $headers[]='X-API-KEY: '.base64_encode($config['apiKey'].':'.$config['apiSecret']); break;
            case 'koctas':
                $url='https://koctas.mirakl.net/api/offers?'.http_build_query(['offset'=>$page*100,'max'=>100]);
                $headers[]='Authorization: '.$config['apiKey']; break;
            default: throw new SyncFailure(422);
        }
        if ($deadline<=time()) throw new SyncFailure(503);
        $body=''; $retry=0; $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(20,$deadline-time())),CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>8388608)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HEADERFUNCTION=>static function($handle,$line)use(&$retry){if(preg_match('/^Retry-After:\s*(\d+)/i',$line,$m))$retry=min(3600,(int)$m[1]);return strlen($line);}
        ]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($ok===false||$status<200||$status>=300)throw new SyncFailure($status?:503,$retry);
        try{$data=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new SyncFailure(502);}
        if(!is_array($data))throw new SyncFailure(502);
        return $data;
    }
}
