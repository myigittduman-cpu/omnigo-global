<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{PazaramaCredentials,PazaramaAuthClient,SyncFailure};
function check(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$input=['sellerId'=>'00000000-0000-0000-0000-000000000001','apiKey'=>'test-client','apiSecret'=>'test-secret'];
$credentials=PazaramaCredentials::fromArray($input);
$client=new PazaramaAuthClient(function($url,$headers,$body,$deadline) {
    check($url==='https://isortagimgiris.pazarama.com/connect/token','Sabit yetkilendirme adresi');
    check(in_array('Authorization: Basic '.base64_encode('test-client:test-secret'),$headers,true),'Basic yetkilendirme');
    parse_str($body,$fields);
    check($fields===['grant_type'=>'client_credentials','scope'=>'merchantgatewayapi.fullaccess'],'Belgelenen OAuth alanları');
    return ['status'=>200,'body'=>json_encode(['access_token'=>'test-token','token_type'=>'Bearer','expires_in'=>3600])];
});
$result=$client->authenticate($credentials,time()+30);
check($result['accessToken']==='test-token' && $result['expiresAt']>time()+3500,'Token ve güvenli süre');
$liveShape=['success'=>true,'data'=>['accessToken'=>'live-format-test-token','tokenType'=>'Bearer','expiresIn'=>3600],'message'=>''];
$wrapped=(new PazaramaAuthClient(fn()=>['status'=>200,'body'=>json_encode($liveShape)]))->authenticate($credentials,time()+30);
check($wrapped['accessToken']==='live-format-test-token' && $wrapped['expiresAt']>time()+3500,'Canlı Pazarama zarfı ve camelCase alanları');
foreach ([
    [['status'=>429,'body'=>'','retryAfter'=>7200],429,7200],
    [['status'=>400,'body'=>'{"error":"invalid_client"}'],401,0],
    [['status'=>200,'body'=>'{"access_token":"x","token_type":"Bearer","expires_in":0}'],502,0],
    [['status'=>200,'body'=>'not-json'],502,0],
    [['status'=>503,'body'=>'sensitive-provider-error'],503,0],
    [['status'=>200,'body'=>json_encode(array_replace($liveShape,['success'=>false]))],502,0],
    [['status'=>200,'body'=>'{"success":true,"data":null}'],502,0],
    [['status'=>200,'body'=>'{"success":true,"data":{"accessToken":"x","tokenType":"Basic","expiresIn":3600}}'],502,0],
    [['status'=>200,'body'=>'{"success":true,"data":{"accessToken":"x","tokenType":"Bearer","expiresIn":"3600"}}'],502,0],
    [['status'=>200,'body'=>'{"success":true,"data":{"accessToken":"bad\\r\\nheader","tokenType":"Bearer","expiresIn":3600}}'],502,0],
] as [$response,$status,$delay]) {
    try { (new PazaramaAuthClient(fn()=>$response))->authenticate($credentials,time()+30); throw new RuntimeException('Hata bekleniyordu'); }
    catch (SyncFailure $error) { check($error->httpStatus===$status && $error->retryAfter===$delay,'Hata ve yeniden deneme bilgisi'); check(!str_contains($error->getMessage(),'sensitive'),'Hata ayrıntısı sızıntısı'); }
}
foreach (['sellerId'=>'invalid','apiKey'=>"bad\r\nheader",'apiSecret'=>''] as $key=>$value) {
    try { PazaramaCredentials::fromArray(array_replace($input,[$key=>$value])); throw new RuntimeException('Geçersiz veri kabul edildi'); }
    catch (InvalidArgumentException) {}
}
ob_start();var_dump($credentials);$debug=ob_get_clean();check(!str_contains($debug,'test-secret')&&!str_contains($debug,'test-client'),'Hata ayıklama gizliliği');
echo "Pazarama yetkilendirme: sabit adres, OAuth, süre, hız sınırı, hata gizliliği ve alan kontrolleri geçti.\n";
