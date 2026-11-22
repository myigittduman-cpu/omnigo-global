<?php
declare(strict_types=1);
namespace Omnigo;

/** Public, credential-free event contract shared by feed, native push and report dialog. */
final class IntegrationImportPresentation
{
    public const PROVIDERS=['hepsiburada'=>'Hepsiburada','n11'=>'n11','idefix'=>'idefix','pazarama'=>'Pazarama','pttavm'=>'PTT AVM','koctas'=>'Koçtaş','trendyol'=>'Trendyol'];
    public const EVENTS=['marketplace_import_started','marketplace_import_completed','marketplace_import_failed'];
    public static function isEvent(array $detail):bool{return (bool)array_intersect(self::EVENTS,$detail['events']??[]);}
    public static function presentation(array $detail):array
    {
        $report=$detail['importReport']??[];$label=self::PROVIDERS[$report['provider']??'']??'Pazaryeri';
        if(in_array('marketplace_import_started',$detail['events']??[],true))return ['title'=>$label.' aktarımı başlatıldı','body'=>'Aktarım sıraya alındı.','view'=>'inventory'];
        $failed=in_array('marketplace_import_failed',$detail['events']??[],true);
        return ['title'=>$label.($failed?' aktarımı tamamlanamadı':' aktarımı tamamlandı'),'body'=>$failed?'Bağlantı veya aktarım hatasını kısa rapordan inceleyin.':'Aktarım sonucunun kısa raporunu inceleyebilirsiniz.','view'=>'inventory'];
    }
}
