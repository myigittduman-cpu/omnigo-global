<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{BackupOffsite,BackupFiles};
$folder=sys_get_temp_dir().'/omnigo-offsite-test-'.bin2hex(random_bytes(8)); mkdir($folder,0700);
try {
    $rsa=openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
    $public=openssl_pkey_get_details($rsa)['key']; $key=random_bytes(32);
    $data=random_bytes(2500000); $file=$folder.'/test.ogbackup'; file_put_contents($file,$data);
    $status=['bytes'=>strlen($data),'sha256'=>hash('sha256',$data)];
    $name=BackupOffsite::prepare($file,$status,$key,$public);
    $manifest=json_decode(file_get_contents($folder.'/'.$name),true);
    if (!openssl_private_decrypt(base64_decode($manifest['recovery']['envelope']),$plain,$rsa,OPENSSL_PKCS1_OAEP_PADDING) || $plain!==$key) throw new RuntimeException('Kurtarma anahtarı uyuşmuyor.');
    $combined=''; foreach ($manifest['parts'] as $part) {
        $encoded=file_get_contents($folder.'/'.$part['file']);
        if (strlen($encoded)>1048576) throw new RuntimeException('Aktarım parçası sunucu sınırını aşıyor.');
        $bytes=base64_decode(json_decode($encoded,true)['data'],true);
        if (hash('sha256',$bytes)!==$part['sha256']) throw new RuntimeException('Parça özeti farklı.'); $combined.=$bytes;
    }
    if ($combined!==$data || count($manifest['parts'])!==5) throw new RuntimeException('Kopya eksik.');
    echo "Sunucu dışı aktarım: parça bütünlüğü ve ayrı kurtarma anahtarı geçti.\n";
} finally { BackupFiles::removeStaging($folder); }
