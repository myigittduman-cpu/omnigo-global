<?php
declare(strict_types=1);
namespace Omnigo;

final class PazaramaCredentials
{
    private function __construct(
        public readonly string $sellerId,
        public readonly string $apiKey,
        public readonly string $apiSecret,
    ) {}

    public static function fromArray(array $input): self
    {
        foreach (['sellerId','apiKey','apiSecret'] as $field) {
            if (!is_string($input[$field] ?? null)) throw new \InvalidArgumentException('Pazarama bağlantı alanlarını kontrol edin.');
            $input[$field] = trim($input[$field]);
        }
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $input['sellerId'])) {
            throw new \InvalidArgumentException('Pazarama satıcı numarası geçersiz.');
        }
        foreach (['apiKey','apiSecret'] as $field) {
            if ($input[$field] === '' || strlen($input[$field]) > 2048 || preg_match('/[\x00-\x20\x7f]/', $input[$field])) {
                throw new \InvalidArgumentException('Pazarama API bilgilerini kontrol edin.');
            }
        }
        if (str_contains($input['apiKey'], ':')) throw new \InvalidArgumentException('Pazarama API anahtarı geçersiz.');
        return new self(strtolower($input['sellerId']), $input['apiKey'], $input['apiSecret']);
    }

    public function __debugInfo(): array { return ['sellerId'=>$this->sellerId,'apiKey'=>'[gizli]','apiSecret'=>'[gizli]']; }
}
