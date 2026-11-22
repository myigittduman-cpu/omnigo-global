<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{InboxFailure, InboxStatus, QuestionReadTransport, TrendyolQuestionClient, TrendyolQuestionMapper};

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rejects(callable $action, int $status, int $delay = 0, bool $uncertain = false): void
{
    try {
        $action();
    } catch (InboxFailure $error) {
        check($error->status === $status && $error->retryAfter === $delay && $error->uncertain === $uncertain, 'Gönderim hatası güvenli biçimde sınıflandırılmadı.');
        return;
    }
    throw new RuntimeException('Beklenen gönderim hatası alınmadı.');
}

$config = ['enabled' => true, 'supplierId' => '17123', 'apiKey' => 'fixture-key', 'apiSecret' => 'fixture-secret'];
$id = '9223372036854775807';
$text = 'Merhaba, kutu içeriğinde iki ürün bulunuyor.';
$deadline = time()+30;
$expectedUrl = 'https://apigw.trendyol.com/integration/qna/sellers/17123/questions/'.$id;
$requests = [];
$permit = false;
$scenario = ['status' => 200, 'body' => ''];
$question = ['id' => $id, 'creationDate' => 1794733200000, 'status' => 'WAITING_FOR_ANSWER', 'productName' => 'Örnek ürün', 'text' => 'Kutu içeriği nedir?'];
$transport = new QuestionReadTransport(static function ($url, $headers, $until, $body) use (&$requests, &$scenario, &$permit, $question, $expectedUrl, $deadline, $text): array {
    check($until === $deadline, 'İstek zaman sınırını aştı.');
    check(in_array('Authorization: Basic '.base64_encode('fixture-key:fixture-secret'), $headers, true), 'Satıcı kimlik doğrulaması yanlış.');
    check(in_array('User-Agent: 17123 - OmnigoGlobal', $headers, true), 'Satıcı User-Agent alanı yanlış.');
    $requests[] = $url;
    if ($url === $expectedUrl && $body === null) return ['status' => 200, 'body' => json_encode($question, JSON_THROW_ON_ERROR)];
    check($url === $expectedUrl.'/answers' && $permit, 'Yanıt doğru satıcıya ve iş kilidiyle gönderilmedi.');
    check(in_array('Content-Type: application/json', $headers, true), 'Yanıt JSON olarak gönderilmedi.');
    check(json_decode($body, true, 8, JSON_THROW_ON_ERROR) === ['text' => $text], 'Yanıt gövdesi değişti.');
    return $scenario;
});
$client = new TrendyolQuestionClient($transport);
$allow = static function () use (&$permit): void {$permit = true;};

$detail = $client->detail($config, $id, $deadline);
check($detail['id'] === $id && InboxStatus::value($detail) === 'waiting', 'Mevcut soru okuması bozuldu.');
$receipt = $client->reply($config, $id, $text, $deadline, $allow);
check($receipt === ['thread_id' => $id, 'accepted' => true], 'Değerlendirmeye alınan cevap için kabul kaydı yanlış.');
check(count($requests) === 2, 'Yanıt birden fazla kez gönderildi.');

foreach (['', str_repeat('ü', 9), str_repeat('ü', 2001), str_repeat(' ', 10), "\xF0\x28\x8C\x28 geçersiz", "Merhaba\0gizli bilgi"] as $invalid) {
    rejects(fn() => $client->reply($config, $id, $invalid, $deadline, $allow), 422);
}
check(TrendyolQuestionClient::validReply(str_repeat('ü', 10)), 'Alt karakter sınırı hatalı.');
check(TrendyolQuestionClient::validReply(str_repeat('ü', 2000)), 'Üst karakter sınırı hatalı.');
rejects(fn() => $client->reply($config, '9223372036854775808', $text, $deadline, $allow), 422);
rejects(fn() => $client->reply([...$config,'supplierId' => 'other/path'], $id, $text, $deadline), 422);
rejects(fn() => $client->reply([...$config,'apiKey' => "bad\nsecret"], $id, $text, $deadline), 422);
rejects(fn() => $client->reply($config, $id, $text, time()-1), 408);
rejects(fn() => $client->reply($config, $id, $text, $deadline, static function (): void {throw new InboxFailure(409);}), 409);
check(count($requests) === 2, 'Geçersiz istek sağlayıcıya ulaştı.');

$scenario = ['status' => 429, 'body' => '', 'retryAfter' => 120];
rejects(fn() => $client->reply($config, $id, $text, $deadline, $allow), 429, 120);
$scenario = ['status' => 403, 'body' => ''];
rejects(fn() => $client->reply($config, $id, $text, $deadline, $allow), 403);
$scenario = ['status' => 503, 'body' => ''];
rejects(fn() => $client->reply($config, $id, $text, $deadline, $allow), 503, 0, true);
$scenario = ['status' => 200];
rejects(fn() => $client->reply($config, $id, $text, $deadline, $allow), 502, 0, true);

foreach (['WAITING_FOR_APPROVE' => 'approval_pending', 'REJECTED' => 'closed', 'UNANSWERED' => 'closed', 'REPORTED' => 'closed'] as $raw => $status) {
    $mapped = TrendyolQuestionMapper::thread([...$question, 'status' => $raw], '17123');
    check($mapped['sourceStatus'] === $raw && InboxStatus::value($mapped) === $status, 'Trendyol onay durumu farklı.');
}
echo "Trendyol yanıt isteği, yanıt uzunluğu, durumlar, süre ve belirsiz gönderim senaryoları doğrulandı.\n";
