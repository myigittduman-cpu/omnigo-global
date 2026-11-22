<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/support/MarketplaceStockPilotFixture.php';
use Omnigo\{MarketplacePriceQueue,MarketplacePriceWorker,MarketplaceStockPilot,MarketplaceStockSchema};
$f=new MarketplaceStockPilotFixture();$db=$f->db;
MarketplaceStockSchema::installAccess($db);
$db->run('INSERT INTO marketplace_model_access VALUES(2,1)');
$key=$f->inputs['trendyol']['productKey'];
$db->run("UPDATE inventory_listings SET product_key=? WHERE company_id=2",[$key]);
$q=new MarketplacePriceQueue($db);
$body=['productKey'=>$key,'requestId'=>'example-price-group-1234','confirmed'=>true,'priceCents'=>2100000];
$rows=$q->add(2,$body,$f->actor);
if(count($rows)!==3||$rows!==$q->add(2,$body,$f->actor))throw new RuntimeException('Üç bağımsız ilan veya tekrar güvenliği başarısız.');
try{$q->add(1,$body,$f->actor);throw new RuntimeException('Kapalı mağaza kabul edildi.');}catch(DomainException){}
$sent=[];
$gate=function($provider)use($f,&$sent){return new class($provider,$f,$sent) {
 public function __construct(private string $p,private MarketplaceStockPilotFixture $f,private array &$sent){}
 public function read($cfg,$sku,$deadline){return ['salePriceCents'=>$this->sent[$this->p]??10000]+$this->f->gateways[$this->p]->read($cfg,$sku,$deadline);}
 public function submitPrice($cfg,$listing,$price,$deadline){if(isset($this->sent[$this->p]))throw new RuntimeException('Çift gönderim');$this->sent[$this->p]=$price;return 'example';}
};};
$w=new MarketplacePriceWorker($db,MarketplaceStockPilot::load(),fn($c,$p)=>['enabled'=>true,'company'=>$c,'channel'=>$p],$gate);
for($i=0;$i<7;$i++){$w->tick(time()+30);$db->run('UPDATE marketplace_price_jobs SET due=0');}
if(count($sent)!==3||array_unique(array_column($q->results(2,$body['requestId']),'state'))!==['succeeded'])throw new RuntimeException('Pazaryerinden fiyat doğrulaması başarısız.');
echo "Üç kanala gönderim, tekrar güvenliği, mağaza izolasyonu ve fiyat doğrulaması geçti.\n";
