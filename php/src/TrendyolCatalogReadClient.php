<?php
declare(strict_types=1);
namespace Omnigo;

/** Fixed Product V2 GET endpoints; no marketplace write or credential mutation. */
final class TrendyolCatalogReadClient
{
    public function __construct(private ?\Closure $transport=null){}
    public function page(array $config,int $page,int $partition,?string $cursor,int $deadline):array
    {
        $seller=(string)($config['supplierId']??'');
        if(empty($config['enabled'])||!preg_match('/^[1-9][0-9]{0,15}$/D',$seller)||!in_array($partition,[0,1],true)||$page<0||$page>10000||$deadline<=time()
            ||!is_string($config['apiKey']??null)||$config['apiKey']===''||!is_string($config['apiSecret']??null)||$config['apiSecret']==='')throw new SyncFailure(422);
        $query=['size'=>100,'supplierId'=>$seller];
        if($partition===0)$query['orderByDirection']='ASC';
        if($page<100)$query['page']=$page;
        else{
            if(!is_string($cursor)||$cursor===''||strlen($cursor)>8192)throw new SyncFailure(422);
            $query['nextPageToken']=$cursor;
        }
        $url='https://apigw.trendyol.com/integration/product/sellers/'.$seller.'/products/'.($partition===0?'approved':'unapproved').'?'.http_build_query($query);
        $headers=['Accept: application/json','User-Agent: '.$seller.' - OmnigoGlobal','Authorization: Basic '.base64_encode($config['apiKey'].':'.$config['apiSecret'])];
        $reply=$this->transport?($this->transport)($url,$headers,$deadline):$this->request($url,$headers,$deadline);
        if(($reply['status']??0)!==200)throw new SyncFailure((int)($reply['status']??503),(int)($reply['retryAfter']??0));
        try{$data=json_decode($reply['body']??'',true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){throw new SyncFailure(502);}
        if(!is_array($data)||!is_array($data['content']??null)||!array_is_list($data['content']))throw new SyncFailure(422);
        foreach($data['content'] as $content){
            if(!is_array($content))throw new SyncFailure(422);
            $variants=$partition===0?($content['variants']??null):[$content];
            if(!is_array($variants)||!array_is_list($variants)||!$variants)throw new SyncFailure(422);
            foreach($variants as $variant){
                $owner=is_array($variant)?($variant['supplierId']??null):null;
                if((!is_int($owner)&&!is_string($owner))||!preg_match('/^[1-9][0-9]{0,15}$/D',(string)$owner)||(string)$owner!==$seller)throw new SyncFailure(403);
            }
        }
        // Reject malformed page metadata before exposing any of this page to the writer.
        $normalized=TrendyolCatalogPage::normalize($data,$page,$partition);
        if($page>=100&&$normalized['next']&&($normalized['cursor']??null)===$cursor)throw new SyncFailure(409);
        return $data;
    }
    private function request(string $url,array $headers,int $deadline):array
    {
        if(!function_exists('curl_init'))throw new SyncFailure(503);$curl=curl_init($url);$body='';$retry=0;
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(20,$deadline-time())),
            CURLOPT_WRITEFUNCTION=>static function($h,string $part)use(&$body):int{if(strlen($body)+strlen($part)>16777216)return 0;$body.=$part;return strlen($part);},
            CURLOPT_HEADERFUNCTION=>static function($h,string $header)use(&$retry):int{if(preg_match('/^Retry-After:\s*(.+)$/i',trim($header),$m)){$value=trim($m[1]);$retry=min(3600,is_numeric($value)?max(0,(int)ceil((float)$value)):max(0,(strtotime($value)?:time())-time()));}return strlen($header);}]);
        try{if(curl_exec($curl)===false)throw new SyncFailure(503,$retry);return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body,'retryAfter'=>$retry];}
        finally{curl_close($curl);}
    }
}
