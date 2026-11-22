<?php
declare(strict_types=1);
namespace Omnigo;

/** Pazarama uses POST for this read operation; no stock/order write endpoints exist here. */
final class PazaramaReadClient
{
    public function __construct(private ?\Closure $transport=null) {}

    public function sample(string $token,string $start,string $end,int $deadline): array
    {
        return $this->orders($token,$start,$end,1,$deadline);
    }

    public function orders(string $token,string $start,string $end,int $page,int $deadline): array
    {
        if (!preg_match('/^[\x21-\x7e]{1,16384}$/D',$token) || $deadline<=time()) throw new SyncFailure(503);
        foreach ([$start,$end] as $date) if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$date)) throw new SyncFailure(422);
        if ($start >= $end || strtotime($end)-strtotime($start)>31*86400) throw new SyncFailure(422);
        $url='https://isortagimapi.pazarama.com/order/getOrdersForApi';
        if($page<1||$page>10000)throw new SyncFailure(422);
        $body=json_encode(['pageSize'=>20,'pageNumber'=>$page,'startDate'=>$start,'endDate'=>$end],JSON_THROW_ON_ERROR);
        $headers=['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$token];
        $reply=$this->transport?($this->transport)($url,$headers,$body,$deadline):$this->request($url,$headers,$body,$deadline);
        if (($reply['status']??0)!==200) throw new SyncFailure((int)($reply['status']??503),max(0,(int)($reply['retryAfter']??0)));
        try {$data=json_decode($reply['body']??'',true,64,JSON_THROW_ON_ERROR);}
        catch (\JsonException) {throw new SyncFailure(502);}
        if (!is_array($data) || ($data['success']??null)!==true || !is_array($data['data']??null)
            || !array_is_list($data['data']) || count($data['data'])>20) throw new SyncFailure(422);
        return $data['data'];
    }

    public function questions(string $token,int $page,int $deadline): array
    {
        if($page<1||$page>10000||$deadline<=time()||!preg_match('/^[\x21-\x7e]{1,16384}$/D',$token))throw new SyncFailure(422);
        $url='https://isortagimapi.pazarama.com/QuestionAnswer/getApprovalAnswersByMerchantSearch';
        $body=json_encode(['barcode'=>null,'topicId'=>null,'questionStartDate'=>null,'questionEndDate'=>null,'questionStatus'=>null,'pageIndex'=>$page,'pageSize'=>100],JSON_THROW_ON_ERROR);
        $headers=['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$token];
        $reply=$this->transport?($this->transport)($url,$headers,$body,$deadline):$this->request($url,$headers,$body,$deadline);
        if(($reply['status']??0)!==200)throw new SyncFailure((int)($reply['status']??503),max(0,(int)($reply['retryAfter']??0)));
        try{$result=json_decode($reply['body']??'',true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new SyncFailure(502);}
        $data=$result['data']??null;$paging=$data['pageResponse']??null;
        if(($result['success']??false)!==true||!is_array($data)||!is_array($data['approvalAnswersByMerchantSearchs']??null)||!array_is_list($data['approvalAnswersByMerchantSearchs'])||count($data['approvalAnswersByMerchantSearchs'])>100||!is_array($paging)||($paging['pageIndex']??null)!==$page||!is_int($paging['totalPages']??null)||$paging['totalPages']<0||$paging['totalPages']>10000)throw new SyncFailure(422);
        if(!$data['approvalAnswersByMerchantSearchs']&&$page<$paging['totalPages'])throw new SyncFailure(422);
        return $data;
    }

    private function request(string $url,array $headers,string $body,int $deadline): array
    {
        if (!function_exists('curl_init')) throw new SyncFailure(503);
        $curl=curl_init($url);$response='';$retryAfter=0;
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(20,$deadline-time())),
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use(&$response): int {
                if (strlen($response)+strlen($chunk)>2097152) return 0;
                $response.=$chunk;return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION=>static function($handle,string $header) use(&$retryAfter): int {
                if (stripos($header,'Retry-After:')===0) {
                    $value=trim(substr($header,12));
                    $retryAfter=ctype_digit($value)?min(604800,(int)$value):max(0,min(604800,(strtotime($value)?:time())-time()));
                }
                return strlen($header);
            },
        ]);
        try {
            if (curl_exec($curl)===false) throw new SyncFailure(503,$retryAfter);
            return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$response,'retryAfter'=>$retryAfter];
        } finally {curl_close($curl);}
    }
}
