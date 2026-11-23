<?php
declare(strict_types=1);
namespace Omnigo;

final class InventoryRoutes
{
    public function __construct(private Database $db,private Security $security){}
    public function handle(string $path,string $method,array $user):void
    {
        if(!preg_match('#^/api/inventory(?:/(commands|history|products|matching|start|visibility|image|purchase-price))?$#D',$path,$match))return;
        $maintenance=dirname(__DIR__).'/storage/inventory-engine-maintenance.flag';
        if($method!=='GET'&&is_file($maintenance)&&filemtime($maintenance)>time()-300)Http::fail(503,'Stok altyapısı kısa süreli bakımda. Girdiğiniz bilgileri koruyup birazdan yeniden deneyin.');
        $company=$this->security->company($user,$_GET['company']??null);
        $policy=new CustomerPermissions($this->db,$company);$policy->require($user,'inventory');
        try{
            if(($match[1]??'')==='purchase-price'&&$method==='POST'){$policy->require($user,'inventory','editPurchasePrice');Http::json((new InventoryPurchasePrices($this->db))->save($company,Http::body(),$user));}
            if(($match[1]??'')==='image'){
                (new InventoryImageRoutes($this->db))->handle($company,$method,$user);
            }
            if(($match[1]??'')==='start'&&$method==='POST'){
                Security::admin($user);$input=Http::body();
                if(!is_int($input['profileVersion']??null))throw new \InvalidArgumentException('Müşteri ayarlarını yenileyin.');
                Http::json((new InventoryActivation($this->db,new IntegrationVault($this->db,dirname(__DIR__).'/storage/backup.key')))->start($company,(int)$user['id'],$input['profileVersion']),202);
            }
            if($method==='GET'&&!isset($match[1])){
                if(($_GET['detail']??'')==='sku-conflicts'){
                    Http::json(['items'=>(new InventoryStockCardConflicts($this->db))->listing($company)]);
                }
                $filter=(string)($_GET['filter']??'stocked');
                if($filter==='removed')Security::admin($user);
                $flags=CustomerSensitiveVisibility::flags($this->db,$user,$company,'inventory');
                $data=(new InventoryRead($this->db))->listing($company,(string)($_GET['query']??''),(int)($_GET['page']??0),$filter,(string)($_GET['provider']??''),(int)($_GET['pageSize']??10),(string)($_GET['category']??''),(string)($_GET['sort']??'stable'),$flags);
                $data['activation']=(new InventoryActivation($this->db,new IntegrationVault($this->db,dirname(__DIR__).'/storage/backup.key')))->state($company);
                $data['connectionImports']=($user['role']??'')==='admin'?(new IntegrationImportRuns($this->db))->listing($company):[];
                $data=CustomerSensitiveVisibility::redact(InventoryPermissionPolicy::listing($data,InventoryPermissionPolicy::capabilities($policy,$user)),$flags);
                $data['sensitiveVisibility']=$flags;
                $stockPolicy=new MarketplaceStockPolicy($this->db);
                $data['marketplaceManagement']=($user['role']??'')==='admin'&&$stockPolicy->enabled($company);
                $targets=$data['marketplaceManagement']?(new MarketplaceStockQueue($this->db,$stockPolicy))->targets($company,array_column($data['items'],'key')):[];
                foreach($data['items'] as &$item){$item['sensitiveVisibility']=$data['sensitiveVisibility'];if($data['marketplaceManagement'])$item['marketplaceStockTargets']=$targets[$item['key']]??[];}unset($item);
                Http::json($data);
            }
            if(($match[1]??'')==='products'&&$method==='POST'){
                Security::admin($user);
                Http::json((new InventoryManual($this->db))->create($company,Http::body(),(int)$user['id']),201);
            }
            if(($match[1]??'')==='visibility'&&$method==='POST'){
                Security::admin($user);
                Http::json((new InventoryVisibility($this->db))->change($company,Http::body(),(int)$user['id']));
            }
            if(($match[1]??'')==='matching'&&$method==='POST'){
                Security::admin($user);$input=Http::body();
                if(!is_string($input['productKey']??null)||!is_array($input['providers']??null))throw new \InvalidArgumentException('Ürün ve pazaryerlerini kontrol edin.');
                Http::json((new InventoryMatching($this->db))->request($company,$input['productKey'],$input['providers']),202);
            }
            if(($match[1]??'')==='commands'){
                if($method==='POST'){
                    $input=Http::body();$policy->require($user,'inventory',InventoryPermissionPolicy::commandAction($input));
                    if(($input['mode']??'')==='store_return')$policy->require($user,'visibility','viewReturns');
                    $result=(new InventoryCommands($this->db))->submit($company,$input,(int)$user['id']);
                    if($result['state']==='pending')$result=(new InventoryCommandCompletion($this->db))->finish($company,$result['requestId']);
                    Http::json($result,$result['state']==='pending'?202:200);
                }
                if($method==='GET'){
                    $row=$this->db->one('SELECT state,result,mode,actor_id FROM inventory_commands WHERE company_id=? AND request_id=?',[$company,(string)($_GET['requestId']??'')]);
                    if(!$row)Http::fail(404,'Stok kaydı bulunamadı.');
                    $policy->require($user,'inventory',InventoryPermissionPolicy::commandAction($row));
                    if(($user['role']??'')!=='admin'&&(int)$row['actor_id']!==(int)$user['id'])Http::fail(403,'Bu stok işlemi başka bir kullanıcıya ait.');
                    Http::json(['state'=>$row['state'],'result'=>$row['result']?json_decode($row['result'],true,32,JSON_THROW_ON_ERROR):null]);
                }
            }
            if(($match[1]??'')==='history'&&$method==='GET'){
                $policy->require($user,'inventory','viewMovements');
                $stockPolicy=new MarketplaceStockPolicy($this->db);$marketplace=$user['role']==='admin'&&$stockPolicy->option($company)!==null;
                $flags=CustomerSensitiveVisibility::flags($this->db,$user,$company,'inventory');
                $history=(new InventoryHistory($this->db))->listing($company,(string)($_GET['productKey']??''),(string)($_GET['from']??''),(string)($_GET['to']??''),(string)($_GET['before']??''),$flags,$marketplace);
                Http::json([...$history,'marketplaceManagement'=>$marketplace&&$stockPolicy->enabled($company)]);
            }
            Http::fail(405,'Bu stok işlemi desteklenmiyor.');
        }catch(\InvalidArgumentException $e){Http::fail(400,$e->getMessage());}
        catch(\OutOfBoundsException $e){Http::fail(404,$e->getMessage());}
        catch(\DomainException $e){Http::fail(409,$e->getMessage());}
    }
}

