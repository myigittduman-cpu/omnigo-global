<?php
declare(strict_types=1);
namespace Omnigo;

/** Official SettlementService reader. No payment or account mutation methods. */
final class N11FinanceClient
{
    public function __construct(private ?\Closure $transport=null) {}
    public function settlements(N11Credentials $credentials,string $from,string $to,int $page,int $deadline):array
    {
        $start=self::day($from);$end=self::day($to);
        if($start>$end||(new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days>29||$page<0||$page>100||$deadline<=time())throw new SyncFailure(422);
        $escape=static fn($v)=>htmlspecialchars($v,ENT_XML1|ENT_QUOTES,'UTF-8');
        $xml='<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:sch="http://www.n11.com/ws/schemas"><soapenv:Body><sch:GetSettlementListRequest><auth><appKey>'.$escape($credentials->apiKey).'</appKey><appSecret>'.$escape($credentials->apiSecret).'</appSecret></auth><startDate>'.$start.'</startDate><endDate>'.$end.'</endDate><pagingData><currentPage>'.$page.'</currentPage><pageSize>100</pageSize></pagingData></sch:GetSettlementListRequest></soapenv:Body></soapenv:Envelope>';
        $url='https://api.n11.com/ws/settlementService/';
        $reply=$this->transport?($this->transport)($url,$xml,$deadline):$this->request($url,$xml,$deadline);
        if(($reply['status']??0)!==200)throw new SyncFailure((int)($reply['status']??503),(int)($reply['retryAfter']??0));
        $data=self::parse($reply['body']??'',$page);
        foreach($data['entries'] as $entry)if($entry['transactionDate']<$from||$entry['transactionDate']>$to)throw new SyncFailure(422);
        return $data;
    }
    public static function day(mixed $value,bool $optional=false):string
    {
        if($optional&&($value===null||$value===''))return '';
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))throw new SyncFailure(422);
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$day||$day->format('Y-m-d')!==$value)throw new SyncFailure(422);
        return $value;
    }
    public static function parse(string $body,int $page):array
    {
        if(strlen($body)>4194304||preg_match('/<!DOCTYPE|<!ENTITY/i',$body))throw new SyncFailure(422);
        $doc=new \DOMDocument();$previous=libxml_use_internal_errors(true);
        try{if(!$doc->loadXML($body,LIBXML_NONET))throw new SyncFailure(502);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        $xp=new \DOMXPath($doc);$roots=$xp->query('/*[local-name()="Envelope"]/*[local-name()="Body"]/*[local-name()="GetSettlementListResponse"]');
        if($roots->length!==1)throw new SyncFailure(422);$root=$roots->item(0);
        $value=static fn($query,$node)=>trim($xp->evaluate('string('.$query.')',$node));
        if($value('./result/status',$root)!=='success')throw new SyncFailure(422);
        $meta=[];foreach(['currentPage','pageSize','pageCount','totalCount'] as $field){$v=$value('./settlementListData/metadata/'.$field,$root);if(!ctype_digit($v))throw new SyncFailure(422);$meta[$field]=(int)$v;}
        if($meta['currentPage']!==$page||$meta['pageSize']!==100||$meta['pageCount']>100||$meta['totalCount']>10000||($meta['totalCount']>0&&$meta['pageCount']!==(int)ceil($meta['totalCount']/100)))throw new SyncFailure(422);
        $entries=[];$nodes=$xp->query('./settlementListData/settlementList',$root);
        $rows=[];
        foreach($nodes as $row){$raw=[];foreach(['deductionAmount','paymentAmount','remittanceDate','settlementAmount','settlementDate','status'] as $field)$raw[$field]=$value('./'.$field,$row);$rows[]=$raw;}
        $entries=N11SettlementPage::normalize($rows,min(100,max(0,$meta['totalCount']-$page*100)));
        return ['count'=>$meta['totalCount'],'cursor'=>$page+1<$meta['pageCount']?$page+1:null,'entries'=>$entries];
    }
    private function request(string $url,string $xml,int $deadline):array
    {
        $curl=curl_init($url);$body='';$retry=0;
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$xml,CURLOPT_HTTPHEADER=>['Content-Type: text/xml; charset=UTF-8','SOAPAction: ""'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(20,$deadline-time())),
            CURLOPT_WRITEFUNCTION=>static function($h,$part)use(&$body){if(strlen($body)+strlen($part)>4194304)return 0;$body.=$part;return strlen($part);},
            CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$retry){if(stripos($line,'Retry-After:')===0){$v=trim(substr($line,12));$retry=ctype_digit($v)?min(604800,(int)$v):max(0,min(604800,(strtotime($v)?:time())-time()));}return strlen($line);}]);
        try{if(curl_exec($curl)===false)throw new SyncFailure(503,$retry);return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body,'retryAfter'=>$retry];}finally{curl_close($curl);}
    }
}
