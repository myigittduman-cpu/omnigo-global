<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,BackupArchive};
function check(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function fresh(): Database { $db=new Database(['dsn'=>'sqlite::memory:']); $db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql')); return $db; }
function rejects(callable $action): void { try { $action(); } catch (Throwable) { return; } throw new RuntimeException('Beklenen güvenlik reddi yok.'); }
$source=fresh(); $key=random_bytes(32);
$source->run("INSERT INTO companies VALUES(1,'Örnek müşteri')");
$source->run("INSERT INTO users VALUES(1,'test@example.test','Yönetici','test-hash','admin',NULL)");
$source->run("INSERT INTO records VALUES(1,1,'sales',?,1,'2026-10-05')",['{"amount":12345.67,"note":"İade düşüldü"}']);
$source->run("INSERT INTO company_profiles VALUES(1,?, ?,1)",['{"notes":"Özel not"}','data:image/png;base64,example']);
$source->run("INSERT INTO sessions VALUES('old-session',1,'old-csrf',9999999999)");
$source->run("INSERT INTO password_resets VALUES('old-reset',1,9999999999)");
$source->run("INSERT INTO jobs(id,company_id,provider,state,next_run,lease_token,lease_until) VALUES(1,1,'n11','running',0,'old-lease',9999999999)");
$archive=BackupArchive::create($source,$key);
check(!str_contains($archive,'test@example.test') && !str_contains($archive,'test-hash'),'Yedek açık veri içeriyor');
$target=fresh(); BackupArchive::restore($target,$archive,$key);
foreach(['companies','users','records','company_profiles'] as $table) check($source->all("SELECT * FROM $table")===$target->all("SELECT * FROM $table"),'Veri kaybı: '.$table);
check(!$target->all('SELECT * FROM sessions') && !$target->all('SELECT * FROM password_resets'),'Eski erişimler geri geldi');
check($target->one('SELECT state FROM jobs')['state']==='blocked','İşler otomatik başlamamalı');
rejects(fn()=>BackupArchive::restore($target,$archive,$key));
check($target->one('SELECT COUNT(*) AS n FROM companies')['n']===1,'Dolu hedef değiştirildi');
rejects(fn()=>BackupArchive::read($archive,random_bytes(32)));
$broken=$archive; $broken[strlen($broken)-1]=chr(ord($broken[strlen($broken)-1])^1);
rejects(fn()=>BackupArchive::read($broken,$key));
rejects(fn()=>BackupArchive::read(substr($archive,0,-5),$key));
$source->run("INSERT INTO users VALUES(2,'other@example.test','Diğer','hash','client',1)");
$source->run("INSERT INTO users VALUES(3,'third@example.test','Üçüncü','hash','client',1)");
$incompatible=fresh(); $incompatible->pdo->exec("CREATE TRIGGER reject_third BEFORE INSERT ON users WHEN NEW.id=3 BEGIN SELECT RAISE(ABORT,'test'); END;");
rejects(fn()=>BackupArchive::restore($incompatible,BackupArchive::create($source,$key),$key));
check(!$incompatible->all('SELECT * FROM companies') && !$incompatible->all('SELECT * FROM users'),'Yarım geri yükleme geri alınmadı');
check((int)$incompatible->one('SELECT id FROM install_state')['id']===1,'İşlem geri alınmadı');
$source->pdo->exec('CREATE TABLE unsupported(id INT)');
rejects(fn()=>BackupArchive::create($source,$key));
echo "Yedek testleri geçti: veri/logo doğruluğu, şifreleme, yanlış anahtar, bozulma, eksik dosya, dolu hedef koruması, işlem geri alma, şema uyumu ve oturum iptali.\n";
