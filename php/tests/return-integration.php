<?php
declare(strict_types=1);
$provider=$argv[1]??'';
if($provider===''){foreach(['n11','idefix','hepsiburada'] as $source){passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($source),$status);if($status!==0)exit($status);}exit;}
if(!in_array($provider,['n11','idefix','hepsiburada'],true))throw new RuntimeException('Pazaryeri gerekli.');
require __DIR__.'/'.['n11'=>'n11-sales','idefix'=>'idefix-orders','hepsiburada'=>'hepsiburada-sales'][$provider].'.php';
$companyId=$provider==='idefix'?1:$company;
$value=json_decode($db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$companyId,'sync_'.$provider])['payload'],true);
if($provider==='hepsiburada')$value=$value['financial'];
if(!isset($value['returnRequests'])||!is_array($value['returnRequests']['items']))throw new RuntimeException('Tam tarama iade listesini yayımlamadı.');
if($provider!=='n11'&&count($value['returnRequests']['items'])!==1)throw new RuntimeException('İade satırı tekilleştirilmedi.');
echo $provider." kuyruğu iade görüntüleme kaydını ayrı ve tekrarsız yayımladı.\n";
