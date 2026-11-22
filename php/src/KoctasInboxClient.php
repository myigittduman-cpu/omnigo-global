<?php
declare(strict_types=1);
namespace Omnigo;

/** Messaging has its own narrow write allowlist; the sales client remains GET-only. */
final class KoctasInboxClient
{
    public function __construct(private string $key, private int $shopId) {}
    public function threads(?string $cursor=null,?int $deadline=null): array
    {
        $query=['with_messages'=>'true','limit'=>50,'updated_since'=>InboxQuestionWindow::START];
        if ($cursor!==null) $query['page_token']=$cursor;
        return $this->request('GET','/api/inbox/threads',$query,deadline:$deadline);
    }
    public function thread(string $id): array { return $this->request('GET','/api/inbox/threads/'.self::id($id)); }
    public function reply(string $id,string $body,array $recipients,array $files=[]): array
    {
        return $this->request('POST','/api/inbox/threads/'.self::id($id).'/message',[],['body'=>$body,'to'=>$recipients],$files);
    }
    private static function id(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$id)) throw new InboxFailure(422);
        return rawurlencode($id);
    }
    private function request(string $method,string $path,array $query=[],?array $message=null,array $files=[],?int $deadline=null): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/iD',$this->key) || $this->shopId<1) throw new InboxFailure(401);
        $timeout=$deadline===null?18:min(18,$deadline-time());
        if($timeout<1)throw new InboxFailure(425);
        $write=$method==='POST';
        $curl=curl_init('https://koctas.mirakl.net'.$path.'?'.http_build_query($query+['shop_id'=>$this->shopId],'','&',PHP_QUERY_RFC3986));
        $body=''; $retryAfter=0;
        $headers=['Accept: application/json','Authorization: '.$this->key,'User-Agent: Omnigo-Inbox/0.6.2'];
        $options=[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>min(6,$timeout),CURLOPT_TIMEOUT=>$timeout,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION=>static function($h,string $chunk) use(&$body): int { if(strlen($body)+strlen($chunk)>8388608)return 0; $body.=$chunk;return strlen($chunk); },
            CURLOPT_HEADERFUNCTION=>static function($h,string $header) use(&$retryAfter): int { if(stripos($header,'Retry-After:')===0){$v=trim(substr($header,12));$retryAfter=ctype_digit($v)?(int)$v:max(0,(strtotime($v)?:time())-time());}return strlen($header); }
        ];
        if ($write) {
            $boundary='omnigo-'.bin2hex(random_bytes(16));
            $headers[]='Content-Type: multipart/form-data; boundary='.$boundary;
            $options[CURLOPT_POST]=true;
            $multipart='--'.$boundary."\r\nContent-Disposition: form-data; name=\"message_input\"\r\nContent-Type: application/json\r\n\r\n".json_encode($message,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\r\n";
            if(count($files)>3)throw new InboxFailure(422);
            foreach($files as $index=>$file){$meta=InboxAttachments::validate($file['name'],$file['bytes']);$filename='ek-'.($index+1).'.'.$meta['extension'];$multipart.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"files\"; filename=\"$filename\"\r\nContent-Type: ".$meta['type']."\r\n\r\n".$file['bytes']."\r\n";}
            $options[CURLOPT_POSTFIELDS]=$multipart.'--'.$boundary."--\r\n";
        } else $options[CURLOPT_HTTPGET]=true;
        $options[CURLOPT_HTTPHEADER]=$headers; curl_setopt_array($curl,$options);
        try {
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($ok===false || $status!==($write?201:200)) throw new InboxFailure($status?:503,$retryAfter,$write&&($ok===false||$status===408||$status>=500));
            $result=json_decode($body,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($result)) throw new InboxFailure(502,0,$write);
            if($write&&(!is_string($result['message_id']??null)||!is_string($result['thread_id']??null)))throw new InboxFailure(502,0,true);
            return $result;
        } catch(\JsonException) { throw new InboxFailure(502,0,$write); }
        finally { curl_close($curl); }
    }
}
