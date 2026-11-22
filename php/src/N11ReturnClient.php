<?php
declare(strict_types=1);
namespace Omnigo;

/** SOAP transport supports ClaimReturnList only. It cannot approve or reject a return. */
final class N11ReturnClient
{
    public function __construct(private ?\Closure $transport=null) {}
    public function claims(N11Credentials $credentials,string $from,string $to,int $page,int $deadline): array
    {
        $zone=new \DateTimeZone('Europe/Istanbul');
        $start=\DateTimeImmutable::createFromFormat('!Y-m-d',$from,$zone);$end=\DateTimeImmutable::createFromFormat('!Y-m-d',$to,$zone);
        if(!$start||!$end||$start->format('Y-m-d')!==$from||$end->format('Y-m-d')!==$to||$start>$end||$start->diff($end)->days>31||$page<0||$page>1000||$deadline<=time())throw new SyncFailure(422);
        $escape=static fn(string $value)=>htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');
        $xml='<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:sch="http://www.n11.com/ws/schemas"><soapenv:Body><sch:ClaimReturnListRequest><auth><appKey>'.$escape($credentials->apiKey).'</appKey><appSecret>'.$escape($credentials->apiSecret).'</appSecret></auth><searchData><status>ALL</status><executer/><searchInfoType/><searchQuery/><period><startDate>'.$start->format('d/m/Y').'</startDate><endDate>'.$end->format('d/m/Y').'</endDate></period><sender>ALL</sender></searchData><pagingData><currentPage>'.$page.'</currentPage></pagingData></sch:ClaimReturnListRequest></soapenv:Body></soapenv:Envelope>';
        $url='https://api.n11.com/ws/returnService/';
        $reply=$this->transport?($this->transport)($url,$xml,$deadline):$this->request($url,$xml,$deadline);
        if(($reply['status']??0)!==200)throw new SyncFailure((int)($reply['status']??503),(int)($reply['retryAfter']??0));
        return self::parse($reply['body']??'',$page);
    }
    public static function parse(string $body,int $page): array
    {
        if(strlen($body)>4194304||preg_match('/<!DOCTYPE|<!ENTITY/i',$body))throw new SyncFailure(422);
        $document=new \DOMDocument();$previous=libxml_use_internal_errors(true);
        try{if(!$document->loadXML($body,LIBXML_NONET))throw new SyncFailure(502);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        $xpath=new \DOMXPath($document);
        $responses=$xpath->query('/*[local-name()="Envelope"]/*[local-name()="Body"]/*[local-name()="ClaimReturnListResponse"]');
        if($responses->length!==1)throw new SyncFailure(422);$root=$responses->item(0);
        $value=static fn(string $query,\DOMNode $node)=>trim($xpath->evaluate('string('.$query.')',$node));
        if($value('./result/status',$root)!=='success')throw new SyncFailure(422);
        $meta=[];foreach(['currentPage','pageCount','totalCount'] as $field){$v=$value('./pagingData/'.$field,$root);if(!ctype_digit($v))throw new SyncFailure(422);$meta[$field]=(int)$v;}
        if($meta['currentPage']!==$page||$meta['pageCount']>1000||$meta['totalCount']>20000)throw new SyncFailure(422);
        $claims=[];$nodes=$xpath->query('./claimReturnList/claimReturn',$root);
        if($nodes->length>20)throw new SyncFailure(422);
        foreach($nodes as $node){
            $claim=[];foreach(['claimReturnId','orderNumber','productId','skuId','quantity','finalPrice','status','requestDate','approvedDate','paymentDate'] as $field)$claim[$field]=$value('./'.$field,$node);
            foreach(['claimReturnId','orderNumber','productId','quantity'] as $field)if(!preg_match('/^[0-9]{1,40}$/D',$claim[$field]))throw new SyncFailure(422);
            if(!in_array($claim['status'],['REQUESTED','APPROVAL_WAITING','CANCELLED','DENIED','PENDING','PENDED','APPROVED','MANUAL_REFUND'],true))throw new SyncFailure(422);
            $claim['quantity']=(int)$claim['quantity'];if($claim['quantity']<1||$claim['quantity']>100000)throw new SyncFailure(422);
            // Kept only until the snapshot store encrypts it; no phone/address/email is imported.
            $name=ReturnRequestMapper::text($value('./buyerName',$node),200);
            if($name!=='')$claim['buyerName']=$name;
            $claim['amountCents']=KoctasOrderMapper::cents($claim['finalPrice']);unset($claim['finalPrice']);$claims[]=$claim;
        }
        if(count($claims)>$meta['totalCount']||(!$claims&&$meta['pageCount']>$page))throw new SyncFailure(422);
        return [...$meta,'claims'=>$claims];
    }
    private function request(string $url,string $xml,int $deadline): array
    {
        $curl=curl_init($url);$body='';$retryAfter=0;
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$xml,CURLOPT_HTTPHEADER=>['Content-Type: text/xml; charset=UTF-8','SOAPAction: ""'],
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(20,$deadline-time())),
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>4194304)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HEADERFUNCTION=>static function($handle,string $header)use(&$retryAfter):int{if(stripos($header,'Retry-After:')===0){$v=trim(substr($header,12));$retryAfter=ctype_digit($v)?min(604800,(int)$v):max(0,min(604800,(strtotime($v)?:time())-time()));}return strlen($header);}]);
        try{if(curl_exec($curl)===false)throw new SyncFailure(503,$retryAfter);return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body,'retryAfter'=>$retryAfter];}
        finally{curl_close($curl);}
    }
}
