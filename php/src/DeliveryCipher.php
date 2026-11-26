<?php
declare(strict_types=1);
namespace Omnigo;
final class DeliveryCipher
{
    private ?string $keyMaterial=null;
    public function __construct(private string $keyPath) {}
    public static function withKey(string $key): self
    {
        if(strlen($key)!==32)throw new \InvalidArgumentException('Şifreleme anahtarı geçersiz.');
        $cipher=new self('');$cipher->keyMaterial=$key;return $cipher;
    }
    private function key(): string
    {
        $key=$this->keyMaterial??(is_file($this->keyPath)?file_get_contents($this->keyPath):'');
        if(strlen($key)!==32)throw new \RuntimeException('Teslimat bilgileri için şifreleme anahtarı hazır değil.');
        return hash_hkdf('sha256',$key,32,'omnigo-delivery-v1');
    }
    public function seal(int $company,string $scope,array $value,int $jsonFlags=0): string
    {
        $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR|$jsonFlags),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$nonce,$tag,$company.':'.$scope);
        if($cipher===false)throw new \RuntimeException('Teslimat bilgileri saklanamadı.');
        return json_encode(['cipher'=>base64_encode($cipher),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag)],JSON_THROW_ON_ERROR|$jsonFlags);
    }
    public function open(int $company,string $scope,string $payload): array
    {
        $v=json_decode($payload,true,16,JSON_THROW_ON_ERROR);
        $plain=openssl_decrypt(base64_decode($v['cipher'],true),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,base64_decode($v['nonce'],true),base64_decode($v['tag'],true),$company.':'.$scope);
        if($plain===false)throw new \RuntimeException('Teslimat kaydı doğrulanamadı.');
        return json_decode($plain,true,32,JSON_THROW_ON_ERROR);
    }
}
