<?php
declare(strict_types=1);

require $argv[1] ?? dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/src/IdefixQuestionClient.php';

use Omnigo\{IdefixQuestionClient, InboxFailure, MarketplaceQuestionMapper, QuestionReadTransport, SyncFailure};

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function throws(callable $operation, int $status, bool $uncertain = false): void
{
    try { $operation(); }
    catch (InboxFailure $error) {
        check($error->status === $status && $error->uncertain === $uncertain, 'Gönderim sonucu yanlış sınıflandı.');
        return;
    } catch (SyncFailure $error) {
        check($error->httpStatus === $status && !$uncertain, 'Okuma sonucu yanlış sınıflandı.');
        return;
    }
    throw new RuntimeException('Beklenen hata oluşmadı.');
}

$config = ['vendorId'=>'9', 'apiKey'=>'test-key', 'apiSecret'=>'test-secret'];
$deadline = time() + 50;
$body = 'Örnek yanıt';
$product = ['id'=>12, 'product'=>'Ürün', 'question'=>'Ölçüsü nedir?', 'productQuestionAnswer'=>[],
    'createdAt'=>'2026-11-15T09:00:00+03:00', 'isArchived'=>false];
$order = ['id'=>13, 'orderNumber'=>'IDE-ÖRNEK', 'reason'=>'Kargo', 'createdAt'=>'2026-11-15T09:00:00+03:00',
    'lastMessage'=>'Siparişim nerede?', 'lastMessageDate'=>'2026-11-15T09:10:00+03:00'];
$orderAnswer = [...$order, 'lastMessage'=>$body, 'lastMessageDate'=>'2026-11-15T09:15:00+03:00'];
$state = ['orderAnswered'=>false, 'orderChanges'=>false, 'productResponse'=>'normal', 'postCount'=>0, 'leaseChecks'=>0];
$reads = new QuestionReadTransport(static function (string $url, array $headers) use (&$state, &$product, $order, $orderAnswer): array {
    check(in_array('X-API-KEY: '.base64_encode('test-key:test-secret'), $headers, true), 'idefix mağaza kimliği kullanılmadı.');
    $parts = parse_url($url);
    $item = match ($parts['path']) {
        '/pim/vendor/9/question/12' => $product,
        '/pim/vendor/9/order-question/13' => $state['orderAnswered'] ? $orderAnswer : $order,
        '/pim/vendor/9/order-question/99' => $order,
        '/pim/vendor/9/order-question/filter' => null,
        default => throw new RuntimeException('Beklenmedik GET adresi.'),
    };
    if ($item === null) {
        parse_str($parts['query'], $query);
        check($query['orderNumber'] === $order['orderNumber'], 'Sipariş sorusu araması kendi siparişine bağlı kalmadı.');
        if ($query['status'] === 'unanswered' && !$state['orderAnswered']) {
            $item = $state['orderChanges'] ? [...$order, 'lastMessage'=>'Yeni müşteri mesajı'] : $order;
        } elseif ($query['status'] === 'answered' && $state['orderAnswered']) {
            $item = $orderAnswer;
        }
    }
    return ['status'=>200, 'body'=>json_encode(['items'=>$item === null ? [] : [$item], 'currentPage'=>1,
        'pageCount'=>$item === null ? 0 : 1], JSON_THROW_ON_ERROR)];
});
$writes = static function (string $url, array $headers, string $json) use (&$state, $body): string {
    ++$state['postCount'];
    check($state['leaseChecks'] === $state['postCount'], 'Son gönderim kontrolü çalışmadı.');
    check(in_array('Content-Type: application/json', $headers, true), 'idefix yanıtı JSON olarak hazırlanmadı.');
    $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    if (str_ends_with($url, '/question/12/answer')) {
        check($data === ['answer_body'=>$body], 'Ürün sorusu yanıt gövdesi hatalı.');
        return match ($state['productResponse']) {
            'bad' => '{',
            'wrong' => '{"id":5,"answerBody":"başka"}',
            default => json_encode(['id'=>4, 'answerBody'=>$body, 'bannedWords'=>null], JSON_THROW_ON_ERROR),
        };
    }
    check(str_ends_with($url, '/order-question/13/answer') && $data === ['answer_body'=>$body, 'type'=>1], 'Sipariş sorusu yanıt gövdesi hatalı.');
    if ($state['productResponse'] !== 'delay') $state['orderAnswered'] = true;
    return '';
};

$client = new IdefixQuestionClient($reads, $writes);
$before = static function () use (&$state): void { ++$state['leaseChecks']; };
$productFingerprint = MarketplaceQuestionMapper::idefix($product, 'product')['fingerprint'];
$orderFingerprint = MarketplaceQuestionMapper::idefix($order, 'unanswered')['fingerprint'];
check($client->detail($config, 'product:12', $deadline)['fingerprint'] === $productFingerprint, 'Ürün detay ve liste kimliği farklı.');
check($client->detail($config, 'order:13', $deadline)['fingerprint'] === $orderFingerprint, 'Sipariş detay ve liste kimliği farklı.');
$product['isArchived'] = true;
throws(fn()=>$client->reply($config, 'product:12', $body, $productFingerprint, $deadline, $before), 409);
$product['isArchived'] = false;
$state['orderChanges'] = true;
throws(fn()=>$client->reply($config, 'order:13', $body, $orderFingerprint, $deadline, $before), 409);
$state['orderChanges'] = false;
throws(fn()=>$client->reply($config, 'order:99', $body, $orderFingerprint, $deadline, $before), 422);
throws(fn()=>$client->reply($config, 'product:12', $body, str_repeat('0', 64), $deadline, $before), 409);
check($state['postCount'] === 0, 'Geçersiz işlem pazaryerine cevap göndermemeli.');

check($client->reply($config, 'product:12', $body, $productFingerprint, $deadline, $before) === ['thread_id'=>'product:12', 'accepted'=>true], 'Ürün cevabı doğrulanamadı.');
foreach (['bad', 'wrong'] as $mode) {
    $state['productResponse'] = $mode;
    throws(fn()=>$client->reply($config, 'product:12', $body, $productFingerprint, $deadline, $before), 502, true);
}
$state['productResponse'] = 'delay';
throws(fn()=>$client->reply($config, 'order:13', $body, $orderFingerprint, $deadline, $before), 502, true);
$state['productResponse'] = 'normal';
check($client->reply($config, 'order:13', $body, $orderFingerprint, $deadline, $before) === ['thread_id'=>'order:13', 'accepted'=>true], 'Sipariş cevabı kaynakta doğrulanamadı.');
throws(fn()=>$client->reply($config, 'order:13', $body, $orderFingerprint, $deadline, $before), 409);
check($state['postCount'] === 5 && $state['leaseChecks'] === 5, 'Tek işlemde yinelenen gönderim oluştu.');
echo "idefix ürün/sipariş yanıtı, güncel kaynak kontrolü, gönderim belirsizliği ve tek gönderim doğrulandı.\n";
