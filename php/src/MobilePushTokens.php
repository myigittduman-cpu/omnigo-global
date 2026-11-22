<?php
declare(strict_types=1);
namespace Omnigo;

final class MobilePushTokens
{
    private array $cache=[];
    public function __construct(private MobilePushHttp $http) {}
    private function sign(array $claims,string $pem,string $algorithm,array $headers=[]): string
    {
        require_once dirname(__DIR__).'/vendor/push/autoload.php';
        $alg=$algorithm==='ES256'?new \Jose\Component\Signature\Algorithm\ES256():new \Jose\Component\Signature\Algorithm\RS256();
        $builder=new \Jose\Component\Signature\JWSBuilder(new \Jose\Component\Core\AlgorithmManager([$alg]));
        $jws=$builder->create()->withPayload(json_encode($claims,JSON_THROW_ON_ERROR))
            ->addSignature(\Jose\Component\KeyManagement\JWKFactory::createFromKey($pem),['alg'=>$algorithm,...$headers])->build();
        return (new \Jose\Component\Signature\Serializer\CompactSerializer())->serialize($jws,0);
    }
    public function apns(array $config): string
    {
        if (!preg_match('/^[A-Z0-9]{10}$/D',$config['teamId']??'') || !preg_match('/^[A-Z0-9]{10}$/D',$config['keyId']??'')) throw new \RuntimeException('Apple bildirim kimliği eksik.');
        $key='apns:'.$config['teamId'].':'.$config['keyId'];
        if (($this->cache[$key]['until']??0)>time()) return $this->cache[$key]['token'];
        $token=$this->sign(['iss'=>$config['teamId'],'iat'=>time()],file_get_contents($config['privateKeyFile']),'ES256',['kid'=>$config['keyId']]);
        $this->cache[$key]=['token'=>$token,'until'=>time()+2400];return $token;
    }
    public function fcm(array $config): string
    {
        $account=json_decode(file_get_contents($config['serviceAccountFile']),true,16,JSON_THROW_ON_ERROR);
        if (!preg_match('/^[a-z][a-z0-9-]{4,62}$/D',$config['projectId']??'') || ($account['project_id']??null)!==$config['projectId']
            || !str_ends_with($account['client_email']??'','.iam.gserviceaccount.com')) throw new \RuntimeException('Firebase hizmet hesabı proje ile eşleşmiyor.');
        $key='fcm:'.$account['client_email'];
        if (($this->cache[$key]['until']??0)>time()) return $this->cache[$key]['token'];
        $jwt=$this->sign(['iss'=>$account['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging',
            'aud'=>'https://oauth2.googleapis.com/token','iat'=>time(),'exp'=>time()+3600],$account['private_key'],'RS256');
        $result=$this->http->post('https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]));
        if ($result['status']!==200 || !is_string($result['body']['access_token']??null)) throw new \RuntimeException('Firebase yetkilendirmesi başarısız.');
        $token=$result['body']['access_token'];
        $this->cache[$key]=['token'=>$token,'until'=>time()+min(3300,max(1,(int)($result['body']['expires_in']??3600)-60))];return $token;
    }
}
