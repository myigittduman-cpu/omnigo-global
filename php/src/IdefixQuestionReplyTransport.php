<?php
declare(strict_types=1);
namespace Omnigo;

/** A single answer attempt. An uncertain remote write must never be retried. */
final class IdefixQuestionReplyTransport
{
    public function post(string $url, array $headers, string $json, int $deadline): string
    {
        if (!preg_match('#^https://merchantapi\.idefix\.com/pim/vendor/[A-Za-z0-9%_.~-]+/(?:question|order-question)/[1-9][0-9]{0,19}/answer$#D', $url)) {
            throw new InboxFailure(422);
        }
        $timeout = min(18, $deadline - time());
        if ($timeout < 1) throw new InboxFailure(503);
        $handle = curl_init($url);
        $body = '';
        $retryAfter = 0;
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$json,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>min(6, $timeout),
            CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION=>static function ($curl, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $value = trim(substr($header, 12));
                    $retryAfter = min(604800, ctype_digit($value) ? (int)$value : max(0, (strtotime($value) ?: time()) - time()));
                }
                return strlen($header);
            },
        ]);
        try {
            if (curl_exec($handle) === false) throw new InboxFailure(503, $retryAfter, true);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!in_array($status, [200, 201, 204], true)) {
                $rejected = $status >= 400 && $status < 500 && $status !== 408;
                throw new InboxFailure($status ?: 503, $retryAfter, !$rejected);
            }
            return $body;
        } finally {
            curl_close($handle);
        }
    }
}
