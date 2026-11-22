<?php
declare(strict_types=1);

use Omnigo\{ActiveMarketplaceListings,Database,InventorySchema,MarketplaceStockFailure,MarketplaceStockGateway,MarketplaceStockJob,MarketplaceStockPilot,MarketplaceStockPolicy,MarketplaceStockQueue,MarketplaceStockSchema,MarketplaceStockWorker};

final class PilotTestGateway implements MarketplaceStockGateway
{
    public int $reads=0,$posts=0,$polls=0,$quantity=5;
    public bool $saleActive=true,$uncertain=false;
    public string $receiptStatus='pending';
    public string $catalogSku='HB-product-32';
    public ?Closure $afterRead=null;
    public function __construct(public readonly string $channel,public string $remoteId){}

    public function read(array $configuration,string $sku,int $deadline):array
    {
        $this->checkConfiguration($configuration);
        $this->reads++;
        if ($this->afterRead) ($this->afterRead)();
        return ['merchantSku'=>$sku,'remoteIds'=>[$this->remoteId],'listingId'=>$this->remoteId,'hepsiburadaSku'=>$this->catalogSku,'quantity'=>$this->quantity,'saleActive'=>$this->saleActive];
    }
    public function submit(array $configuration,array $listing,int $quantity,int $deadline):string
    {
        $this->checkConfiguration($configuration);
        $this->posts++;
        if($this->uncertain)throw new MarketplaceStockFailure(uncertain:true);
        $this->quantity=$quantity;
        $this->saleActive=$quantity>0;
        return 'receipt-'.$this->channel;
    }
    public function status(array $configuration,string $receipt,int $deadline):string
    {
        $this->checkConfiguration($configuration);
        if($receipt!=='receipt-'.$this->channel)throw new RuntimeException('Yanlış pazaryeri işlem kodu');
        $this->polls++;
        return $this->receiptStatus;
    }
    private function checkConfiguration(array $config):void
    {
        if(($config['company']??0)!==2||($config['channel']??'')!==$this->channel)throw new RuntimeException('Yanlış mağaza veya pazaryeri bağlantısı');
    }
}

final class MarketplaceStockPilotFixture
{
    public Database $db;
    public MarketplaceStockQueue $queue;
    public MarketplaceStockWorker $worker;
    public array $actor=['id'=>10,'role'=>'admin'];
    public array $inputs=[],$gateways=[];
    public PilotTestGateway $legacy;

    public function __construct()
    {
        $db=$this->db=new Database(['dsn'=>'sqlite::memory:']);
        $db->pdo->exec(file_get_contents(dirname(__DIR__).'/schema.sqlite.sql'));
        $db->run('CREATE TABLE IF NOT EXISTS account_profiles (user_id INT PRIMARY KEY,deleted_at TEXT)');
        InventorySchema::install($db);
        MarketplaceStockSchema::install($db);
        $db->run("INSERT INTO companies VALUES(1,'Örnek A'),(2,'Örnek B')");
        $db->run("INSERT INTO users VALUES(10,'qa@example.test','Yönetici','','admin',NULL),(20,'store@example.test','Mağaza','','client',2)");
        $now=time();
        foreach(MarketplaceStockJob::CHANNELS as $channel=>$label){
            $remoteId='source-'.$channel;
            $this->gateways[$channel]=new PilotTestGateway($channel,$remoteId);
            $db->run('INSERT INTO records(company_id,kind,payload,version,updated) VALUES(?,?,?,1,?)',[2,'integration_'.$channel,'{}',gmdate('c',$now-1800)]);
            $db->run('INSERT INTO inventory_catalog_jobs(company_id,provider,last_success) VALUES(?,?,?)',[2,$channel,gmdate('c',$now-10)]);
            $db->run("INSERT INTO inventory_catalog_progress(company_id,provider,state,started_at,completed_at) VALUES(?,?,'complete',?,?)",[2,$channel,$now-150,$now-10]);
            $this->inputs[$channel]=$this->addListing($channel,$remoteId,'SKU-32');
        }
        $config=tempnam(sys_get_temp_dir(),'omnigo-pilot-');
        try {
            file_put_contents($config,json_encode(['companies'=>[2=>hash('sha256','Örnek B')]],JSON_THROW_ON_ERROR));
            $pilot=MarketplaceStockPilot::load($config);
        } finally { unlink($config); }
        $policy=new MarketplaceStockPolicy($db);
        $this->queue=new MarketplaceStockQueue($db,$policy,$pilot);
        $this->legacy=new PilotTestGateway('legacy','unreachable');
        $this->worker=new MarketplaceStockWorker($db,$policy,$this->legacy,static fn(int $company,string $channel)=>['enabled'=>true,'company'=>$company,'channel'=>$channel],$pilot,fn(string $channel)=>$this->gateways[$channel]);
    }
    public function addListing(string $channel,string $id,string $sku,?string $sharedProduct=null):array
    {
        $label=MarketplaceStockJob::CHANNELS[$channel];
        $key=$sharedProduct??hash('sha256','product-'.$channel.'-'.$id);
        $listing=hash('sha256',$id);
        $seen=gmdate('c',time()-20);
        if(!$this->db->one('SELECT product_key FROM inventory_products WHERE company_id=2 AND product_key=?',[$key])){
            $this->db->run("INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(2,?,'Stok ürünü',?,'','',?)",[$key,$sku,$seen]);
        }
        $evidence=['saleActive'=>true];if($channel==='hepsiburada')$evidence['sourceCatalogId']='HB-product-32';
        $this->db->run('INSERT INTO inventory_listings VALUES(?,?,?,?,?,?,?,?,?)',[2,$label,$listing,$id,$key,$sku,json_encode($evidence),5,$seen]);
        $input=['productKey'=>$key,'listingKey'=>$listing,'quantity'=>0,'sourceQuantity'=>5,'confirmed'=>true,'provider'=>$label,'requestId'=>'request-pilot-'.$channel.'-00001'];
        if($channel==='hepsiburada')$input['identityToken']=(new ActiveMarketplaceListings($this->db))->writableTarget(2,$key,$listing)['identityToken'];
        return $input;
    }
    public function submit(string $channel):array{return $this->queue->submit(2,$this->inputs[$channel],$this->actor);}
    public function status(string $id):string{return $this->queue->get(2,$id,$this->actor)['state'];}
    public function tick():void
    {
        $this->db->run('UPDATE marketplace_stock_requests SET next_run=0');
        if(!$this->worker->tick(time()+30))throw new RuntimeException('İşleyici bekleyen görevi bulamadı');
    }
}
