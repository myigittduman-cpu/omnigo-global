<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Omnigo\{ShipmentImportParser,ShipmentImportFormat};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function tsv(array $row):string{$f=fopen('php://temp','w+');fputcsv($f,$row,"\t",'"','');rewind($f);$text=stream_get_contents($f);fclose($f);return $text;}
$hb=['10 Eki 2026, 15:19','Müşteri','Alıcı','05551234567','Teslimat','Ankara','Sincan','Ürün','1.234,56','2','Fatura adresi','test@example.com','Fatura adı','Kurumsal'];
$h=ShipmentImportParser::parse(tsv($hb),'2026-10')[0]['payload'];check($h['orderedAt']==='2026-10-10T15:19:00+03:00'&&$h['packageStatus']===''&&$h['invoiceCents']===123456,'14 sütun ve Türkçe tarih işlenmeli.');
$blankContact=$hb;$blankContact[2]='';$blankContact[3]='';
$historical=ShipmentImportParser::parse(tsv($blankContact),'2026-10')[0]['payload'];
check($historical['recipientName']===''&&$historical['phone']===''&&$historical['customerName']==='Müşteri','Eski Hepsiburada kayıtlarında eksik alıcı ve telefon boş korunmalı.');
$blankContact[8]='';check(ShipmentImportParser::parse(tsv($blankContact),'2026-10')[0]['payload']['invoiceCents']===null,'Kaynakta eksik tutar sıfır olarak kaydedilmemeli.');
$blankContact[4]='';check(ShipmentImportParser::parse(tsv($blankContact),'2026-10')[0]['payload']['deliveryAddress']==='','Eksik teslimat adresi fatura adresinden uydurulmamalı.');
$blankContact[7]='';check(ShipmentImportParser::parse(tsv($blankContact),'2026-10')[0]['payload']['productName']==='','Kaynakta bulunmayan ürün adı boş kalmalı.');
$blankContact[1]='';try{ShipmentImportParser::parse(tsv($blankContact),'2026-10');throw new RuntimeException('Temel alanlar eksik kabul edildi.');}catch(InvalidArgumentException){}
$n=['30 Eyl 2026, 16:31','Ürün','1','2.345,67','Müşteri','05551234567','test@example.com',"Teslimat\nKat 2",'Gölbaşı','Ankara','Firma Ltd.','Fatura adresi','Gölbaşı','Ankara','Kurumsal','','0012345678','Alıcı','Örnek V.D.'];
$payload=ShipmentImportParser::parse(tsv($n),'2026-09','n11')[0]['payload'];
check($payload['customerName']==='Müşteri'&&$payload['recipientName']==='Alıcı'&&$payload['invoiceName']==='Firma Ltd.','Üç isim alanı ayrı eşleşmeli.');
check($payload['invoiceAddress']==="Fatura adresi\nV.D.: Örnek V.D."&&$payload['taxNumber']==='0012345678','Vergi bilgisi metin olarak kayıpsız korunmalı.');
$n[17]='';check(ShipmentImportParser::parse(tsv($n),'2026-09','n11')[0]['payload']['recipientName']==='','Eksik alıcı adı uydurulmamalı.');
$n[14]='Bireysel';check(!isset(ShipmentImportParser::parse(tsv($n),'2026-09','n11')[0]['payload']['taxNumber']),'Bireyselde vergi bilgisi eklenmemeli.');
$legacy=array_slice($n,0,15);$legacy[0]='29-Haz-2026 23:07';$legacy[14]='Kurumsal';$legacy[]='Örnek / 0012345678';$legacy[]='Alıcı';
$old=ShipmentImportParser::parse(tsv($legacy),'2026-06','n11')[0]['payload'];
check($old['recipientName']==='Alıcı'&&$old['taxNumber']==='0012345678'&&str_ends_with($old['invoiceAddress'],'V.D.: Örnek'),'17 sütunlu N11 vergi ve alıcı alanları ayrı korunmalı.');
check(ShipmentImportFormat::date('16 Tem Per 2026 15:40:31','2026-07',1)->format('c')==='2026-07-16T15:40:31+03:00','Gün adı içeren Türkçe tarih doğru işlenmeli.');
$ptt=[...$hb,'1234567890 / Yenimahalle'];$ptt[0]='08 Ekim 2026 16:46:34';
$p=ShipmentImportParser::parse(tsv($ptt),'2026-10','PTT AVM')[0]['payload'];check($p['orderedAt']==='2026-10-08T16:46:34+03:00'&&$p['taxNumber']==='1234567890'&&str_ends_with($p['invoiceAddress'],'V.D.: Yenimahalle'),'PttAVM saniye ve ters vergi sırası korunmalı.');
$ptt[14]='Yenimahalle / 1234567890';check(ShipmentImportParser::parse(tsv($ptt),'2026-10','PTT AVM')[0]['payload']===$p,'İki vergi sırası aynı sonucu vermeli.');
$ptt[14]='1234567890';check(!str_contains(ShipmentImportParser::parse(tsv($ptt),'2026-10','PTT AVM')[0]['payload']['invoiceAddress'],'V.D.'),'Eksik vergi dairesi uydurulmamalı.');
check(ShipmentImportFormat::date('10/10/2026 16:28','2026-10',1)->format('c')==='2026-10-10T16:28:00+03:00','Eğik çizgili tarih doğru olmalı.');
check(ShipmentImportFormat::date('04 Eylül 2026 2026 23:59:25','2026-09',1)->format('c')==='2026-09-04T23:59:25+03:00','Aynı yılın tekrarı normalleştirilmeli.');
$idefix=['08.10.2026 - 18:42','Müşteri','test@example.com','05551234567','Ürün','1','12865,5','Alıcı','Teslimat adresi','Ankara','Sincan','Fatura adresi','Firma','0012345678 / Örnek','kurumsal'];
$i=ShipmentImportParser::parse(tsv($idefix),'2026-10','idefix')[0]['payload'];check($i['invoiceCents']===1286550&&$i['customerType']==='Kurumsal'&&$i['invoiceName']==='Firma'&&$i['recipientName']==='Alıcı','İdefix alanları ve tek kuruş basamağı işlenmeli.');
$idefix[6]='21762';check(ShipmentImportParser::parse(tsv($idefix),'2026-10','idefix')[0]['payload']['invoiceCents']===2176200,'Tam lira tutarı kuruşa dönüştürülmeli.');
$koctas=['08.10.2026 17:11','Müşteri','05551234567','Ürün','2','25.790,00','Alıcı','Teslimat adresi','Ankara','Sincan','Fatura adresi','Firma','0012345678 / Örnek','Kurumsal'];
$k=ShipmentImportParser::parse(tsv($koctas),'2026-10','Koçtaş')[0]['payload'];check($k['recipientEmail']===''&&$k['invoiceCents']===2579000&&$k['quantity']===2&&$k['taxNumber']==='0012345678','Koçtaş tutarı adetle çarpılmadan korunmalı; e-posta uydurulmamalı.');
$pazarama=['8 Ekim 2026 17:05','Ürün','1','20.900,00','Müşteri','05551234567','test@example.com','Teslimat adresi','Ankara','Sincan','Alıcı Fatura','Fatura adresi','Sincan','Ankara','Kurumsal','0012345678 / Örnek'];
$z=ShipmentImportParser::parse(tsv($pazarama),'2026-10','Pazarama')[0]['payload'];check($z['customerName']==='Müşteri'&&$z['recipientName']==='Alıcı Fatura'&&$z['invoiceName']==='Alıcı Fatura','Onaylanan Pazarama isim eşleştirmesi korunmalı.');
$pazarama[2]='1,00';check(ShipmentImportParser::parse(tsv($pazarama),'2026-10','Pazarama')[0]['payload']['quantity']===1,'Tam sayı olan ondalıklı adet işlenmeli.');
$pazarama[2]='1,50';try{ShipmentImportParser::parse(tsv($pazarama),'2026-10','Pazarama');throw new RuntimeException('Kesirli adet kabul edildi.');}catch(InvalidArgumentException){}
foreach(['31 Eyl 2026, 16:31','30 Eyl 2026, 24:00','01 Eki 2026, 12:00','30 Eyl 2026, 16:31:99'] as $value){try{ShipmentImportFormat::date($value,'2026-09',1);throw new RuntimeException('Geçersiz tarih kabul edildi.');}catch(InvalidArgumentException){}}
echo "Pazaryeri sütunları, boş alanlar, vergi bilgileri, Türkçe/eğik çizgili/saniyeli tarihler ve geçersiz tarihler doğrulandı.\n";
