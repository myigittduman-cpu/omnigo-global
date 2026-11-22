<?php
declare(strict_types=1);
namespace Omnigo;

/** Official seller question and reply methods on the same Hepsiburada service. */
final class HepsiburadaQuestionClient
{
    public const PROVIDER = 'hepsiburada';
    public const JOB = 'hepsiburada Sorular';
    public const STATE_KEY = 'hb_questions';
    private const ORIGIN = 'https://api-asktoseller-merchant.hepsiburada.com/api/v1.0/issues';
    private const PAGE_SIZE = 25;
    private const REPLY_MAX = 2000;

    public function __construct(
        private ?QuestionReadTransport $transport = null,
        private ?\Closure $replyTransport = null,
    ) {}

    public function page(array $config,int $page,int $deadline,bool $answered=false,?string $through=null): array
    {
        if ($page < 1 || $page > 1000) throw new SyncFailure(422);
        $credentials = HepsiburadaCredentials::fromArray($config);
        $query = ['page'=>$page,'size'=>self::PAGE_SIZE,'status'=>$answered?2:1];
        if ($through !== null) $query['maxCreatedAt']=$through;
        $data = $this->get($credentials,'?'.http_build_query($query),$deadline);
        if (array_key_exists('data',$data) && $data['data'] === null && ($data['totalItemCount']??null) === 0) {
            $data['data']=[];
        }
        $items = $data['data'] ?? null;
        $pages = $data['totalPageCount'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items)>self::PAGE_SIZE
            || !is_int($pages) || $pages<0 || $pages>1000 || ($data['currentPage']??null)!==$page
            || (!$items && $page<$pages)) throw new SyncFailure(422);

        $threads=[];
        foreach ($items as $item) {
            $thread=HepsiburadaQuestionMapper::thread($item,$credentials->merchantId);
            if (isset($threads[$thread['id']])) throw new SyncFailure(422);
            $threads[$thread['id']]=$thread;
        }
        return ['threads'=>array_values($threads),'complete'=>$page>=max(1,$pages)];
    }

    public function detail(array $config,string $id,int $deadline): array
    {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D',$id)) throw new SyncFailure(422);
        $credentials=HepsiburadaCredentials::fromArray($config);
        $thread=HepsiburadaQuestionMapper::thread($this->get($credentials,'/'.$id,$deadline),$credentials->merchantId);
        if ($thread['id'] !== $id) throw new SyncFailure(422);
        return $thread;
    }

    public function reply(
        array $config,
        string $id,
        string $body,
        string $fingerprint,
        int $deadline,
        ?callable $beforeSend = null,
    ): array {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id) || (int)$id > 2147483647
            || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)
            || !mb_check_encoding($body, 'UTF-8') || trim($body) === ''
            || mb_strlen($body, 'UTF-8') > self::REPLY_MAX) {
            throw new InboxFailure(422);
        }

        try {
            $credentials = HepsiburadaCredentials::fromArray($config);
            $thread = $this->detail($config, $id, $deadline);
        } catch (\InvalidArgumentException) {
            throw new InboxFailure(401);
        } catch (SyncFailure $error) {
            throw new InboxFailure($error->httpStatus, $error->retryAfter);
        }

        $expiresAt = strtotime($thread['expiresAt'] ?? '');
        if (($thread['questionStatus'] ?? null) === 1 && !$thread['partial']) {
            throw new InboxFailure(409, answeredQuestion:$thread);
        }
        if (($thread['questionStatus'] ?? null) !== 0 || $thread['partial'] || $expiresAt === false
            || $expiresAt <= time() || !hash_equals($thread['fingerprint'], $fingerprint)) {
            throw new InboxFailure(409);
        }
        $deadline = min($deadline, $expiresAt);
        if ($deadline <= time()) {
            throw new InboxFailure(409);
        }
        if ($beforeSend !== null) {
            $beforeSend();
        }
        if ($deadline <= time()) {
            throw new InboxFailure(409);
        }

        $url = self::ORIGIN.'/'.$id.'/answer';
        $headers = $this->headers($credentials);
        // A curl array body generates multipart/form-data with the official Answer part.
        $form = ['Answer' => $body];
        try {
            $response = $this->replyTransport
                ? ($this->replyTransport)($url, $headers, $form, $deadline)
                : $this->post($url, $headers, $form, $deadline);
        } catch (InboxFailure $error) {
            throw $error;
        } catch (\Throwable) {
            throw new InboxFailure(503, 0, true);
        }
        $status = (int)($response['status'] ?? 0);
        if ($status !== 201) {
            $definitiveRejection = in_array($status, [400, 401, 403, 404, 410, 422, 429], true);
            throw new InboxFailure($status ?: 503, (int)($response['retryAfter'] ?? 0), !$definitiveRejection);
        }
        $messageId = self::messageId($response['body'] ?? null, $response['contentType'] ?? '');
        return ['thread_id' => $id, 'accepted' => true, 'message_id' => $messageId];
    }

    private function get(HepsiburadaCredentials $credentials,string $path,int $deadline): array
    {
        $body=($this->transport??new QuestionReadTransport())->read(self::ORIGIN.$path,$this->headers($credentials),$deadline);
        try {
            $data=json_decode($body,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new SyncFailure(502);
        }
        if (!is_array($data) || array_is_list($data)) throw new SyncFailure(422);
        return $data;
    }

    private function headers(HepsiburadaCredentials $credentials): array
    {
        return [
            'Accept: application/json',
            'Authorization: Basic '.base64_encode($credentials->username.':'.$credentials->serviceKey),
            'User-Agent: '.$credentials->userAgent,
            'merchantId: '.$credentials->merchantId,
        ];
    }

    private static function messageId(mixed $body, string $contentType): string
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if (!is_string($body) || strlen($body) > 512) {
            throw new InboxFailure(502, 0, true);
        }
        $id = trim($body);
        if (in_array($mediaType, ['application/json', 'text/json'], true)) {
            try {
                $id = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InboxFailure(502, 0, true);
            }
        } elseif ($mediaType !== 'text/plain') {
            throw new InboxFailure(502, 0, true);
        }
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $id)) {
            throw new InboxFailure(502, 0, true);
        }
        return $id;
    }

    private function post(string $url, array $headers, array $form, int $deadline): array
    {
        if ($deadline <= time()) {
            throw new InboxFailure(409);
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new InboxFailure(503);
        }
        $body = '';
        $retryAfter = 0;
        try {
            $configured = curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $form,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => max(1, min(20, $deadline - time())),
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                    if (stripos($header, 'Retry-After:') === 0) {
                        $value = trim(substr($header, 12));
                        $delay = ctype_digit($value) ? (int)$value : max(0, (strtotime($value) ?: time()) - time());
                        $retryAfter = min(86400, $delay);
                    }
                    return strlen($header);
                },
            ]);
            if (!$configured) {
                throw new InboxFailure(503);
            }
            if (curl_exec($curl) === false) {
                throw new InboxFailure(503, $retryAfter, true);
            }
            return [
                'status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'body' => $body,
                'contentType' => (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
                'retryAfter' => $retryAfter,
            ];
        } finally {
            curl_close($curl);
        }
    }
}
