<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,InboxQuestionFilters,InboxQuestionWindow,InboxRepository,MarketplaceQuestionMapper};

function checkWindow(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
}

function question(string $id,string $date,array $extra=[]): array
{
    $message=MarketplaceQuestionMapper::message($id.'-q','Ürün ölçüsü nedir?',$date);
    return MarketplaceQuestionMapper::thread($id,'Ürün',[$message],0,['updated'=>$date,...$extra]);
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("INSERT INTO companies VALUES(1,'Birinci mağaza'),(2,'İkinci mağaza')");
$db->run("INSERT INTO users VALUES(1,'fixture@example.test','Test yöneticisi','hash','admin',NULL)");
$inbox=new InboxRepository($db);
$start=InboxQuestionWindow::START;
$earlier='2026-10-31T20:59:59+00:00';
$extra=['expiresAt'=>gmdate('c',time()+3600),'recipients'=>[['type'=>'CUSTOMER','id'=>'buyer']],
    'sourceStatus'=>'WAITING_FOR_ANSWER'];
foreach (array_keys(InboxQuestionFilters::PROVIDERS) as $provider) {
    checkWindow($inbox->save(1,question('old',$earlier,$extra),$provider)===0,$provider.' eski kayıt atlanmalı');
    $id=$inbox->save(1,question('legacy',$start,$extra),$provider);
    checkWindow($id>0,$provider.' mevcut kayıt hazırlanamadı');
    $db->run('UPDATE inbox_messages SET created=? WHERE thread_id=?',[$earlier,$id]);
    $inbox->save(1,question('current',$start,$extra),$provider);
    $inbox->save(2,question('current',$start,$extra),$provider);
    $page=$inbox->summary(1,company:1,provider:$provider,pageSize:10,table:true);
    checkWindow($page['total']===1&&$page['waitingCount']===1&&$page['items'][0]['created']===$start,
        $provider.' eski kayıt, mağaza ayrımı veya tarih sınırı yanlış');
}
$inbox->save(1,question('expired',$start,[...$extra,'expiresAt'=>gmdate('c',time()-1)]),'hepsiburada');
$inbox->save(1,question('archived',$start,[...$extra,'archived'=>true]),'idefix');
$inbox->save(1,question('uncertain-recipient',$start,[...$extra,'recipients'=>[]]),'koctas');
$page=$inbox->summary(1,company:1,pageSize:10,table:true);
checkWindow($page['total']===6&&$page['waitingCount']===6&&$page['unreadCount']===6,
    'Liste, sayaç ve okunmamış sayısı yalnız geçerli bekleyenleri içermeli');
echo "Altı pazaryerinde İstanbul tarih sınırı, eski kayıt, mağaza ayrımı ve yanıtlanabilir liste doğrulandı.\n";
