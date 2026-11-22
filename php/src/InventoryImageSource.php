<?php
declare(strict_types=1);
namespace Omnigo;

/** Exact manufacturer model first; otherwise the exact marketplace listing's image. */
final class InventoryImageSource
{
    private \Closure $fetch;
    private array $catalog;
    public function __construct(?\Closure $fetch=null,?array $catalog=null)
    {
        $this->fetch=$fetch??static fn(string $url,int $deadline,int $limit)=>(new InventoryImageHttp())->get($url,$deadline,$limit);
        $this->catalog=$catalog??json_decode(file_get_contents(dirname(__DIR__).'/resources/inventory-image-sources.json'),true,64,JSON_THROW_ON_ERROR);
    }
    public static function productImage(string $html,string $model):?string
    {
        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',$html,$matches);
        foreach($matches[1] as $json){
            $product=json_decode($json,true);if(!is_array($product)||($product['@type']??'')!=='Product'||strcasecmp((string)($product['mpn']??''),$model)!==0)continue;
            $images=$product['image']??[];if(is_string($images))$images=[$images];if(!is_array($images))continue;
            $images=array_values(array_filter($images,static fn($url)=>is_string($url)&&str_starts_with($url,'https://media3.bsh-group.com/Product_Shots/')));
            $selected=null;foreach($images as $url)if(str_contains($url,'_STP_')){$selected=$url;break;}$selected??=$images[0]??null;
            if($selected)return preg_replace('~/Product_Shots/(?:\d+x/)?~','/Product_Shots/2000x/',$selected);
        }
        return null;
    }
    public function download(array $product,array $listings,int $deadline):array
    {
        $brand=trim((string)($product['brand']??''));$model=trim((string)($product['model']??''))?:trim($product['sku']);$candidates=[];$last=null;
        foreach($listings as $row){
            $evidence=json_decode($row['evidence'],true,32,JSON_THROW_ON_ERROR);
            if($brand==='')$brand=trim((string)($evidence['brand']??''))?:trim((string)($evidence['imageBrand']??''));
            foreach($evidence['imageCandidates']??[] as $url)if(InventoryImageCandidates::valid($url))$candidates[]=['url'=>$url,'source'=>$url];
        }
        if($brand===''&&preg_match('/\b(profilo|regal)\b/iu',$product['title'],$m))$brand=$m[1];
        $brand=mb_strtolower(str_replace(['İ','I'],['i','i'],$brand));$identity=$brand.':'.mb_strtoupper($model);$official=[];
        if(isset($this->catalog[$identity]))$official[]=['url'=>$this->catalog[$identity]['image'],'source'=>$this->catalog[$identity]['page']];
        if($brand==='profilo'&&preg_match('/^[A-Z0-9]{5,20}$/iD',$model)){
            $page='https://www.profilo.com/tr/tr/product/'.rawurlencode(strtoupper($model));
            $official[]=['page'=>$page,'source'=>$page];
        }
        $seen=[];
        foreach(array_slice([...$official,...$candidates],0,4) as $candidate){
            if(time()+1>=$deadline)break;
            try{
                $url=$candidate['url']??null;
                if(isset($candidate['page'])){$html=($this->fetch)($candidate['page'],$deadline,2097152);$url=self::productImage($html,$model);}
                if(!$url||isset($seen[$url]))continue;$seen[$url]=true;
                $bytes=($this->fetch)($url,$deadline,5242880);return ['bytes'=>InventoryImageLayout::normalize($bytes),'source'=>$candidate['source']];
            }
            catch(\Throwable $e){$last=$e;}
        }
        throw $last??new \DomainException('Doğru modele ait ürün görseli henüz kaynakta bulunamadı.');
    }
}
