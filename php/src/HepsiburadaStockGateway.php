<?php
declare(strict_types=1);
namespace Omnigo;

/** Stock-only API. It never sends price, cargo, listing state or purchase limits. */
final class HepsiburadaStockGateway implements MarketplaceStockGateway
{
    public function __construct(private ?\Closure $transport=null){}
    public function read(array $configuration,string $sku,int $deadline):array
    {
        $data=$this->request($configuration,'GET','',null,$deadline,['offset'=>0,'limit'=>100,'merchantSkuList'=>$sku]);
        $rows=$data['listings']??null;
        if(!is_array($rows)||!array_is_list($rows))throw new MarketplaceStockFailure();
        $matches=array_values(array_filter($rows,static fn($row)=>is_array($row)&&($row['merchantSku']??null)===$sku));
        if(count($matches)!==1)throw new \DomainException('Pazaryerinde tek ve kesin bir ürün eşleşmesi bulunamadı. Stok gönderilmedi.');
        $row=$matches[0];$quantity=$row['availableStock']??null;$hb=$row['hepsiburadaSku']??null;
        if(!is_int($quantity)||$quantity<0||!is_string($hb)||trim($hb)===''||strlen($hb)>180)throw new MarketplaceStockFailure();
        return ['merchantSku'=>$sku,'hepsiburadaSku'=>$hb,'salePriceCents'=>MarketplacePrice::cents($row['price']??null),'listingId'=>$row['listingId']??null,'quantity'=>$quantity,'saleActive'=>InventorySaleStatus::fromSource('hepsiburada',$row),'remoteIds'=>array_values(array_filter([$hb,$row['listingId']??null], 'is_string'))];
    }
    public function submitPrice(array $configuration,array $listing,int $price,int $deadline):string
    {
        $data=$this->request($configuration,'POST','/price-uploads',[['hepsiburadaSku'=>$listing['hepsiburadaSku'],'merchantSku'=>$listing['merchantSku'],'price'=>$price/100]],$deadline);
        $id=$data['id']??$data['Id']??null;
        if(!is_string($id)||!preg_match('/^[a-zA-Z0-9-]{8,100}$/D',$id))throw new MarketplaceStockFailure(uncertain:true);
        return $id;
    }
    public function submit(array $configuration,array $listing,int $quantity,int $deadline):string
    {
        if($quantity<0||$quantity>100000000)throw new \InvalidArgumentException('Pazaryeri stok adedi geçersiz.');
        $data=$this->request($configuration,'POST','/stock-uploads',[['hepsiburadaSku'=>$listing['hepsiburadaSku'],'merchantSku'=>$listing['merchantSku'],'availableStock'=>$quantity]],$deadline);
        $receipt=$data['id']??$data['Id']??null;
        if(!is_string($receipt)||!preg_match('/^[a-zA-Z0-9-]{8,100}$/D',$receipt))throw new MarketplaceStockFailure(uncertain:true);
        return $receipt;
    }
    public function status(array $configuration,string $receipt,int $deadline):string
    {
        if(!preg_match('/^[a-zA-Z0-9-]{8,100}$/D',$receipt))throw new \DomainException('Pazaryeri takip kimliği geçersiz.');
        $data=$this->request($configuration,'GET','/stock-uploads/id/'.rawurlencode($receipt),null,$deadline);
        $status=strtolower((string)($data['status']??$data['Status']??''));$errors=$data['errors']??$data['Errors']??null;
        if(!empty($errors)||$status==='failed')return 'failed';
        return match($status){'done'=>'done','ready','inprogress','pending','processing'=>'pending',default=>throw new MarketplaceStockFailure()};
    }
    private function request(array $configuration,string $method,string $suffix,?array $payload,int $deadline,array $query=[]):array
    {
        if(empty($configuration['enabled']))throw new \DomainException('Hepsiburada bağlantısı kapalı.');
        $credentials=HepsiburadaCredentials::fromArray($configuration);
        $url='https://listing-external.hepsiburada.com/listings/merchantid/'.rawurlencode($credentials->merchantId).$suffix.($query?'?'.http_build_query($query):'');
        if($deadline<=time())throw new MarketplaceStockFailure(retryable:true);
        if($this->transport)return ($this->transport)($method,$url,$payload);
        $headers=['Accept: application/json','Content-Type: application/json','Authorization: Basic '.base64_encode($credentials->username.':'.$credentials->serviceKey),'User-Agent: '.$credentials->userAgent];
        $body='';$retry=30;$curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>max(1,min(20,$deadline-time())),CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>8388608)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HEADERFUNCTION=>static function($handle,$line)use(&$retry){if(preg_match('/^Retry-After:\s*(\d+)/i',$line,$match))$retry=max(5,min(3600,(int)$match[1]));return strlen($line);}]);
        if($payload!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($ok===false||$status<200||$status>=300)throw new MarketplaceStockFailure($method==='POST'&&($ok===false||$status===408||$status>=500),$status===429||($method==='GET'&&($status===0||$status>=500)),$retry);
        try{$result=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new MarketplaceStockFailure(uncertain:$method==='POST');}
        if(!is_array($result))throw new MarketplaceStockFailure(uncertain:$method==='POST');
        return $result;
    }
}
