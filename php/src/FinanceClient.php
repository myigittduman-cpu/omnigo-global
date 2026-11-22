<?php
declare(strict_types=1);
namespace Omnigo;

/** Separate read-only finance endpoints; credentials never enter a browser. */
final class FinanceClient
{
    public function __construct(private ?\Closure $transport=null) {}
    public function hepsiburada(HepsiburadaCredentials $credentials,string $from,string $to,int $offset,int $deadline): array
    {
        if($offset<0||$offset>10000)throw new SyncFailure(422);
        $query=['Offset'=>$offset,'Limit'=>100,'DueDateStart'=>$from,'DueDateEnd'=>$to];
        $data=$this->request('GET','https://mpfinance-external.hepsiburada.com/transactions/merchantid/'.rawurlencode($credentials->merchantId).'?'.http_build_query($query),['Authorization: Basic '.base64_encode($credentials->username.':'.$credentials->serviceKey),'User-Agent: '.$credentials->userAgent],null,$deadline);
        $rows=$data['items']??null;$count=$data['count']??null;
        if(!is_int($count)||$count<0||$count>10000||!is_array($rows)||!array_is_list($rows)||count($rows)>100||$offset+count($rows)>$count||(!$rows&&$offset<$count))throw new SyncFailure(422);
        $entries=[];foreach($rows as $raw){
            if(strtolower($raw['merchantId']??'')!==$credentials->merchantId)throw new SyncFailure(403);
            $entry=FinanceMapper::hepsiburada($raw);
            if($entry===null)continue;
            if($entry['transactionDate']<$from||$entry['transactionDate']>$to)throw new SyncFailure(422);
            $entries[]=$entry;
        }
        return ['entries'=>$entries,'count'=>$count,'cursor'=>$offset+count($rows)<$count?$offset+count($rows):null];
    }
    public function pazarama(string $token,string $from,string $to,int $deadline): array
    {
        $data=$this->pazaramaAgreement($token,$from,$to,$deadline);
        return ['entries'=>array_map([FinanceMapper::class,'pazarama'],$data['transactionList']),'cursor'=>null];
    }
    public function pazaramaCommission(string $token,string $from,string $to,int $deadline): array
    {
        return PazaramaCommissionWindow::read($this->pazaramaAgreement($token,$from,$to,$deadline),$from,$to);
    }
    public function pazaramaUpcoming(string $token,string $from,string $to,int $deadline): array
    {
        $data = $this->pazaramaAgreement($token,$from,$to,$deadline,byAllowance:true);
        return PazaramaUpcomingWindow::read($data,$from,$to);
    }
    public function pazaramaOrder(string $token,string $number,int $deadline): array
    {
        if(!preg_match('/^[1-9][0-9]{0,15}$/D',$number))throw new SyncFailure(422);
        $data=$this->request('POST','https://isortagimapi.pazarama.com/order/paymentAgreement',
            ['Authorization: Bearer '.$token],['startDate'=>null,'endDate'=>null,'allowanceStartDate'=>null,
                'allowanceEndDate'=>null,'orderId'=>(int)$number],$deadline);
        $rows=$data['data']['transactionList']??null;
        if(($data['success']??false)!==true||!is_array($rows)||!array_is_list($rows)||count($rows)>10000)throw new SyncFailure(422);
        // A gateway ignoring the order filter must not publish an apparently complete result.
        foreach($rows as $row)if(!is_array($row)||(string)($row['orderId']??'')!==$number)throw new SyncFailure(422);
        return $rows;
    }
    private function pazaramaAgreement(string $token,string $from,string $to,int $deadline,bool $byAllowance=false): array
    {
        $start = $from.'T00:00:00.000';
        $end = $to.'T23:59:59.999';
        $params = [
            'startDate'=>$byAllowance ? null : $start, 'endDate'=>$byAllowance ? null : $end,
            'allowanceStartDate'=>$byAllowance ? $start : null, 'allowanceEndDate'=>$byAllowance ? $end : null, 'orderId'=>null,
        ];
        $data = $this->request('POST','https://isortagimapi.pazarama.com/order/paymentAgreement',
            ['Authorization: Bearer '.$token],$params,$deadline);
        $rows = $data['data']['transactionList'] ?? null;
        if (($data['success'] ?? false) !== true || !is_array($rows) || !array_is_list($rows) || count($rows)>10000) {
            throw new SyncFailure(422);
        }
        return $data['data'];
    }
    public function koctas(array $config,string $from,?string $cursor,int $deadline): array
    {
        if(!preg_match('/^[a-f0-9-]{36}$/iD',$config['apiKey']??'')||($config['shopId']??0)<1)throw new SyncFailure(401);
        $query=['shop_id'=>$config['shopId'],'limit'=>100,'start_date'=>$from.'T00:00:00+03:00'];if($cursor!==null)$query['page_token']=$cursor;
        $data=$this->request('GET','https://koctas.mirakl.net/api/seller-billing-cycles?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),['Authorization: '.$config['apiKey']],null,$deadline);
        $rows=$data['data']??null;$next=$data['next_page_token']??null;
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>100||($next!==null&&(!is_string($next)||strlen($next)>2000)))throw new SyncFailure(422);
        return ['entries'=>array_map([FinanceMapper::class,'koctas'],$rows),'cursor'=>$next?:null];
    }
    private function request(string $method,string $url,array $headers,?array $body,int $deadline): array
    {
        if($deadline<=time())throw new SyncFailure(503);$headers[]='Accept: application/json';$headers[]='Content-Type: application/json';
        $json=$body===null?null:json_encode($body,JSON_THROW_ON_ERROR);
        if($this->transport)$reply=($this->transport)($method,$url,$headers,$json,$deadline);
        else{
            $curl=curl_init($url);$response='';$retry=0;
            curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(20,$deadline-time())),
                CURLOPT_WRITEFUNCTION=>static function($h,string $part)use(&$response):int{if(strlen($response)+strlen($part)>8388608)return 0;$response.=$part;return strlen($part);},
                CURLOPT_HEADERFUNCTION=>static function($h,string $line)use(&$retry):int{if(stripos($line,'Retry-After:')===0){$v=trim(substr($line,12));$retry=ctype_digit($v)?min(604800,(int)$v):max(0,(strtotime($v)?:time())-time());}return strlen($line);}
            ]);if($json!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$json);
            try{if(curl_exec($curl)===false)throw new SyncFailure(503,$retry);$reply=['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$response,'retryAfter'=>$retry];}finally{curl_close($curl);}
        }
        if(($reply['status']??0)!==200)throw new SyncFailure((int)($reply['status']??503),(int)($reply['retryAfter']??0));
        try{$result=json_decode($reply['body']??'',true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new SyncFailure(502);}
        if(!is_array($result))throw new SyncFailure(422);return $result;
    }
}
