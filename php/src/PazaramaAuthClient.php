<?php
declare(strict_types=1);
namespace Omnigo;

/** Only obtains an access token. No marketplace write endpoints are exposed. */
final class PazaramaAuthClient
{
    private const TOKEN_URL = 'https://isortagimgiris.pazarama.com/connect/token';

    public function __construct(private ?\Closure $transport = null) {}

    /** Caller must run inside a queued job; SyncFailure carries retry timing to SyncQueue. */
    public function authenticate(PazaramaCredentials $credentials, int $deadline): array
    {
        if ($deadline <= time()) throw new SyncFailure(503);
        $headers = ['Accept: application/json','Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic '.base64_encode($credentials->apiKey.':'.$credentials->apiSecret)];
        $body = http_build_query(['grant_type'=>'client_credentials','scope'=>'merchantgatewayapi.fullaccess']);
        $response = $this->transport
            ? ($this->transport)(self::TOKEN_URL, $headers, $body, $deadline)
            : $this->request($headers, $body, $deadline);
        $status = (int)($response['status'] ?? 0);
        if ($status !== 200) {
            // OAuth invalid-client responses are permanent failures, not retry storms.
            if ($status === 400) {
                $error = json_decode($response['body'] ?? '', true);
                if (($error['error'] ?? '') === 'invalid_client') $status = 401;
            }
            throw new SyncFailure($status ?: 503, max(0,(int)($response['retryAfter'] ?? 0)));
        }
        try { $data = json_decode($response['body'] ?? '', true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new SyncFailure(502); }
        // The live gateway wraps camelCase token fields in {success:true,data:{...}}.
        // Also retain the standard OAuth response used by the older endpoint contract.
        if (is_array($data) && array_key_exists('data', $data)) {
            if (($data['success'] ?? null) !== true || !is_array($data['data'])) throw new SyncFailure(502);
            $tokenData = $data['data'];
            $data = ['access_token'=>$tokenData['accessToken'] ?? null,
                'token_type'=>$tokenData['tokenType'] ?? null,'expires_in'=>$tokenData['expiresIn'] ?? null];
        } elseif (is_array($data) && array_key_exists('success', $data) && $data['success'] !== true) {
            throw new SyncFailure(502);
        }
        if (!is_array($data) || !is_string($data['access_token'] ?? null)
            || !preg_match('/^[\x21-\x7e]{1,16384}$/D', $data['access_token'])
            || strcasecmp((string)($data['token_type'] ?? ''), 'Bearer') !== 0
            || !is_int($data['expires_in'] ?? null) || $data['expires_in'] < 60) throw new SyncFailure(502);
        // A valid token verifies credentials, not ownership of the user-entered seller ID.
        return ['accessToken'=>$data['access_token'],'expiresAt'=>time()+min(3600,$data['expires_in'])-30];
    }

    private function request(array $headers, string $body, int $deadline): array
    {
        if (!function_exists('curl_init')) throw new SyncFailure(503);
        $curl = curl_init(self::TOKEN_URL);
        $response = ''; $retryAfter = 0;
        curl_setopt_array($curl, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,
            CURLOPT_TIMEOUT=>max(1,min(20,$deadline-time())),
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$response): int {
                if (strlen($response)+strlen($chunk)>65536) return 0;
                $response .= $chunk; return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$retryAfter): int {
                if (stripos($line,'Retry-After:') === 0) {
                    $value=trim(substr($line,12));
                    $retryAfter=ctype_digit($value)?min(604800,(int)$value):max(0,min(604800,(strtotime($value)?:time())-time()));
                }
                return strlen($line);
            },
        ]);
        try {
            $ok=curl_exec($curl);
            if ($ok===false) throw new SyncFailure(503,$retryAfter);
            return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$response,'retryAfter'=>$retryAfter];
        } finally { curl_close($curl); }
    }
}
