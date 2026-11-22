<?php
declare(strict_types=1);
require __DIR__.'/inventory-purchase-prices.php';
use Omnigo\{InventoryPurchaseTax,ProfitabilitySchema,InventoryPermissionPolicy};
InventoryPurchaseTax::install($db);
ProfitabilitySchema::install($db);
$input=['productKey'=>$key,'requestId'=>'tax-cost-excluded-0001','version'=>3,'amountCents'=>10001,'vatBasis'=>'excluded','vatRateBasisPoints'=>2000];
$saved=$service->save(1,$input,$admin);
verify($saved['purchasePriceCents']===12001&&$saved['purchaseInputCents']===10001&&$saved['purchaseNetCents']===10001,'KDV hariç tutar yuvarlanarak KDV dahil tarihçeye yazılır.');
verify($service->save(1,$input,$admin)['replayed'],'KDV bilgisi olan istek tekrarı yeni fiyat yazmaz.');
verify((int)$db->one('SELECT amount_cents FROM profitability_price_versions WHERE company_id=1 AND product_key=? AND version=4',[$key])['amount_cents']===12001,'Maliyet geçmişinde brüt fiyat korunur.');
$included=$service->save(1,[...$input,'requestId'=>'tax-cost-included-0001','version'=>4,'amountCents'=>12001,'vatBasis'=>'included'],$admin);
verify($included['purchasePriceCents']===12001&&$included['purchasePriceVersion']===5&&$included['purchaseNetCents']===10001,'Tutar aynı kalsa da dahil/hariç tercihi sürüm kontrollü düzenlenebilir.');
$meta=$service->forKeys(1,[$key])[$key];
verify($meta['purchaseVatBasis']==='included'&&$meta['purchaseInputCents']===12001,'Okumada giriş tutarı ve KDV temeli doğru döner.');
verify(!isset($service->forKeys(2,[$key])[$key]),'Başka mağazaya KDV bilgisi sızmaz.');
$hidden=InventoryPermissionPolicy::listing(['items'=>[$meta]],[]);
verify(!isset($hidden['items'][0]['purchaseNetCents'])&&!isset($hidden['items'][0]['purchaseVatBasis']),'Alış izni yoksa KDV ve tutarları API yanıtından çıkarılır.');
rejects(fn()=>$service->save(1,[...$input,'requestId'=>'tax-cost-stale-0000001','version'=>4],$admin),'Eski sürüm KDV seçimini ezemez.');
foreach([['vatBasis'=>'unknown'],['vatRateBasisPoints'=>10001],['vatRateBasisPoints'=>-1],['amountCents'=>100000000000]] as $invalid)
 rejects(fn()=>InventoryPurchaseTax::normalize([...$input,...$invalid]),'Geçersiz KDV bilgisi veya taşan tutar reddedilir.');
verify(InventoryPurchaseTax::normalize([...$input,'vatRateBasisPoints'=>0])['purchaseGrossCents']===10001,'Sıfır KDV desteklenir.');
$legacy=$service->save(1,['productKey'=>$key,'requestId'=>'tax-legacy-client-0001','version'=>5,'amountCents'=>9000],$admin);
verify(!isset($service->forKeys(1,[$key])[$key]['purchaseVatBasis']),'Eski istemcinin değiştirdiği tutara eski KDV temeli iliştirilmez.');
verify((int)$db->one('SELECT amount_cents FROM profitability_price_versions WHERE company_id=1 AND product_key=? AND version=4',[$key])['amount_cents']===12001,'Sonraki düzenleme eski maliyet geçmişini değiştirmez.');
echo "KDV dahil/hariç: tutar, yuvarlama, sürüm, tekrar, geçmiş, mağaza ve yetki sınırları geçti.\n";
