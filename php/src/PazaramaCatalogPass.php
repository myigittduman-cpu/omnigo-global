<?php
declare(strict_types=1);
namespace Omnigo;

/** Keep the existing inventory scan and verify salability separately for the manual store. */
final class PazaramaCatalogPass
{
    private const CURSOR='catalog_cursor_pazarama';
    private bool $enabled;
    private string $revision;

    public function __construct(
        private Database $db,
        private IntegrationVault $vault,
        private int $company,
        private array $access,
    ) {
        $this->enabled=MarketplaceStockJob::pazaramaEnabled($db,MarketplaceStockPilot::load(),$company);
        $this->revision=(string)$access['revision'];
    }

    public function approvedPage(array $job,int $deadline):array
    {
        $pageNo=(int)$job['page_no'];
        $cursor=null;
        if($pageNo>0){
            $row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$this->company,self::CURSOR]);
            $saved=json_decode($row['payload']??'null',true,16,JSON_THROW_ON_ERROR);
            if(($saved['revision']??null)!==$this->revision||($saved['page']??null)!==$pageNo||!is_string($saved['cursor']??null))
                return $this->restart();
            $cursor=$saved['cursor'];
        }
        if(!$this->enabled)return $this->restart();
        try{
            $response=(new PazaramaStockGateway())->approvedPage($this->access,$cursor,$deadline);
        }catch(MarketplaceStockFailure $error){
            if($error->retryable)throw new SyncFailure(503,$error->retryAfter);
            throw $error;
        }
        $next=$response['nextCursor'];
        if($next!==null&&$next===$cursor)throw new \DomainException('Pazarama aynı katalog imlecini döndürdü.');
        $page=InventoryCatalogPage::normalize('pazarama',[
            'success'=>true,'data'=>$response['sellerProducts'],'hasNextPage'=>$next!==null,
        ],$pageNo,1,true);
        return $page+['cursor'=>$next];
    }

    public function checkpoint(array $job,array $page):?\Closure
    {
        if(!$this->enabled&&(int)$job['partition_no']===0)return null;
        return function(int $nextPage,int $partition,bool $next)use($page):array{
            $current=$this->vault->get($this->company,'pazarama');
            if(($current['revision']??null)!==$this->revision)throw new \DomainException('Pazarama katalog aktarımı sırasında mağaza bağlantısı değişti.');
            if($partition===0)return $next?[$nextPage,0,true]:[0,1,true];
            $this->db->run('DELETE FROM records WHERE company_id=? AND kind=?',[$this->company,self::CURSOR]);
            if(!empty($page['restart']))return [0,0,true];
            if(!$next)return [0,0,false];
            $payload=json_encode(['revision'=>$this->revision,'page'=>$nextPage,'cursor'=>$page['cursor']],JSON_THROW_ON_ERROR);
            $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',
                [$this->company,self::CURSOR,$payload,gmdate('c')]);
            return [$nextPage,1,true];
        };
    }

    private function restart():array
    {
        return ['items'=>[],'next'=>true,'totalPages'=>null,'restart'=>true];
    }
}
