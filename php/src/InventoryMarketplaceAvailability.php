<?php
declare(strict_types=1);
namespace Omnigo;

/** Display-only catalogue coverage. Never links products or changes quantities. */
final class InventoryMarketplaceAvailability
{
 public function __construct(private Database $db){}
 public function forProducts(int $company,array $products,array $snapshots,array $sales):array
 {
  if(!$products)return [];
  $catalogs=[];foreach($this->db->all('SELECT provider,last_success,error FROM inventory_catalog_jobs WHERE company_id=?',[$company]) as $row)$catalogs[InventoryCatalogPage::LABELS[$row['provider']]??$row['provider']]=$row;
  // A catalogue row with the same code but another product identity is a matching
  // issue, not proof that the product was never created in that marketplace.
  $aliases=[];foreach($products as $p)foreach([$p['sku'],$p['barcode']] as $code)if(trim($code)!=='')$aliases[hash('sha256',mb_strtoupper(trim($code)))]=true;
  $candidates=[];
  if($aliases){$in=implode(',',array_fill(0,count($aliases),'?'));foreach($this->db->all("SELECT DISTINCT a.alias_key,a.provider,a.product_key FROM inventory_aliases a WHERE a.company_id=? AND a.alias_key IN ($in) AND EXISTS(SELECT 1 FROM inventory_listings l WHERE l.company_id=a.company_id AND l.product_key=a.product_key AND l.provider=a.provider AND l.remote_id NOT LIKE 'order-sku:%')",[$company,...array_keys($aliases)]) as $row)$candidates[$row['alias_key']][$row['provider']][$row['product_key']]=true;}
  $result=[];foreach($products as $p){$key=$p['product_key'];$groups=[];foreach($snapshots[$key]??[] as $source)$groups[$source['provider']][]=$source;
   $providers=array_values(array_unique([...array_keys($catalogs),...array_keys($groups),...array_column($sales[$key]??[],'provider')]));
   foreach($providers as $provider){$candidate=false;foreach([$p['sku'],$p['barcode']] as $code)if(trim($code)!==''&&!empty($candidates[hash('sha256',mb_strtoupper(trim($code)))][$provider]))$candidate=true;
    $result[$key][]=self::state($provider,$groups[$provider]??[],$catalogs[$provider]??[],$candidate);
   }
  }return $result;
 }
 public static function state(string $provider,array $sources,array $catalog,bool $candidate=false):array
 {
  $checked=$catalog['last_success']??null;$fresh=$checked&&strtotime($checked)>=time()-1800&&empty($catalog['error']);
  $status='unknown';$detail='Güncel katalog veya satış durumu henüz doğrulanamadı.';
  if($fresh&&$sources){$current=array_values(array_filter($sources,fn($s)=>strtotime($s['observedAt']??'')>=time()-1800&&in_array($s['health']??'', ['current','unknown'],true)));
   if(array_filter($current,fn($s)=>($s['saleActive']??null)===true)){$status='active';$detail='Pazaryeri ürünü satışa açık olarak bildiriyor.';}
   elseif(count($current)===count($sources)&&!array_filter($current,fn($s)=>($s['saleActive']??null)!==false)){$status='closed';$detail='Ürün tanımlı; pazaryeri satışa kapalı olarak bildiriyor.';}
   elseif($current)$detail='Ürün tanımlı; kaynak satışa açık/kapalı bilgisini doğrulamıyor.';
  }elseif($fresh&&!$sources){$status=$candidate?'unmatched':'not_found';$detail=$candidate?'Aynı stok kodu veya barkodda katalog kaydı var; ürün eşleşmesi kontrol edilmeli.':'Son tamamlanan katalogda bu ürün bulunamadı; tanımlanmamış veya henüz açılmamış olabilir.';}
  return ['provider'=>$provider,'status'=>$status,'label'=>['active'=>'Satışa açık','closed'=>'Satışa kapalı','unmatched'=>'Eşleşme bekliyor','not_found'=>'Ürün bulunamadı','unknown'=>'Kontrol bekliyor'][$status],'detail'=>$detail,'checkedAt'=>$checked];
 }
}
