<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only product types; no stock, identity or import data is changed. */
final class InventoryProductCategory
{
 private const WASHER_ACCESSORIES=[
  '%makin_si muslu%', '%makin_si baglanti muslu%', '%makin_si su giris muslu%',
  '%makin_si hortum%', '%makin_si su giris hortum%', '%makin_si tahliye hortum%',
  '%makin_si tahliye pompa%', '%makin_si yedek parca%', '%makin_si aksesuar%',
  '%makin_si kapak kilid%', '%makin_si kapi kilid%', '%makin_si kayis%',
  '%makin_si koru%', '%makin_si stand%', '%makin_si ayag%',
  '%makin_si icin %', '%makineleri icin %', '%makinalari icin %',
  '%washing machine tap%', '%washing machine faucet%', '%washing machine hose%',
  '%washing machine inlet hose%', '%washing machine drain hose%',
  '%washing machine accessory%', '%washing machine accessories%', '%washing machine spare part%',
  '%washing machine stand%', '%washing machine door lock%', '%for washing machine%',
 ];

 private const TYPES=[
  'washer_dryer'=>['Kurutmalı Çamaşır Makinaları',['%kurutmali%camasir%','%washer%dryer%']],
  'dryers'=>['Kurutma Makinaları',['%kurutma%makin%','%kurutucu%','%tumble dryer%']],
  'washing_machines'=>['Çamaşır Makinaları',['%camasir%makin%','%washing machine%']],
  'dishwashers'=>['Bulaşık Makinaları',['%bulasik%makin%','%dishwasher%']],
  'refrigerators'=>['Buzdolapları',['%buzdolab%','%refrigerator%','%fridge%']],
  'freezers'=>['Derin Dondurucular',['%dondurucu%','%freezer%']],
  'televisions'=>['Televizyonlar',['%televizyon%','%smart tv%','%qled tv%','%oled tv%','%google tv%','%android tv%','% led tv%','% tv']],
  'water_purifiers'=>['Su Arıtma Cihazları',['%su%arit%']],
  'water_dispensers'=>['Su Sebilleri',['%su sebil%']],
  'built_in_sets'=>['Ankastre Setler',['%ankastre%set%']],
  'microwaves'=>['Mikrodalga Fırınlar',['%mikrodalga%','%microwave%']],
  'ovens'=>['Fırınlar',['%firin%','%oven%']],
  'hobs'=>['Ocaklar',['%ocak%','%hob%']],
  'hoods'=>['Davlumbaz ve Aspiratörler',['%davlumbaz%','%aspirator%']],
  'air_conditioners'=>['Klimalar',['%klima%','%air conditioner%']],
  'vacuum_cleaners'=>['Süpürgeler',['%supurge%','%vacuum%']],
  'small_appliances'=>['Küçük Ev Aletleri',['%kettle%','%cayci%','%cay%makin%','%kahve%makin%','%blender%','%tost%makin%','%buharli%utu%']],
  'other'=>['Diğer Ürünler',[]],
 ];
 public static function name(string $value):string{
  if($value!==''&&!isset(self::TYPES[$value]))throw new \InvalidArgumentException('Ürün filtresini kontrol edin.');
  return $value;
 }
 public static function expression():string{
  $title='p.title';
  foreach(['Ç'=>'c','ç'=>'c','Ğ'=>'g','ğ'=>'g','İ'=>'i','I'=>'i','ı'=>'i','Ö'=>'o','ö'=>'o','Ş'=>'s','ş'=>'s','Ü'=>'u','ü'=>'u'] as $from=>$to)$title="REPLACE($title,'$from','$to')";
  $title="LOWER($title)";$sql='CASE';
  foreach(self::TYPES as $key=>[$label,$patterns]){
   if(!$patterns)continue;
   $parts=array_map(fn($pattern)=>"$title LIKE '$pattern'",$patterns);
   $match='('.implode(' OR ',$parts).')';
   if(in_array($key,['washer_dryer','washing_machines'],true)){
    $accessories=array_map(fn($pattern)=>"$title LIKE '$pattern'",self::WASHER_ACCESSORIES);
    $sql.=" WHEN $match THEN CASE WHEN (".implode(' OR ',$accessories).") THEN 'other' ELSE '$key' END";
   }else $sql.=" WHEN $match THEN '$key'";
  }
  return $sql." ELSE 'other' END";
 }
 public static function options(Database $db,string $where,array $params,string $selected=''):array{
  $rows=$db->all('SELECT '.self::expression().' AS product_category,COUNT(*) AS n FROM inventory_products p WHERE '.$where.' GROUP BY product_category',$params);
  $counts=array_column($rows,'n','product_category');$options=[];
  foreach(self::TYPES as $key=>[$label])if(!empty($counts[$key])||$key===$selected)$options[]=['value'=>$key,'label'=>$label,'count'=>(int)($counts[$key]??0)];
  return $options;
 }
}
