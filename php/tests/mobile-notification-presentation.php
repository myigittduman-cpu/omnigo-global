<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{MobileNotificationPresentation,MobilePushMessage};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$event=['company'=>'Vizyona Beyaz Eşya','company_id'=>1,'provider'=>'hepsiburada','event_type'=>'order','payload'=>json_encode(['buyerName'=>'PRIVATE_BUYER','address'=>'PRIVATE_ADDRESS','price'=>'PRIVATE_PRICE'])];
$message=MobilePushMessage::create([],$event,'test','admin');
check($message['title']==='Omnigo Global Yönetici Portalı','Başlık uygulama adı olmalı.');
check($message['body']==='Vizyona; Hepsiburada’dan yeni bir siparişiniz var.','Firma ve pazaryeri ayrıntısı doğru olmalı.');
check(!str_contains(json_encode($message),'PRIVATE_'),'Siparişe ait özel ayrıntılar gönderilmemeli.');
foreach(['n11'=>'n11’den','idefix'=>'idefix’ten','pazarama'=>'Pazarama’dan','koctas'=>'Koçtaş’tan','pttavm'=>'PTT AVM’den'] as $provider=>$label){
    check(str_contains(MobileNotificationPresentation::create([...$event,'provider'=>$provider],'admin')['body'],$label),'Pazaryeri eki yanlış: '.$provider);
}
$client=MobilePushMessage::create([],[...$event,'id'=>4,'event_type'=>'resolved'],'test','client');
check($client['title']==='Omnigo Global İş Ortağım'&&$client['body']==='Omnigo, destek talebinizi çözüldü olarak kapattı.','Müşteri uygulaması adı ve firma korunmalı.');
check(str_contains($client['url'],'view=support'),'Çözüm bildirimi destek ekranına gitmeli.');
$cancel=MobilePushMessage::create([],[...$event,'event_type'=>'operation','payload'=>'{"events":["cancelled"]}'],'test');
check(str_contains($cancel['body'],'Sipariş iptal edildi')&&!str_contains($cancel['body'],'yeni bir sipariş'),'İptal yeni sipariş olarak görünmemeli.');
$missing=MobileNotificationPresentation::create(['company'=>"\n ",'provider'=>'unknown'],'admin');
check($missing['body']==='Pazaryerinden yeni bir siparişiniz var.','Eksik şirket/pazaryeri için düzgün varsayılan metin olmalı.');
check(mb_strlen(MobileNotificationPresentation::create([...$event,'company'=>str_repeat('İ',500)],'admin')['body'])<=500,'Gövde uzunluğu sınırlı olmalı.');
echo "Uygulama başlığı, firma/pazaryeri, Türkçe, olay türü, yönlendirme ve özel veri sınırı kontrolleri geçti.\n";
