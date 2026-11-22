<?php
declare(strict_types=1);
namespace Omnigo;

final class N11QuestionClient
{
    public function __construct(private ?QuestionReadTransport $transport=null){}
    private static function escape(string $value): string
    {
        return htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');
    }
    private function request(array $config,string $operation,string $content,int $deadline): array
    {
        $xml='<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:sch="http://www.n11.com/ws/schemas"><soapenv:Body><sch:'.$operation.'Request><auth><appKey>'.self::escape($config['apiKey']).'</appKey><appSecret>'.self::escape($config['apiSecret']).'</appSecret></auth>'.$content.'</sch:'.$operation.'Request></soapenv:Body></soapenv:Envelope>';
        $body=($this->transport??new QuestionReadTransport())->read('https://api.n11.com/ws/productService/',['Content-Type: text/xml; charset=UTF-8','SOAPAction: ""'],$deadline,$xml);
        if(preg_match('/<!DOCTYPE|<!ENTITY/i',$body))throw new SyncFailure(422);
        $document=new \DOMDocument();$previous=libxml_use_internal_errors(true);
        try{if(!$document->loadXML($body,LIBXML_NONET))throw new SyncFailure(502);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        $xpath=new \DOMXPath($document);$nodes=$xpath->query('/*[local-name()="Envelope"]/*[local-name()="Body"]/*[local-name()="'.$operation.'Response"]');
        if($nodes->length!==1)throw new SyncFailure(422);$root=$nodes->item(0);
        $statuses=$xpath->query('./result/status',$root);
        $status=$statuses->length===1?trim($statuses->item(0)->textContent):null;
        if($operation==='SaveProductAnswer'){
            if($status==='failure')throw new InboxFailure(422);
            if($status!=='success')throw new InboxFailure(502,0,true);
        }elseif($status!=='success')throw new SyncFailure(422,65);
        return [$xpath,$root];
    }
    public function listing(array $config,int $page,int $deadline,?string $end=null): array
    {
        if($page<0||$page>1000)throw new SyncFailure(422);
        $zone=new \DateTimeZone('Europe/Istanbul');$last=new \DateTimeImmutable($end??'today',$zone);$first=$last->modify('-13 days');
        [$xpath,$root]=$this->request($config,'GetProductQuestionList','<productQuestionSearch><status>OPEN</status><startDate>'.$first->format('d/m/Y').'</startDate><endDate>'.$last->format('d/m/Y').'</endDate></productQuestionSearch><pagingData><currentPage>'.$page.'</currentPage><pageSize>50</pageSize></pagingData>',$deadline);
        $pages=trim($xpath->evaluate('string(./pagingData/pageCount)',$root));$current=trim($xpath->evaluate('string(./pagingData/currentPage)',$root));
        if(!ctype_digit($pages)||(int)$pages>1000||!ctype_digit($current)||(int)$current!==$page)throw new SyncFailure(422);
        $ids=[];foreach($xpath->query('./productQuestions/productQuestion',$root) as $node){$id=trim($xpath->evaluate('string(./id)',$node));if(!ctype_digit($id))throw new SyncFailure(422);$ids[]=$id;}
        if(count($ids)>50)throw new SyncFailure(422);
        return ['ids'=>$ids,'complete'=>$page+1>=max(1,(int)$pages),'windowStart'=>$first->format('Y-m-d'),'windowEnd'=>$last->format('Y-m-d')];
    }
    public function detail(array $config,string $id,int $deadline): array
    {
        if(!ctype_digit($id))throw new SyncFailure(422);
        [$xpath,$root]=$this->request($config,'GetProductQuestionDetail','<productQuestionId>'.$id.'</productQuestionId>',$deadline);
        $returnedId=trim($xpath->evaluate('string(./productQuestion/id)',$root));
        if($returnedId!==''&&$returnedId!==$id)throw new SyncFailure(422);
        $raw=[];foreach(['status','productId','productTitle','question','questionDate','answer','answeredDate'] as $key)$raw[$key]=trim($xpath->evaluate('string(./productQuestion/'.$key.')',$root));
        if($raw['status']==='OPEN'&&$raw['answer']!=='')throw new SyncFailure(422);
        return MarketplaceQuestionMapper::n11($raw,$id);
    }

    public function reply(array $config,string $id,string $answer,int $deadline,?callable $beforeSend=null): array
    {
        if(!preg_match('/^[1-9][0-9]{0,18}$/D',$id)||mb_strlen($answer,'UTF-8')>2048||preg_match('/^\s*$/u',$answer)!==0
            ||preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',$answer)!==0)throw new InboxFailure(422);
        if($deadline<=time())throw new InboxFailure(503);
        if($beforeSend)$beforeSend();
        try{
            $this->request($config,'SaveProductAnswer','<productQuestionId>'.$id.'</productQuestionId><answer>'.self::escape($answer).'</answer>',$deadline);
        }catch(SyncFailure $error){
            // A parse error and a missing HTTP acknowledgement cannot safely be retried as a write.
            $status=$error->httpStatus;
            if($status===422)throw new InboxFailure(502,0,true);
            $rejected=in_array($status,[400,401,403,404,405,409,410,413,415,429],true);
            throw new InboxFailure($status,$error->retryAfter,!$rejected);
        }
        return ['thread_id'=>$id,'accepted'=>true];
    }
}
