<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit administrator-only view; normal connection status never contains secrets. */
final class IntegrationCredentialView
{
    private const FIELDS = [
        'hepsiburada'=>['merchantId','username','serviceKey','userAgent'],
        'n11'=>['apiKey','apiSecret'],
        'pazarama'=>['sellerId','apiKey','apiSecret'],
        'koctas'=>['shopId','apiKey'],
        'pttavm'=>['apiKey','accessToken'],
        'idefix'=>['vendorId','apiKey','apiSecret'],
        'trendyol'=>['supplierId','apiKey','apiSecret'],
    ];
    public function __construct(private Database $db,private Security $security,private IntegrationVault $vault){}
    public function read(array $user,mixed $company,string $provider): array
    {
        Security::admin($user);
        $company=$this->security->company($user,$company);
        if(!isset(self::FIELDS[$provider]))Http::fail(404,'Pazaryeri bulunamadı.');
        return $this->db->transaction(function()use($company,$provider){
            $row=$this->db->one('SELECT id,version FROM records WHERE company_id=? AND kind=?'.$this->db->lock(),[$company,'integration_'.$provider]);
            if(!$row)Http::fail(404,'Kayıtlı API bilgisi bulunamadı.');
            $config=$this->vault->get($company,$provider)??[];$fields=[];
            foreach(self::FIELDS[$provider] as $key){
                if(isset($config[$key])&&(is_string($config[$key])||is_int($config[$key])))$fields[$key]=(string)$config[$key];
            }
            return ['company'=>$company,'provider'=>$provider,'version'=>(int)$row['version'],'fields'=>$fields];
        });
    }
}
