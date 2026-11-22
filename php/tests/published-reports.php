<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$repo=new Repository($db);$one=$repo->createCompany('A');$two=$repo->createCompany('B');
$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Test','x','admin',NULL)");$admin=['id'=>1,'role'=>'admin'];$client=['role'=>'client','company_id'=>$one];
$value=['title'=>'Yayımlanan rapor','month'=>'2026-10','content'=>'<script>test</script>','status'=>'published'];
$published=$repo->save($admin,$one,'reports',$value,null);
$repo->save($admin,$one,'reports',[...$value,'status'=>'draft','title'=>'Gizli taslak'],null);
$repo->save($admin,$two,'reports',[...$value,'title'=>'Diğer şirket'],null);
$repo->save($admin,$one,'reports',[...$value,'month'=>'2026-09'],null);
check(count($repo->dashboard($admin,$one,'2026-10')['reports'])===2,'Yönetici taslak ve yayını görür');
$reports=$repo->dashboard($client,$one,'2026-10')['reports'];check(count($reports)===1&&$reports[0]['id']===$published['id'],'Yalnız ilgili firmanın yayımlanan dönem raporu');
$repo->save($admin,$one,'reports',[...$value,'status'=>'draft','version'=>1],$published['id']);
check($repo->dashboard($client,$one,'2026-10')['reports']===[],'Yayın geri alındığında müşteri görünümünden çıkar');
$repo->save($admin,$one,'reports',[...$value,'content'=>'Güncel içerik','version'=>2],$published['id']);
check($repo->dashboard($client,$one,'2026-10')['reports'][0]['content']==='Güncel içerik','Yeniden yayım güncel içerikle görünür');
echo "Raporlar: yayım/taslak, firma, dönem, güncelleme ve yayını geri alma kontrolleri geçti.\n";
