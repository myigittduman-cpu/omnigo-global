<?php
// Owner-authorized one-time delivery check. CLI only, no portal account secrets.
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/bootstrap.php';
$config=require dirname(__DIR__).'/mail.config.php';
$transport=new \Omnigo\MailTransport($config);
if (!$transport->ready()) exit;
$marker=dirname(__DIR__).'/smtp-test-result.json';
$handle=@fopen($marker,'x');
if (!$handle) exit;
$address='destek@omnigoglobal.com';
$subject='TEST — Omnigo portal e-posta kontrolü';
$body="Bu ileti, talebiniz üzerine Omnigo müşteri portalının e-posta gönderimini kontrol etmek için gönderilmiştir.\nGerçek müşteri talebi değildir. Şifre veya müşteri verisi içermez.\n";
try {
    $sent=$transport->send($address,$subject,$body);
    fwrite($handle,json_encode(['accepted'=>$sent,'checked'=>gmdate('c')]));
} catch (\Throwable $error) { fwrite($handle,json_encode(['accepted'=>false,'checked'=>gmdate('c')])); }
fclose($handle); chmod($marker,0600);
