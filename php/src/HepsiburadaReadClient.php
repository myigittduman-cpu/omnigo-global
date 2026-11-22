<?php
declare(strict_types=1);
namespace Omnigo;

/** GET-only order API; fixed origins, no stock, price or fulfilment mutations. */
final class HepsiburadaReadClient
{
    public function __construct(private ?\Closure $transport=null, private HepsiburadaEnvironment $environment=HepsiburadaEnvironment::Production) {}
    public function openOrders(HepsiburadaCredentials $credentials,int $deadline): array
    {
        return $this->page($credentials,'open',0,$deadline);
    }
    public function page(HepsiburadaCredentials $credentials,string $feed,int $offset,int $deadline): array
    {
        $route=match($feed){'open'=>'/orders/merchantid/','packages','shipped','delivered','undelivered'=>'/packages/merchantid/','cancelled'=>'/orders/merchantid/',default=>throw new SyncFailure(422)};
        if($offset<0||$offset>1000000)throw new SyncFailure(422);
        $suffix=in_array($feed,['cancelled','shipped','delivered','undelivered'],true)?'/'.$feed:'';
        // Ready packages default to a 24-hour window even with pagination. Keep
        // a bounded 70-day window so startup/recovery covers the 60-day reports.
        // Other feeds have their own API retention rules; do not alter them.
        $query=($feed==='packages'?'timespan=1680&':'').'offset='.$offset.'&limit=10';
        $data=$this->get($credentials,$route.$credentials->merchantId.$suffix.'?'.$query,$deadline,listPage:true);
        if(is_array($data)&&array_is_list($data)){
            if(count($data)>10)throw new SyncFailure(422);
            return ['items'=>$data,'totalCount'=>null,'hasMore'=>count($data)===10];
        }
        if(array_key_exists('items',$data)&&$data['items']===null){
            $total=$data['totalCount']??null;
            if(!is_int($total)||$total<0)throw new SyncFailure(422);
            if(array_key_exists('offset',$data)&&$data['offset']!==$offset)throw new SyncFailure(422);
            // A rolling delivery feed can shrink between runs. Accept its
            // explicit empty tail only when the metadata confirms this page.
            if($total>0){
                if(($data['offset']??null)!==$offset||($data['limit']??null)!==10
                    ||($data['pageCount']??null)!==intdiv($total-1,10)+1)throw new SyncFailure(422);
                if($offset<$total)throw new SyncFailure(502);
            }
            $data['items']=[];
        }
        if(!is_array($data)||!is_array($data['items']??null)||!array_is_list($data['items'])||count($data['items'])>10
            ||!is_int($data['totalCount']??null)||$data['totalCount']<count($data['items']))throw new SyncFailure(422);
        if(!$data['items']&&$offset<$data['totalCount'])throw new SyncFailure(502);
        return [...$data,'hasMore'=>$offset+count($data['items'])<$data['totalCount']];
    }
    public function order(HepsiburadaCredentials $credentials,string $number,int $deadline): array
    {
        if(!preg_match('/^[0-9]{1,30}$/D',$number))throw new SyncFailure(422);
        return $this->get($credentials,'/orders/merchantid/'.$credentials->merchantId.'/ordernumber/'.$number,$deadline);
    }
    public function packageShipping(HepsiburadaCredentials $credentials,string $number,int $deadline):array
    {
        if(!preg_match('/^[0-9]{1,30}$/D',$number))throw new SyncFailure(422);
        $data=$this->get($credentials,'/packages/merchantid/'.$credentials->merchantId.'/packagenumber/'.$number,$deadline);
        return array_is_list($data)?$data:[$data];
    }
    public function claims(HepsiburadaCredentials $credentials,string $status,int $offset,string $from,string $through,int $deadline):array
    {
        if(!in_array($status,HepsiburadaClaims::STATUSES,true)||$offset<0||$offset>1000000)throw new SyncFailure(422);
        foreach([$from,$through] as $date)if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D',$date))throw new SyncFailure(422);
        $query=http_build_query(['offset'=>$offset,'limit'=>10,'beginDate'=>$from,'endDate'=>$through]);
        $items=$this->get($credentials,'/claims/merchantId/'.$credentials->merchantId.'/status/'.$status.'?'.$query,$deadline,true);
        if(!array_is_list($items)||count($items)>10)throw new SyncFailure(422);
        return ['items'=>$items,'hasMore'=>count($items)===10];
    }
    private function get(HepsiburadaCredentials $credentials,string $path,int $deadline,bool $expectList=false,bool $listPage=false): array
    {
        if($deadline<=time())throw new SyncFailure(503);
        $url=$this->environment->orderOrigin().$path;
        $headers=['Accept: application/json','Authorization: Basic '.base64_encode($credentials->username.':'.$credentials->serviceKey),'User-Agent: '.$credentials->userAgent];
        $response=$this->transport?($this->transport)($url,$headers,$deadline):$this->request($url,$headers,$deadline);
        if(($response['status']??0)!==200)throw new SyncFailure((int)($response['status']??503),(int)($response['retryAfter']??0));
        if($expectList&&!str_starts_with(ltrim($response['body']??''),'['))throw new SyncFailure(422);
        try{$data=json_decode($response['body']??'',true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){throw new SyncFailure(502);}
        // Associative decoding collapses both {} and [] to []. An empty
        // object is not evidence that the provider has finished a list.
        if(!is_array($data)||($listPage&&array_is_list($data)&&!str_starts_with(ltrim($response['body']),'[')))throw new SyncFailure(422);
        return $data;
    }
    private function request(string $url,array $headers,int $deadline): array
    {
        if(!function_exists('curl_init'))throw new SyncFailure(503);
        $curl=curl_init($url);$body='';$retryAfter=0;
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(20,$deadline-time())),
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>4194304)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HEADERFUNCTION=>static function($handle,string $header)use(&$retryAfter):int{
                if(preg_match('/^(Retry-After|X-RateLimit-Reset):\s*(.+)$/i',trim($header),$match)){
                    $value=trim($match[2]);$seconds=ctype_digit($value)?(int)$value:max(0,(strtotime($value)?:time())-time());
                    $retryAfter=max($retryAfter,min(604800,$seconds));
                }return strlen($header);
            }]);
        try{if(curl_exec($curl)===false)throw new SyncFailure(503,$retryAfter);return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body,'retryAfter'=>$retryAfter];}
        finally{curl_close($curl);}
    }
}
