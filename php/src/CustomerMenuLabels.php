<?php
declare(strict_types=1);
namespace Omnigo;

final class CustomerMenuLabels
{
    private const GROUPS=['group.management','group.operations','group.finance','group.system'];

    public static function read(Database $db,int $company): array
    {
        $row=$db->one("SELECT JSON_EXTRACT(payload,'$.menuLabels') AS menu_labels FROM company_profiles WHERE company_id=?",[$company]);
        return self::fromJson($row['menu_labels']??null);
    }

    public static function fromJson(?string $value): array
    {
        $configuration=$value!==null?json_decode($value,true,JSON_THROW_ON_ERROR):null;
        $labels=array_filter(array_intersect_key((array)($configuration['labels']??[]),self::allowed()),'is_string');
        ksort($labels);
        return [
            'version'=>(int)($configuration['version']??0),
            'labels'=>(object)$labels,
        ];
    }

    public static function save(Database $db,array $actor,int $company,array $data): array
    {
        Security::admin($actor);
        $labels=$data['labels']??null;
        if($labels instanceof \stdClass)$labels=(array)$labels;
        if(!is_array($labels)||array_diff_key($labels,self::allowed()))Http::fail(400,'Düzenlenecek menüleri kontrol edin.');
        foreach($labels as $key=>$label){
            if(!is_string($label)||preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u',$label)!==0){
                Http::fail(400,'Menü adlarını tek satır düz metin olarak yazın.');
            }
            $title=preg_replace('/^\s+|\s+$/u','',$label);
            if(mb_strlen($title)>48)Http::fail(400,'Menü adı en fazla 48 karakter olabilir.');
            if($title==='')unset($labels[$key]);
            else $labels[$key]=$title;
        }
        ksort($labels);
        return TransactionRetry::run($db,function()use($db,$actor,$company,$data,$labels){
            if(!$db->one('SELECT id FROM companies WHERE id=?'.$db->lock(),[$company]))Http::fail(404,'Mağaza bulunamadı.');
            $profile=$db->one("SELECT JSON_EXTRACT(payload,'$.menuLabels') AS menu_labels FROM company_profiles WHERE company_id=?".$db->lock(),[$company]);
            $current=self::fromJson($profile['menu_labels']??null);
            if(($data['version']??null)!==$current['version'])Http::fail(409,'Menü adları değişti. Pencereyi yeniden açıp kontrol edin.');
            if($labels===(array)$current['labels'])return $current;
            $next=['version'=>$current['version']+1,'labels'=>(object)$labels];
            if($profile){
                $db->run("UPDATE company_profiles SET payload=JSON_SET(payload,'$.menuLabels',JSON_EXTRACT(?,'$')) WHERE company_id=?",
                    [json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$company]);
            }else{
                $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',
                    [$company,json_encode(['menuLabels'=>$next],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            }
            (new CustomerManagement($db))->audit($actor,$company,'Mağaza menü adları güncellendi',$company);
            return $next;
        });
    }

    public static function allowed(): array
    {
        $pages=array_filter(CustomerPermissionCatalog::managementPages(),static fn(array $page): bool=>empty($page['settingsOnly']));
        return $pages+array_fill_keys(['customers','fileCenter',...self::GROUPS],true);
    }
}
