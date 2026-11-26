<?php
declare(strict_types=1);
namespace Omnigo;

/** Distinguishes transient read errors from a write whose outcome is unknown. */
final class StockGatewayHttp
{
    public function __construct(private ?\Closure $transport=null){}

    public function request(string $method,string $url,array $headers,?array $payload,int $deadline,bool $writes=false):array
    {
        if($deadline<=time())throw new MarketplaceStockFailure(retryable:true);
        if(!in_array(parse_url($url,PHP_URL_HOST),['api.n11.com','apigw.trendyol.com','isortagimapi.pazarama.com'],true)||!str_starts_with($url,'https://'))throw new \InvalidArgumentException('Pazaryeri adresi doğrulanamadı.');
        $headers[]='Accept: application/json';
        if($payload!==null)$headers[]='Content-Type: application/json';
        $body=$payload===null?null:json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $reply=$this->transport
            ?($this->transport)($method,$url,$headers,$body,$deadline)
            :$this->send($method,$url,$headers,$body,$deadline);
        $status=$reply['status']??0;
        if(!is_int($status)||$status<200||$status>=300){
            $transient=$status===0||$status===408||$status>=500;
            throw new MarketplaceStockFailure($writes&&$transient,$status===429||(!$writes&&$transient),max(5,min(3600,(int)($reply['retryAfter']??30))));
        }
        try{$data=json_decode($reply['body']??'',true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(\JsonException){throw new MarketplaceStockFailure(uncertain:$writes);}
        if(!is_array($data))throw new MarketplaceStockFailure(uncertain:$writes);
        return $data;
    }

    private function send(string $method,string $url,array $headers,?string $body,int $deadline):array
    {
        $result='';$retryAfter=30;$curl=curl_init($url);
        curl_setopt_array($curl,[
            CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>6,
            CURLOPT_TIMEOUT=>max(1,min(20,$deadline-time())),CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $part)use(&$result):int{
                if(strlen($result)+strlen($part)>8388608)return 0;
                $result.=$part;return strlen($part);
            },
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$retryAfter):int{
                if(preg_match('/^Retry-After:\s*(.+)$/i',trim($line),$matches)){
                    $value=trim($matches[1]);
                    $retryAfter=ctype_digit($value)?(int)$value:max(0,(strtotime($value)?:time())-time());
                }
                return strlen($line);
            },
        ]);
        if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
        try{
            $ok=curl_exec($curl);
            return ['status'=>$ok===false?0:(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'retryAfter'=>$retryAfter,'body'=>$result];
        }finally{curl_close($curl);}
    }
}
