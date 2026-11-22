<?php
declare(strict_types=1);
namespace Omnigo;

/** Runs inside the leased PTT job. At most two detail reads; no financial/fulfillment writes. */
final class PttavmPricingWorker
{
    public function __construct(private Database $db,private IntegrationVault $vault,private MarketplaceReadClient $reader){}
    public function run(int $company,array $config,int $deadline): void
    {
        $json=fn($key)=>$this->db->mysql()?"JSON_UNQUOTE(JSON_EXTRACT(payload,'$.$key'))":"JSON_EXTRACT(payload,'$.$key')";
        $integer=$this->db->mysql()?'SIGNED':'INTEGER';
        $rows=$this->db->all("SELECT id,payload FROM recent_orders WHERE company_id=? AND provider='pttavm' AND ".$json('pricingDetailVerified')." IS NULL AND CAST(COALESCE(".$json('pricingDetailRetryAt').",0) AS $integer)<=CAST(? AS $integer) ORDER BY ".$json('createdAt')." DESC,id DESC LIMIT 2",[$company,time()]);
        foreach($rows as $row){
            if($deadline-time()<3)return;
            $before=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$after=$before;
            try{
                $detail=$this->reader->pttavmDetail($config,$before['externalId'],$deadline);
                $after=PttavmCouponDetail::apply($before,$detail);
            }catch(\Throwable $error){
                $after=PttavmDetailRetry::defer($before,$error,time());
            }
            $this->db->transaction(function()use($company,$config,$row,$after){
                $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                $fresh=$this->vault->get($company,'pttavm');
                if(empty($fresh['enabled'])||($fresh['revision']??null)!==($config['revision']??null))return;
                // Compare-and-swap prevents overwriting any newer order/status or another tenant.
                $this->db->run("UPDATE recent_orders SET payload=? WHERE id=? AND company_id=? AND provider='pttavm' AND payload=?",[json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$row['id'],$company,$row['payload']]);
            });
        }
    }
}
