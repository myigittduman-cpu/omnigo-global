<?php
declare(strict_types=1);
namespace Omnigo;

/** Store-level choices are separate from account visibility and action grants. */
final class CustomerSetupSelection
{
    public const LIVE=['orders','inventory','inbox','returns','payments','shipments','deliveries',
        'cityProducts','shipmentInvoices','fileCenter'];
    public const MANUAL=['payoutEstimates','payoutReceipts','invoiceUpload','deliveryDocumentUpload','shipmentEntry',
        'operationCorrections','storeStock','stockCount','storeSalesReturns','purchaseCost','commissionRates',
        'questionReply','replyTemplates','support','workNotes','manualReports'];

    private const MANUAL_PAGES=[
        'inventory'=>['storeStock','stockCount','storeSalesReturns','purchaseCost'],
        'payments'=>['payoutEstimates','payoutReceipts'],
        'shipments'=>['shipmentEntry','operationCorrections'],
        'shipmentInvoices'=>['invoiceUpload'],
        'inbox'=>['questionReply','replyTemplates'],
        'support'=>['support'],'tasks'=>['workNotes'],'reports'=>['manualReports'],
    ];

    private const MANUAL_ACTIONS=[
        'inventory'=>['editStock'=>['storeStock','stockCount'],'recordStoreSale'=>['storeSalesReturns'],
            'editPurchasePrice'=>['purchaseCost']],
        'shipments'=>['create'=>['shipmentEntry'],'edit'=>['operationCorrections'],'delete'=>['operationCorrections'],
            'status'=>['operationCorrections'],'bulkStatus'=>['operationCorrections'],
            'upload'=>['invoiceUpload'],'replaceDocuments'=>['invoiceUpload'],
            'deleteDocuments'=>['operationCorrections']],
        'orders'=>['upload'=>['invoiceUpload','deliveryDocumentUpload'],'replaceDocuments'=>['invoiceUpload','deliveryDocumentUpload'],
            'deleteDocuments'=>['operationCorrections']],
        'shipmentInvoices'=>['upload'=>['invoiceUpload'],'replaceDocuments'=>['invoiceUpload']],
    ];

    public static function profile(Database $db,int $company): array
    {
        $row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);
        return json_decode($row['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
    }

    /** Read old selections without restarting their existing order imports. */
    public static function stored(array $profile): ?array
    {
        $selection=$profile['setupOptions']??null;
        if($selection===null)return null;
        $live=(array)($selection['live']??[]);
        if(empty($profile['cityProductsConfigured'])&&array_intersect($live,['deliveries','shipmentDeliveryDocuments'])){
            $live[]='cityProducts';
        }
        return ['live'=>array_values(array_intersect(self::LIVE,$live)),
            'manual'=>array_values(array_intersect(self::MANUAL,(array)($selection['manual']??[])))];
    }

    public static function hasLive(Database $db,int $company,string $option): bool
    {
        if(!in_array($option,self::LIVE,true))return false;
        $profile=self::profile($db,$company);
        $selection=$option==='cityProducts'?self::stored($profile):($profile['setupOptions']??null);
        return $selection===null||in_array($option,$selection['live'],true);
    }

    public static function hasManual(Database $db,int $company,string $option): bool
    {
        $selection=self::profile($db,$company)['setupOptions']??null;
        return $selection===null||in_array($option,$selection['manual'],true);
    }

    public static function requireManual(Database $db,int $company,string $option): void
    {
        if(!self::hasManual($db,$company,$option))Http::fail(403,'Bu işlem mağazanın çalışma kapsamında etkin değil.');
    }

    public static function jobEnabled(Database $db,int $company,string $job): bool
    {
        $page=match(true){
            str_ends_with($job,' Sorular')=>'inbox',
            in_array($job,UpcomingPayoutAdapter::JOBS,true)=>'payments',
            $job===TrendyolClaimAdapter::JOB=>'returns',
            default=>null,
        };
        return $page===null||self::hasLive($db,$company,$page);
    }

    public static function catalogEnabled(array $profile): bool
    {
        $selection=$profile['setupOptions']??null;
        return $selection===null?CustomerSectionAudience::enabled($profile['workspacePolicy']['pages']['inventory']??'hidden')
            :(bool)array_intersect($selection['live'],['inventory','fileCenter']);
    }

    public static function pageEnabled(array $selection,string $page): bool
    {
        if($page==='shipmentDeliveryDocuments')return false;
        if(!in_array($page,self::LIVE,true)&&!isset(self::MANUAL_PAGES[$page]))return true;
        if($page==='fileCenter'||in_array($page,$selection['live'],true))return true;
        return (bool)array_intersect(self::MANUAL_PAGES[$page]??[],$selection['manual']);
    }

    public static function restrict(array $selection,array &$pages,array &$grants): void
    {
        if(!self::pageEnabled($selection,'inbox'))$pages['inbox']='hidden';
        foreach($pages as $page=>&$visibility){
            if(!self::pageEnabled($selection,$page)){
                $visibility='hidden';
                if(isset($grants[$page]))$grants[$page]=array_fill_keys(array_keys($grants[$page]),false);
                continue;
            }
            foreach(self::MANUAL_ACTIONS[$page]??[] as $action=>$options){
                if(isset($grants[$page][$action])&&!array_intersect($options,$selection['manual']))$grants[$page][$action]=false;
            }
        }
        unset($visibility);
    }

    public static function validate(mixed $value,?array $previous=null): array
    {
        if($value instanceof \stdClass)$value=(array)$value;
        if(!is_array($value)||array_diff(array_keys($value),['live','manual'])){
            Http::fail(400,'Mağaza çalışma seçeneklerini kontrol edin.');
        }
        $live=self::choices($value['live']??null,[...self::LIVE,'shipmentDeliveryDocuments']);
        $manual=self::choices($value['manual']??null,self::MANUAL);
        if(in_array('shipmentDeliveryDocuments',$live,true)&&!in_array('cityProducts',$live,true)
            &&($previous===null||in_array('cityProducts',$previous['live'],true)))$live[]='cityProducts';
        return ['live'=>array_values(array_intersect(self::LIVE,$live)),
            'manual'=>array_values(array_intersect(self::MANUAL,$manual))];
    }

    private static function choices(mixed $value,array $allowed): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>count($allowed)){
            Http::fail(400,'Mağaza çalışma seçeneklerini listeden seçin.');
        }
        foreach($value as $choice){
            if(!is_string($choice)||!in_array($choice,$allowed,true))Http::fail(400,'Mağaza çalışma seçeneği geçersiz.');
        }
        return array_values(array_intersect($allowed,$value));
    }

    public static function operationalModules(array $selection): array
    {
        $live=$selection['live'];
        $manual=$selection['manual'];
        return [
            'shipments'=>(bool)array_intersect($live,['shipments','deliveries','cityProducts','shipmentInvoices'])
                ||(bool)array_intersect($manual,['shipmentEntry','operationCorrections','invoiceUpload','deliveryDocumentUpload']),
            'deliveries'=>in_array('deliveries',$live,true),
        ];
    }
}
