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
  $codes=array_values(array_unique(array_filter(array_map(InventoryLiveSkuIdentity::normalize(...),array_column($products,'sku')),static fn($code)=>$code!=='')));
  $candidates=[];
  if($codes){
   $in=implode(',',array_fill(0,count($codes),'?'));
   foreach($this->db->all("SELECT DISTINCT l.sku,l.provider FROM inventory_listings l WHERE l.company_id=? AND ".InventoryLiveSkuIdentity::confirmedSql()." AND UPPER(TRIM(l.sku)) IN ($in)",[$company,...$codes]) as $row)
    $candidates[InventoryLiveSkuIdentity::normalize($row['sku'])][$row['provider']]=true;
  }
  $result=[];foreach($products as $p){$key=$p['product_key'];$groups=[];foreach($snapshots[$key]??[] as $source)$groups[$source['provider']][]=$source;
   $providers=array_values(array_unique([...array_keys($catalogs),...array_keys($groups),...array_column($sales[$key]??[],'provider')]));
   foreach($providers as $provider){$candidate=!empty($candidates[InventoryLiveSkuIdentity::normalize($p['sku'])][$provider]);
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
  }elseif($fresh&&!$sources){$status=$candidate?'unmatched':'not_found';$detail=$candidate?'Aynı stok kodunda katalog kaydı var; ürün eşleşmesi kontrol edilmeli.':'Son tamamlanan katalogda bu ürün bulunamadı; tanımlanmamış veya henüz açılmamış olabilir.';}
  return ['provider'=>$provider,'status'=>$status,'label'=>['active'=>'Satışa açık','closed'=>'Satışa kapalı','unmatched'=>'Eşleşme bekliyor','not_found'=>'Ürün bulunamadı','unknown'=>'Kontrol bekliyor'][$status],'detail'=>$detail,'checkedAt'=>$checked];
 }
}
