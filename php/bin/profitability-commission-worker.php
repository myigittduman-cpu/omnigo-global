<?php
declare(strict_types=1);

use Omnigo\{Database,IntegrationVault,ProfitabilityCommissionSync};

if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
umask(0077);
$root=dirname(__DIR__);
$lock=fopen($root.'/storage/profitability-commission-worker.lock','c');
if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) exit;

require $root.'/bootstrap.php';
$config=require (getenv('OMNIGO_CONFIG')?:$root.'/config.php');
$db=new Database($config['database']);
$vault=new IntegrationVault($db,$root.'/storage/backup.key');
(new ProfitabilityCommissionSync($db,$vault))->run(time()+48);
