<?php
declare(strict_types=1);
require __DIR__.'/ManualShipmentAudit.php';
function check(bool $v):void{if(!$v)throw new RuntimeException('Denetim karşılaştırma testi başarısız.');}
$a=['id'=>1,'company'=>1,'provider'=>'n11','linked'=>false,'payload'=>['orderedAt'=>'2026-09-01T21:30:00Z','customerName'=>'Örnek İsim','phone'=>'05551112233','province'=>'Ankara','productName'=>'Profilo ABC1234 Bulaşık Makinesi','quantity'=>1,'invoiceCents'=>2500000]];
$b=$a;$b['id']=2;$b['linked']=true;$b['payload']['orderedAt']='2026-09-02T00:30:00+03:00';$b['payload']['customerName']='ornek isim';$b['payload']['phone']='+90 555 111 22 33';
check(ManualShipmentAudit::compare($a,$b)['level']==='strong_candidate');
$other=$b;$other['company']=2;check(ManualShipmentAudit::compare($a,$other)===null);
$other=$b;$other['provider']='Hepsiburada';check(ManualShipmentAudit::compare($a,$other)===null);
$other=$b;$other['payload']['orderedAt']='2026-10-02';check(ManualShipmentAudit::compare($a,$other)===null);
$other=$b;$other['payload']['invoiceCents']=2400000;check(!ManualShipmentAudit::compare($a,$other)['evidence']['amount']);
$other=$b;$other['payload']['quantity']=2;check(ManualShipmentAudit::compare($a,$other)['level']==='candidate');
$other=$b;$other['payload']['customerName']='başka';$other['payload']['phone']='';check(ManualShipmentAudit::compare($a,$other)===null);
$result=ManualShipmentAudit::run([$a,$b],[$b]);check($result['summary']['unique_strong_candidate']===1&&count($result['duplicateCandidates'])===1);
check(!isset($result['rows'][0]['manual']['phone'])&&!isset($result['rows'][0]['manual']['customerName']));
$other=$b;$other['id']=3;$result=ManualShipmentAudit::run([$a],[$b,$other]);check($result['summary']['ambiguous']===1);
$before=serialize([$a,$b]);ManualShipmentAudit::run([$a,$b],[$b]);check($before===serialize([$a,$b]));
echo "11 karşılaştırma ve veri koruma kontrolü geçti.\n";
