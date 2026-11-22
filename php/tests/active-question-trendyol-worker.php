<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,InboxQuestionWindow,InboxRepository,InboxService,InboxWorker,IntegrationVault,ProviderRequestGate,QuestionReadTransport,TrendyolQuestionClient,TrendyolQuestionMapper};

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = new Database(['dsn' => 'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("INSERT INTO companies(id,name) VALUES (1,'Örnek mağaza'),(2,'İkinci örnek mağaza')");
$db->run("INSERT INTO users(id,email,name,password,role) VALUES (1,'admin@example.test','Örnek yönetici','fixture','admin')");
ProviderRequestGate::install($db);

$key = tempnam(sys_get_temp_dir(), 'omnigo-trendyol-reply-');
file_put_contents($key, random_bytes(32));

try {
    $vault = new IntegrationVault($db, $key);
    $config = ['supplierId' => '123456', 'apiKey' => 'fixture-key', 'apiSecret' => 'fixture-secret', 'revision' => 'fixture', 'enabled' => true];
    $vault->save(1, $config, TrendyolQuestionClient::PROVIDER);
    $boundary = strtotime(InboxQuestionWindow::START)*1000;
    $question = ['id'=>101, 'sellerId'=>123456, 'creationDate'=>$boundary, 'status'=>'WAITING_FOR_ANSWER', 'text'=>'Ürün kutusunda neler var?', 'productName'=>'Örnek ürün'];
    $old = [...$question, 'id'=>102, 'creationDate'=>$boundary-1000];
    $remote = [101=>$question, 102=>$old];
    $answer = 'Merhaba, kutuda iki ürün ve kullanım kılavuzu var.';
    $requests = [];
    $transport = new QuestionReadTransport(static function (string $url, array $headers, int $deadline, ?string $body) use (&$requests, &$remote, $answer): array {
        check(preg_match('~^https://apigw\.trendyol\.com/integration/qna/sellers/123456/questions/(101|102)(/answers)?$~D', $url, $path) === 1,
            'İstek farklı mağazaya veya soru uç noktasına yönlendi.');
        check(in_array('Authorization: Basic '.base64_encode('fixture-key:fixture-secret'), $headers, true), 'İstek mağaza yetkisini korumadı.');
        $id = (int)$path[1];
        if ($body === null) {
            check(empty($path[2]), 'Yanıt uç noktasına okuma isteği gönderildi.');
            $requests[] = ['GET', $id];
            return ['status'=>200, 'body'=>json_encode($remote[$id], JSON_THROW_ON_ERROR)];
        }
        check(($path[2] ?? '') === '/answers' && $id === 101, 'Geçersiz bir soruya yanıt gönderildi.');
        check(json_decode($body, true, 8, JSON_THROW_ON_ERROR) === ['text'=>$answer], 'Yanıt içeriği değişti.');
        $requests[] = ['POST', $id];
        return ['status'=>200, 'body'=>''];
    });
    $client = new TrendyolQuestionClient($transport);
    $worker = new InboxWorker($db, $vault, static fn() => $client);
    $repository = new InboxRepository($db);
    $queue = new InboxService($db);
    $state = static fn(string $id): string => $db->one('SELECT state FROM inbox_jobs WHERE id=?',[$id])['state'];
    $posts = static function () use (&$requests): int {
        return count(array_filter($requests, static fn(array $request): bool => $request[0] === 'POST'));
    };
    $payload = static fn(array $snapshot): array => ['body'=>$answer,'fingerprint'=>$snapshot['fingerprint'],'recipients'=>$snapshot['recipients']];

    $currentThread = TrendyolQuestionMapper::thread($question, '123456');
    $oldThread = TrendyolQuestionMapper::thread($old, '123456');
    $currentId = $repository->save(1, $currentThread, 'trendyol');
    check($currentId > 0 && $repository->detail($currentId)['canReply'], 'Kasım sınırındaki aktif soru yanıtlanabilir olmalı.');
    check($repository->save(1, $oldThread, 'trendyol') === 0, 'Kasım öncesindeki eski soru içe aktarıldı.');

    // An older release could already have stored this question. The worker must still fence it.
    $db->run('INSERT INTO inbox_threads(company_id,provider,remote_id,payload,updated) VALUES(?,?,?,?,?)',
        [1,'trendyol',$oldThread['id'],'{}',$oldThread['updated']]);
    $oldId = $repository->save(1, $oldThread, 'trendyol');
    check(!$repository->detail($oldId)['canReply'] && $repository->waitingCount(1,'trendyol') === 1, 'Eski kayıt yanıtlanabilir veya bekliyor görünüyor.');
    $oldJob = $queue->enqueue(1, 1, 'reply', $payload($oldThread), $oldId, str_repeat('b',48));
    check($worker->runOne() && $state($oldJob) === 'failed' && $posts() === 0, 'Kuyruk eski soruya gönderimi engellemedi.');

    $requestId = str_repeat('a',48);
    $reply = $queue->enqueue(1, 1, 'reply', $payload($currentThread), $currentId, $requestId);
    check($worker->runOne() && $state($reply) === 'accepted' && $posts() === 1, 'HTTP 200 sonrası tek kabul kaydı bekleniyordu.');
    $pending = $repository->detail($currentId);
    check($pending['status'] === 'approval_pending' && $pending['replyState'] === 'accepted' && !$pending['canReply'], 'Alınan yanıt yayınlanmış veya yeniden yanıtlanabilir görünüyor.');
    check(count($pending['messages']) === 1 && $repository->waitingCount(1,'trendyol') === 0, 'Yapay yanıt eklendi veya bekleyen soru sayısı düşmedi.');
    check($queue->enqueue(1, 1, 'reply', $payload($currentThread), $currentId, $requestId) === $reply
        && !$worker->runOne() && $posts() === 1, 'Tekrarlanan istem aynı yanıtı yeniden yazdı.');

    $remote[101] = [...$question,'status'=>'WAITING_FOR_APPROVE','lastModifiedDate'=>time()*1000+1000];
    $sourcePending = $client->detail($config, '101', time()+30);
    check($sourcePending['questionStatus'] === 2 && $sourcePending['sourceStatus'] === 'WAITING_FOR_APPROVE', 'Kaynak değerlendirme durumu eşlenemedi.');

    $remote[101] = [...$question,'status'=>'ANSWERED','lastModifiedDate'=>time()*1000+3000,
        'answer'=>['id'=>301,'text'=>$answer,'creationDate'=>time()*1000+2000]];
    $refresh = $queue->enqueue(1, 1, 'detail', [], $currentId);
    check($worker->runOne() && $state($refresh) === 'done', 'Yayınlanan yanıt kaynaktan okunamadı.');
    $approved = $repository->detail($currentId);
    check($approved['status'] === 'answered' && $approved['data']['sourceStatus'] === 'ANSWERED' && !$approved['canReply'], 'Onaylanan soru yanlış durumda görünüyor.');
    check(count($approved['messages']) === 2 && $approved['messages'][1]['body'] === $answer && $posts() === 1, 'Onaydan sonra yanıt metni çoğaldı veya yeniden gönderildi.');
    check($repository->waitingCount(2,'trendyol') === 0, 'Soru veya yanıt farklı mağazaya taşındı.');

    echo "Trendyol yanıt kuyruğu, Kasım sınırı, tek gönderim, sağlayıcı onayı ve mağaza ayrımı doğrulandı.\n";
} finally {
    unlink($key);
}
