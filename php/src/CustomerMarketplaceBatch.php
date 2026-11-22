<?php
declare(strict_types=1);
namespace Omnigo;

/** Visibility only: an atomic tenant-scoped batch never edits integration jobs. */
final class CustomerMarketplaceBatch
{
    public function __construct(private Database $db) {}
    public function apply(array $actor,int $company,array $data): array
    {
        Security::admin($actor);
        $action=Validation::choice($data['action']??null,['show','hide']);
        $items=$data['items']??null;
        if(!is_array($items)||!array_is_list($items)||!count($items)||count($items)>count(CustomerMarketplaces::PROVIDERS))Http::fail(400,'İşlem yapılacak mağazaları seçin.');
        $seen=[];
        foreach($items as $index=>$item){
            // Http::body preserves nested JSON objects as stdClass.
            if($item instanceof \stdClass)$item=(array)$item;
            if(!is_array($item))Http::fail(400,'Mağaza seçimini kontrol edin.');
            $provider=$item['provider']??null;
            if(!is_string($provider)||!isset(CustomerMarketplaces::PROVIDERS[$provider])||isset($seen[$provider])||!is_int($item['version']??null))Http::fail(400,'Mağaza seçimini kontrol edin.');
            $seen[$provider]=true;
            $items[$index]=$item;
        }
        return TransactionRetry::run($this->db,function()use($actor,$company,$items,$action){
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))Http::fail(404,'Müşteri bulunamadı.');
            $stores=new CustomerMarketplaces($this->db);
            foreach($items as $item)$stores->change($actor,$company,$item['provider'],['action'=>$action,'version'=>$item['version']]);
            return ['items'=>$stores->listing($company),'updated'=>count($items)];
        });
    }
}
