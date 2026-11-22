<?php
declare(strict_types=1);
namespace Omnigo;

/** Official QuestionAnswer endpoints only. Ambiguous writes are never retried. */
final class PazaramaInboxClient
{
    private string $token;
    public function __construct(private array $config,?PazaramaAuthClient $auth=null,private ?\Closure $transport=null)
    {
        try{
            $credentials=PazaramaCredentials::fromArray($config);
            $token=($auth??new PazaramaAuthClient())->authenticate($credentials,time()+20);
            PazaramaSellerIdentity::verify($token['accessToken'],$credentials->sellerId);$this->token=$token['accessToken'];
        }catch(SyncFailure $e){throw new InboxFailure($e->httpStatus,$e->retryAfter);}
    }
    public function thread(string $id): array
    {
        PazaramaSalesMapper::id($id);
        $result=$this->request('GET','getApprovalAnswerById?questionId='.rawurlencode($id));
        $raw=$result['data']??null;
        if(!is_array($raw)||($raw['questionId']??null)!==$id||strcasecmp((string)($raw['sellerId']??''),$this->config['sellerId'])!==0)throw new InboxFailure(422);
        return PazaramaQuestionMapper::thread($raw);
    }
    public function reply(string $id,string $body,array $recipients=[]): array
    {
        PazaramaSalesMapper::id($id);
        if(mb_strlen($body)<1||mb_strlen($body)>2000)throw new InboxFailure(422);
        $this->request('PUT','sellerAnswer',['questionId'=>$id,'text'=>$body]);
        // The documented acknowledgement is success:true (data may be false).
        return ['thread_id'=>$id,'accepted'=>true];
    }
    private function request(string $method,string $operation,?array $data=null): array
    {
        $write=$method==='PUT';$url='https://isortagimapi.pazarama.com/QuestionAnswer/'.$operation;
        $headers=['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$this->token];
        $body=$data===null?null:json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if($this->transport)$response=($this->transport)($method,$url,$headers,$body);
        else{
            $curl=curl_init($url);$text='';$retry=0;
            curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,
                CURLOPT_WRITEFUNCTION=>static function($h,string $part)use(&$text):int{if(strlen($text)+strlen($part)>2097152)return 0;$text.=$part;return strlen($part);},
                CURLOPT_HEADERFUNCTION=>static function($h,string $line)use(&$retry):int{if(stripos($line,'Retry-After:')===0){$v=trim(substr($line,12));$retry=ctype_digit($v)?min(86400,(int)$v):max(0,(strtotime($v)?:time())-time());}return strlen($line);}
            ]);
            if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
            try{if(curl_exec($curl)===false)throw new InboxFailure(503,$retry,$write);$response=['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$text,'retryAfter'=>$retry];}finally{curl_close($curl);}
        }
        $status=(int)($response['status']??0);
        if($status!==200)throw new InboxFailure($status?:503,(int)($response['retryAfter']??0),$write&&($status===0||$status===408||$status>=500));
        try{$result=json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new InboxFailure(502,0,$write);}
        if(($result['success']??false)!==true)throw new InboxFailure(422,0,$write);
        return $result;
    }
}
