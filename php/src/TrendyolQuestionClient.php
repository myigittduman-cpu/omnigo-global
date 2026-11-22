<?php
declare(strict_types=1);
namespace Omnigo;

/** The official seller question and answer API. */
final class TrendyolQuestionClient
{
    public const PROVIDER = 'trendyol';
    public const JOB = 'Trendyol Sorular';
    public const WAITING = 'WAITING_FOR_ANSWER';
    public const APPROVAL_PENDING = 'WAITING_FOR_APPROVE';
    public const ANSWERED = 'ANSWERED';
    public const WINDOW = 14 * 86400000;
    public const MAX_PAGES = 400;
    private const PAGE_SIZE = 50;
    private const ORIGIN = 'https://apigw.trendyol.com/integration/qna/sellers/';

    public function __construct(private ?QuestionReadTransport $transport = null) {}

    public function page(array $config, string $status, int $start, int $end, int $page, int $deadline): array
    {
        if (!in_array($status, [self::WAITING, self::ANSWERED], true) || $page < 0 || $page >= self::MAX_PAGES
            || $start < 0 || $end < $start || $end - $start > self::WINDOW) {
            throw new SyncFailure(422);
        }
        $seller = $this->seller($config);
        $query = http_build_query([
            'supplierId' => $seller,
            'startDate' => $start,
            'endDate' => $end,
            'status' => $status,
            'page' => $page,
            'size' => self::PAGE_SIZE,
            'orderByField' => 'CreatedDate',
            'orderByDirection' => $status === self::ANSWERED ? 'DESC' : 'ASC',
        ]);
        $data = $this->get($config, $seller, '/filter?'.$query, $deadline);
        $items = $data['content'] ?? null;
        $pages = $data['totalPages'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) > self::PAGE_SIZE
            || !is_int($pages) || $pages < 0 || $pages > self::MAX_PAGES || ($data['page'] ?? null) !== $page
            || (!$items && $page+1 < $pages) || ($items && ($pages === 0 || $page >= $pages))) {
            throw new SyncFailure(422);
        }
        $threads = [];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['status'] ?? null) !== $status) throw new SyncFailure(422);
            $thread = TrendyolQuestionMapper::thread($item, $seller);
            if (isset($threads[$thread['id']])) throw new SyncFailure(422);
            $threads[$thread['id']] = $thread;
        }
        return ['threads' => array_values($threads), 'complete' => $page+1 >= $pages];
    }

    public function detail(array $config, string $id, int $deadline): array
    {
        if (!TrendyolQuestionMapper::validId($id)) throw new SyncFailure(422);
        $seller = $this->seller($config);
        $thread = TrendyolQuestionMapper::thread($this->get($config, $seller, '/'.$id, $deadline), $seller);
        if ($thread['id'] !== $id) throw new SyncFailure(422);
        return $thread;
    }

    public static function validReply(string $text): bool
    {
        if (!mb_check_encoding($text, 'UTF-8') || preg_match('/^\s*$/uD', $text) !== 0
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) !== 0) {
            return false;
        }
        $length = mb_strlen($text, 'UTF-8');
        return $length >= 10 && $length <= 2000;
    }

    public function reply(array $config, string $id, string $text, int $deadline, ?callable $beforeSend = null): array
    {
        if (!TrendyolQuestionMapper::validId($id) || strlen($id) > 19
            || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0) || !self::validReply($text)) {
            throw new InboxFailure(422);
        }

        try {
            $seller = $this->seller($config);
            $headers = [...$this->headers($config, $seller), 'Content-Type: application/json'];
        } catch (SyncFailure $error) {
            throw new InboxFailure($error->httpStatus, $error->retryAfter);
        }
        $payload = json_encode(['text' => $text], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($beforeSend !== null) $beforeSend();
        if ($deadline <= time()) throw new InboxFailure(408);

        try {
            ($this->transport ?? new QuestionReadTransport())->read(
                self::ORIGIN.$seller.'/questions/'.$id.'/answers', $headers, $deadline, $payload
            );
        } catch (SyncFailure $error) {
            $status = $error->httpStatus;
            $rejected = in_array($status, [400, 401, 403, 404, 405, 409, 410, 413, 415, 422, 429], true);
            throw new InboxFailure($status ?: 503, $error->retryAfter, !$rejected);
        } catch (\Throwable) {
            throw new InboxFailure(503, 0, true);
        }

        // HTTP 200 acknowledges submission for moderation. Publication is observed separately.
        return ['thread_id' => $id, 'accepted' => true];
    }

    private function seller(array $config): string
    {
        $id = (string)($config['supplierId'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,15}$/D', $id)) throw new SyncFailure(422);
        return $id;
    }

    private function get(array $config, string $seller, string $suffix, int $deadline): array
    {
        $body = ($this->transport ?? new QuestionReadTransport())->read(
            self::ORIGIN.$seller.'/questions'.$suffix, $this->headers($config, $seller), $deadline
        );
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new SyncFailure(502);
        }
        if (!is_array($data) || array_is_list($data)) throw new SyncFailure(422);
        return $data;
    }

    private function headers(array $config, string $seller): array
    {
        foreach (['apiKey', 'apiSecret'] as $field) {
            if (!is_string($config[$field] ?? null) || !preg_match('/^[\x21-\x7e]{1,4096}$/D', $config[$field])) {
                throw new SyncFailure(422);
            }
        }
        return [
            'Accept: application/json',
            'User-Agent: '.$seller.' - OmnigoGlobal',
            'Authorization: Basic '.base64_encode($config['apiKey'].':'.$config['apiSecret']),
        ];
    }
}
