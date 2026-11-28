<?php
declare(strict_types=1);
namespace Omnigo;

/** Only capabilities backed by real application operations belong in this catalogue. */
final class CustomerPermissionCatalog
{
    public const PAGE_PARENTS=[
        'overviewSales'=>'overview','overviewTop'=>'overview','overviewWork'=>'overview',
        'shipmentInvoices'=>'shipments',
    ];
    public const DOCUMENT_PAGES=['invoice'=>'shipmentInvoices'];
    public const ACTIONS=['viewCancellations'=>'İptal bilgilerini görme','viewReturns'=>'İade bilgilerini görme',
        CustomerTableVisibility::CANCELLATIONS=>'İptal kayıtlarını görme',
        CustomerTableVisibility::CANCELLED_STATUS=>'İptal edilen satırı işaretli görme',
        CustomerTableVisibility::RETURNS=>'İade kayıtlarını görme',
        CustomerTableVisibility::TURNOVER=>'İptal sonrası ciroyu görme',
        'selectRows'=>'Toplu satır seçimi','bulkStatus'=>'Toplu durum değiştirme','selectDocuments'=>'Yazdırılacak evrakları seçme','view'=>'Görüntüleme','create'=>'Ekleme','edit'=>'Düzenleme','delete'=>'Silme','status'=>'Durum değiştirme','invoice'=>'Fatura işaretleme','documents'=>'Belge görüntüleme ve indirme','upload'=>'Belge yükleme','export'=>'PDF / Excel dışa aktarma','reply'=>'Yanıt verme','replaceDocuments'=>'Belge değiştirme','deleteDocuments'=>'Belge silme','viewPurchasePrice'=>'Alış fiyatını görme','editPurchasePrice'=>'Alış fiyatını değiştirme','viewMovements'=>'Stok hareketlerini görme','viewSales'=>'Satış detaylarını görme','viewMarketplaceStocks'=>'Pazaryeri stok detaylarını görme','editStock'=>'Stok düzenleme','recordStoreSale'=>'Mağaza satışı ve iadesi kaydetme'];
    public static function pages(): array
    {
        $pages = [
            'visibility'=>['label'=>'İptal / İade görünürlüğü','settingsOnly'=>true,'adminOnly'=>true,'actions'=>['view','viewCancellations','viewReturns']],
            'overview'=>['label'=>'Mağaza Özeti','actions'=>['view']],
            'overviewSales'=>['label'=>'Pazaryeri Satışları','actions'=>['view']],
            'overviewTop'=>['label'=>'En Çok Satan 10','actions'=>['view']],
            'overviewWork'=>['label'=>'İşlem Bekleyenler','actions'=>['view']],
            'channels'=>['label'=>'Pazaryerlerim','legacyOnly'=>true,'actions'=>['view']],
            'orders'=>['label'=>'Siparişler','actions'=>['view','documents','upload','replaceDocuments','deleteDocuments']],
            'fileCenter'=>['label'=>'Dosya Merkezi','actions'=>['view','create','delete','export']],
            'financeProfitability'=>['label'=>'Kârlılık Analizi','actions'=>['view','export']],
            'inventory'=>['label'=>'Stok Yönetimi','actions'=>['view','viewPurchasePrice','editPurchasePrice','viewMovements','viewSales','viewMarketplaceStocks','editStock','recordStoreSale']],
            'analytics'=>['label'=>'Analiz ve raporlar','actions'=>['view','export']],
            'payments'=>['label'=>'Aktif hakedişler','actions'=>['view']],
            'tasks'=>['label'=>'Aktif çalışmalar','actions'=>['view','create','edit']],
            'reports'=>['label'=>'Raporlarım','actions'=>['view','create','edit','export']],
            'support'=>['label'=>'Destek taleplerim','actions'=>['view','create','reply','status']],
            'returns'=>['label'=>'İptal & İade Talepleri','actions'=>['view']],
            'shipments'=>['label'=>'Şehir Dışı Sevkiyat','actions'=>['view','selectRows','bulkStatus','create','edit','delete','status','invoice','documents','upload','replaceDocuments','deleteDocuments','export']],
            'deliveries'=>['label'=>'Şehir Dışı Teslimatlar','actions'=>['view','selectDocuments','export']],
            'cityProducts'=>['label'=>'Şehir Dışı Ürünler','actions'=>['view','export']],
            'shipmentInvoices'=>['label'=>'Faturalar','actions'=>['view','documents','upload','replaceDocuments']],
        ];
        foreach($pages as $page=>&$definition)$definition['actions']=[...$definition['actions'],...CustomerTableVisibility::actions($page)];
        unset($definition);
        return $pages;
    }
    public static function managementPages(): array
    {
        // These pages are listed for administrators without adding writable grants.
        return self::pages()+[
            'fileCenter'=>['label'=>'Dosya Merkezi','adminOnly'=>true,'fixedAudience'=>true],
            'marketplaceStocks'=>['label'=>'Pazaryeri Stokları','adminOnly'=>true,'fixedAudience'=>true,'audienceLabel'=>'Yalnız Omnigo · Pilot kapsamı'],
            'inbox'=>['label'=>'Pazaryeri Soruları','adminOnly'=>true,'fixedAudience'=>true],
            'financeProfitability'=>['label'=>'Kârlılık Analizi','adminOnly'=>true,'fixedAudience'=>true],
            'connections'=>['label'=>'Bağlantı kontrolü','adminOnly'=>true,'fixedAudience'=>true],
        ];
    }
    public static function defaults(): array
    {
        $result=[];
        foreach(self::pages() as $page=>$definition){
            foreach($definition['actions'] as $action)$result[$page][$action]=
                ($action==='view'&&!isset(self::PAGE_PARENTS[$page])&&!in_array($page,['overview','orders','returns','cityProducts','fileCenter','financeProfitability'],true))||
                ($page==='support')||($page==='deliveries'&&in_array($action,['selectDocuments','export'],true))||($page==='inventory'&&in_array($action,['viewPurchasePrice','editPurchasePrice','viewMovements','viewSales','viewMarketplaceStocks'],true))||($action==='export'&&in_array($page,['analytics','reports'],true));
        }
        return $result;
    }
    /** Legacy profiles shared the overview and shipment permissions with these pages. */
    public static function completePages(array $grants,array $explicit,?string $role=null): array
    {
        $overview=!empty(((array)($grants['overview']??[]))['view']);
        $channels=!empty(((array)($grants['channels']??[]))['view']);
        $logistics=$role===CustomerAccountRoles::LOGISTICS;
        $shipment=self::legacyShipmentActions((array)($grants['shipments']??[]));
        $document=!empty($shipment['view'])&&!empty($shipment['documents']);
        $upload=$document&&!empty($shipment['upload']);
        $inherited=[
            'fileCenter'=>array_fill_keys(self::pages()['fileCenter']['actions'],false),
            'financeProfitability'=>array_fill_keys(self::pages()['financeProfitability']['actions'],false),
            'overviewSales'=>['view'=>!$logistics&&($overview||$channels)],
            'overviewTop'=>['view'=>$overview],
            'overviewWork'=>['view'=>!$logistics&&$overview&&$upload],
            'shipmentInvoices'=>['view'=>$document,'documents'=>$document,'upload'=>$upload,
                'replaceDocuments'=>$upload&&!empty($shipment['replaceDocuments'])],
            'cityProducts'=>['view'=>false],
        ];
        unset($grants['shipmentDeliveryDocuments']);
        $grants=array_replace($grants,array_diff_key($inherited,$explicit));
        $productGrants=(array)$grants['cityProducts'];
        if(!array_key_exists('export',(array)($explicit['cityProducts']??[]))){
            $deliveryGrants=(array)($grants['deliveries']??[]);
            $productGrants['export']=!empty($productGrants['view'])&&!empty($deliveryGrants['view'])&&!empty($deliveryGrants['export']);
        }
        $grants['cityProducts']=$productGrants;
        $grants=CustomerTableVisibility::withStatusDefaults($grants,$explicit);
        foreach($grants as $page=>&$actions)$actions=CustomerTableVisibility::complete($page,(array)$actions);
        unset($actions);
        return $grants;
    }
    public static function legacyShipmentActions(array $actions): array
    {
        if(!array_key_exists('selectRows',$actions))$actions['selectRows']=($actions['status']??false)||($actions['export']??false);
        if(!array_key_exists('bulkStatus',$actions))$actions['bulkStatus']=$actions['status']??false;
        if(!($actions['status']??false))$actions['bulkStatus']=false;
        if(!array_key_exists('replaceDocuments',$actions))$actions['replaceDocuments']=$actions['upload']??false;
        if(!array_key_exists('deleteDocuments',$actions))$actions['deleteDocuments']=false;
        return $actions;
    }
    /** Keep existing read/price behavior; stock mutations stay opt-in for clients. */
    public static function legacyInventoryActions(array $actions): array
    {
        $defaults=self::defaults()['inventory'];$result=array_replace($defaults,$actions);
        if(!($result['view']??false))return array_fill_keys(array_keys($defaults),false);
        if(!($result['viewPurchasePrice']??false))$result['editPurchasePrice']=false;
        return $result;
    }
    public static function validateGrants(mixed $value,?string $role=null): array
    {
        if($value instanceof \stdClass)$value=(array)$value;
        if(is_array($value))unset($value['shipmentDeliveryDocuments']);
        if(!is_array($value)||array_diff(array_keys($value),array_keys(self::pages())))Http::fail(400,'Yetki bölümlerini kontrol edin.');
        $value=self::completePages($value,$value,$role);
        $result=[];
        foreach(self::pages() as $page=>$definition){
            $actions=(array)($value[$page]??(in_array($page,['inventory','visibility'],true)?self::defaults()[$page]:[]));
            if($page==='shipments')$actions=self::legacyShipmentActions($actions);
            if($page==='inventory')$actions=self::legacyInventoryActions($actions);
            if($page==='deliveries'&&!array_key_exists('selectDocuments',$actions))$actions['selectDocuments']=$actions['export']??false;
            if(array_diff(array_keys($actions),$definition['actions']))Http::fail(400,'Desteklenmeyen bir işlem yetkisi seçildi.');
            foreach($definition['actions'] as $action){
                if(!is_bool($actions[$action]??null))Http::fail(400,'Her işlem için bir yetki seçin.');
                $result[$page][$action]=$actions[$action];
            }
            if(!$result[$page]['view'])$result[$page]=array_fill_keys($definition['actions'],false);
            if(array_key_exists('documents',$result[$page])&&!$result[$page]['documents'])foreach(['upload','replaceDocuments','deleteDocuments'] as $key)if(isset($result[$page][$key]))$result[$page][$key]=false;
            if($page==='shipments'&&!$result[$page]['selectRows'])$result[$page]['bulkStatus']=false;
            if(isset($result[$page]['replaceDocuments'])&&!$result[$page]['upload'])$result[$page]['replaceDocuments']=false;
        }
        return $result;
    }
}
