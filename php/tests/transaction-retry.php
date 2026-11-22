<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,TransactionRetry};
$db=new Database(['dsn'=>'sqlite::memory:']); $db->run('CREATE TABLE test(id INT PRIMARY KEY)');
foreach ([5,517,6] as $code) {
    $db->run('DELETE FROM test'); $attempts=0;
    TransactionRetry::run($db,function() use($db,$code,&$attempts) {
        $db->run('INSERT INTO test VALUES(1)'); $attempts++;
        if ($attempts<3) { $error=new PDOException('test'); $error->errorInfo=['HY000',$code,'test']; throw $error; }
    });
    if ($attempts!==3 || (int)$db->one('SELECT COUNT(*) n FROM test')['n']!==1) throw new RuntimeException('İşlem tekrarı veri çoğalttı.');
}
$attempts=0;
try { TransactionRetry::run($db,function() use(&$attempts) { $attempts++; $error=new PDOException('test'); $error->errorInfo=['23000',19,'test']; throw $error; }); }
catch (PDOException) { if ($attempts!==1) throw new RuntimeException('Kalıcı hata tekrarlandı.'); }
$attempts=0;
try { TransactionRetry::run($db,function() use(&$attempts) { $attempts++; $error=new PDOException('test'); $error->errorInfo=['HY000',5,'test']; throw $error; }); }
catch (PDOException) { if ($attempts!==4) throw new RuntimeException('Yeniden deneme sınırı hatalı.'); }
echo "Kilit çakışması: sınırlı tekrar, geri alma ve kalıcı hata ayrımı geçti.\n";
