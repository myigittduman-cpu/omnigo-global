<?php
declare(strict_types=1);
namespace Omnigo;

/** The selectable views share upstream readers, but their local exclusions are independent. */
final class TableSyncTargets
{
    private const TARGETS=[
        'orders'=>['Siparişler',['orders']],
        'inventory'=>['Stok Yönetimi',['catalog']],
        'inbox'=>['Pazaryeri Soruları',['questions']],
        'returns'=>['İptal ve İade Talepleri',['orders','returns']],
        'payments'=>['Hakedişler ve Yaklaşan Ödemeler',['payouts']],
        'shipments'=>['Şehir Dışı Sevkiyatlar',['orders']],
        'deliveries'=>['Şehir Dışı Teslimatlar',['orders']],
        'cityProducts'=>['Şehir Dışı Ürünler',['orders']],
        'shipmentInvoices'=>['Faturalar',['orders']],
        'fileCenter'=>['Dosya Merkezi',['catalog']],
    ];

    public static function listing(Database $db,int $company,array $keys,?IntegrationVault $vault=null): array
    {
        $selection=CustomerSetupSelection::stored(CustomerSetupSelection::profile($db,$company));
        $targets=[];$connections=[];
        foreach(self::TARGETS as $table=>[$label,$sources]){
            if($selection!==null&&!in_array($table,$selection['live'],true))continue;
            $providers=[];
            foreach(self::keys($table,$keys) as $key){
                [$source,$provider]=explode(':',$key,2);
                $providers[$provider][]=$source;
            }
            $limits=[];
            if(in_array('orders',$sources,true)&&isset($providers['trendyol']))$limits[]='Trendyol en fazla son 89 günlük sipariş penceresini sunar.';
            if(in_array('orders',$sources,true)&&isset($providers['hepsiburada']))$limits[]='Hepsiburada yalnız halen API listelerinde bulunan siparişleri verir.';
            if($table==='inbox')$limits[]='Pazaryerinin hâlâ yanıtlanabilir olarak sunduğu sorular alınır. Koçtaş, kendi gelen kutusu açıldığında okunur.';
            if($table==='payments')$limits[]='Yalnızca desteklenen pazaryerlerinin yayımladığı yaklaşan ödeme tarihleri alınır. Elle girilen tahminler ve ödeme onayları korunur.';
            if($table==='returns')$limits[]='Bazı pazaryerlerinde iptal veya iade yalnızca sipariş durumu olarak alınabilir.';
            if(in_array($table,['deliveries','cityProducts'],true))$limits[]='Bu görünüm şehir ve teslimat kurallarını uygular. Sevkiyatta elle kaldırılmış satırlar korunur.';
            if(in_array('catalog',$sources,true))$limits[]='Pazaryeri güncel katalog sunar. Geçmişte satıştan çıkarılıp artık sunulmayan ürünler eklenmez.';
            if(!$providers)$limits[]='Bu tablo için kullanılabilir bir çevrimiçi bağlantı bulunmuyor.';
            $starts=[];
            if($vault&&(in_array('orders',$sources,true)||$table==='inbox')){
                foreach(array_keys($providers) as $provider){
                    if(!isset($connections[$provider])){
                        $state=$db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);
                        $connections[$provider]=[$vault->get($company,$provider),json_decode($state['payload']??'{}',true,64,JSON_THROW_ON_ERROR)];
                    }
                    [$config,$state]=$connections[$provider];
                    try{$first=TableSyncBoundary::first($db,$company,$provider,$config,$state,time(),$table);}
                    catch(SyncFailure){continue;}
                    if($table==='inbox')$first=max($first,NewRecordBoundary::epoch(InboxQuestionWindow::START));
                    $starts[$provider]=(new \DateTimeImmutable('@'.$first))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m-d');
                }
            }
            $targets[]=['key'=>$table,'label'=>$label,'sources'=>$sources,'providers'=>array_keys($providers),
                'providerSources'=>$providers,'fromDates'=>$starts,'modes'=>$providers?['incremental','full']:[],'limits'=>$limits];
        }
        return $targets;
    }

    public static function keys(string $table,array $available): array
    {
        $types=self::TARGETS[$table][1]??[];
        return array_values(array_filter($available,static fn(string $key):bool=>
            $key!=='questions:koctas'&&in_array(explode(':',$key,2)[0],$types,true)));
    }

    public static function request(array $body): array
    {
        $table=$body['table']??null;$mode=$body['mode']??null;$id=$body['requestId']??null;
        if(array_diff(array_keys($body),['table','mode','requestId'])||!is_string($table)||!isset(self::TARGETS[$table])
            ||!in_array($mode,['incremental','full'],true)||!is_string($id)
            ||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di',$id))
            Http::fail(400,'Tablo, aktarım biçimi veya işlem kimliği geçersiz.');
        return ['table'=>$table,'mode'=>$mode,'requestId'=>strtolower($id)];
    }
}
