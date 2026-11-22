<?php
declare(strict_types=1);
namespace Omnigo;

final class IdefixQuestionClient
{
    private const ORIGIN = 'https://merchantapi.idefix.com/pim/vendor/';

    public function __construct(
        private ?QuestionReadTransport $transport = null,
        private ?\Closure $replyTransport = null,
    ) {}

    public function page(array $config,string $stream,int $page,int $deadline): array
    {
        $data = $this->rawPage($config, $stream, $page, $deadline);
        return ['threads'=>array_map(fn($raw)=>MarketplaceQuestionMapper::idefix($raw,$stream),$data['items']),'complete'=>$page>=max(1,$data['pageCount'])];
    }

    public function detail(array $config, string $id, int $deadline): array
    {
        [$kind, $remoteId, $route] = self::identity($id);
        $data = $this->get($config, $route, $deadline);
        $items = $data['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) !== 1
            || !is_array($items[0]) || (string)($items[0]['id'] ?? '') !== $remoteId) {
            throw new SyncFailure(422);
        }
        $raw = $items[0];
        if ($kind === 'product') return MarketplaceQuestionMapper::idefix($raw, $kind);

        // The single-order question response has no answer state. Confirm it using
        // the official exact-order filter, and reject mismatched concurrent reads.
        $orderNumber = $raw['orderNumber'] ?? null;
        if (!is_string($orderNumber) || trim($orderNumber) === '' || strlen($orderNumber) > 140) {
            throw new SyncFailure(422);
        }
        foreach (['unanswered', 'answered'] as $stream) {
            $thread = MarketplaceQuestionMapper::idefix($raw, $stream);
            for ($page = 1; ; $page++) {
                $list = $this->rawPage($config, $stream, $page, $deadline, $orderNumber);
                foreach ($list['items'] as $item) {
                    if ((string)($item['id'] ?? '') !== $remoteId) continue;
                    $candidate = MarketplaceQuestionMapper::idefix($item, $stream);
                    if (!hash_equals($thread['fingerprint'], $candidate['fingerprint'])) throw new SyncFailure(409);
                    return $candidate;
                }
                if ($page >= $list['pageCount']) break;
            }
        }
        throw new SyncFailure(409);
    }

    public function reply(array $config, string $id, string $body, string $fingerprint, int $deadline, ?callable $beforeSend = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint) || !mb_check_encoding($body, 'UTF-8')
            || trim($body) === '' || mb_strlen($body, 'UTF-8') > 2000) throw new InboxFailure(422);
        try {
            [$kind, , $route] = self::identity($id);
            $current = $this->detail($config, $id, $deadline);
        } catch (SyncFailure $error) {
            throw new InboxFailure($error->httpStatus, $error->retryAfter);
        }
        if (($current['questionStatus'] ?? null) !== 0 || !empty($current['archived'])
            || !hash_equals($current['fingerprint'], $fingerprint)) throw new InboxFailure(409);

        $payload = $kind === 'product' ? ['answer_body'=>$body] : ['answer_body'=>$body, 'type'=>1];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($beforeSend !== null) $beforeSend();
        if ($deadline <= time()) throw new InboxFailure(503);
        $headers = [...self::headers($config), 'Content-Type: application/json'];
        $url = self::url($config, $route.'/answer');
        $response = $this->replyTransport !== null
            ? ($this->replyTransport)($url, $headers, $json, $deadline)
            : (new IdefixQuestionReplyTransport())->post($url, $headers, $json, $deadline);

        if ($kind === 'product') {
            try { $answer = json_decode($response, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
            catch (\JsonException) { throw new InboxFailure(502, 0, true); }
            if (!ctype_digit((string)($answer['id'] ?? '')) || ($answer['answerBody'] ?? null) !== $body
                || !empty($answer['bannedWords'])) throw new InboxFailure(502, 0, true);
        } else {
            // The order reply contract has no response body. Check actual delivery
            // in the authoritative answered stream before accepting the result.
            try { $answer = $this->detail($config, $id, $deadline); }
            catch (\Throwable) { throw new InboxFailure(502, 0, true); }
            if ($answer['questionStatus'] !== 1 || $answer['messages'][0]['body'] !== $body
                || $answer['updated'] < $current['updated']) throw new InboxFailure(502, 0, true);
        }
        return ['thread_id'=>$id, 'accepted'=>true];
    }

    private function rawPage(array $config, string $stream, int $page, int $deadline, ?string $orderNumber = null): array
    {
        if(!in_array($stream,['product','unanswered','answered'],true)||$page<1||$page>1000)throw new SyncFailure(422);
        $query=['page'=>$page,'limit'=>50,'sort'=>'newest'];if($stream!=='product')$query['status']=$stream;
        if ($orderNumber !== null) $query['orderNumber'] = $orderNumber;
        $route = ($stream === 'product' ? 'question' : 'order-question').'/filter?'.http_build_query($query);
        $data = $this->get($config, $route, $deadline);
        if(!is_array($data['items']??null)||!array_is_list($data['items'])||count($data['items'])>50||!is_int($data['pageCount']??null)||$data['pageCount']<0||$data['pageCount']>1000||(int)($data['currentPage']??0)!==$page)throw new SyncFailure(422);
        return $data;
    }

    private function get(array $config, string $route, int $deadline): array
    {
        $body = ($this->transport ?? new QuestionReadTransport())->read(self::url($config, $route), self::headers($config), $deadline);
        try { $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
        catch (\JsonException) { throw new SyncFailure(502); }
        if (!is_array($data)) throw new SyncFailure(422);
        return $data;
    }

    private static function identity(string $id): array
    {
        if (!preg_match('/^(product|order):([1-9][0-9]{0,19})$/D', $id, $match)) throw new SyncFailure(422);
        $route = $match[1] === 'product' ? 'question' : 'order-question';
        return [$match[1], $match[2], $route.'/'.$match[2]];
    }

    private static function headers(array $config): array
    {
        return ['Accept: application/json', 'X-API-KEY: '.base64_encode($config['apiKey'].':'.$config['apiSecret'])];
    }

    private static function url(array $config, string $route): string
    {
        return self::ORIGIN.rawurlencode($config['vendorId']).'/'.$route;
    }
}
