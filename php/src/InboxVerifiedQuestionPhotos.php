<?php
declare(strict_types=1);

namespace Omnigo;

/** Private source evidence keyed to a marketplace question inside its own store. */
final class InboxVerifiedQuestionPhotos
{
    private ?array $entries = null;
    private ?string $secret = null;

    public function __construct(private ?string $keyFile = null, private ?string $evidenceFile = null)
    {
    }

    public static function token(string $secret, int $company, string $provider, string $question): string
    {
        if (strlen($secret) !== 32 || $company <= 0 || !isset(InboxQuestionFilters::PROVIDERS[$provider])
            || !preg_match('~^[a-zA-Z0-9][a-zA-Z0-9.:_/-]{0,100}$~D', $question)) {
            return '';
        }
        $key = hash_hkdf('sha256', $secret, 32, 'omnigo-inbox-verified-question-photos:v1');
        return hash_hmac('sha256', json_encode([$company, $provider, $question], JSON_THROW_ON_ERROR), $key);
    }

    public function find(int $company, string $provider, string $question): ?array
    {
        $this->load();
        if ($this->secret === null || !$this->entries) return null;
        $entry = $this->entries[self::token($this->secret, $company, $provider, $question)] ?? null;
        if (!is_array($entry)) return null;
        $file = $entry['file'] ?? null;
        if (!is_string($file) || !preg_match('/^[a-z0-9][a-z0-9-]{2,100}\.webp$/D', $file)) return null;
        $title = DeliveryMapper::text($entry['title'] ?? '', 240);
        if ($title === '') return null;
        return ['title'=>$title, 'imageUrl'=>'/assets/inbox-products/'.$file];
    }

    private function load(): void
    {
        if ($this->entries !== null) return;
        $this->entries = [];
        $root = dirname(__DIR__);
        $keyPath = $this->keyFile ?? $root.'/storage/backup.key';
        $mapPath = $this->evidenceFile ?? $root.'/resources/inbox-verified-question-photos.json';
        if (!is_readable($keyPath) || !is_readable($mapPath)) return;
        $key = @file_get_contents($keyPath, false, null, 0, 33);
        $bytes = @file_get_contents($mapPath, false, null, 0, 131073);
        if (!is_string($key) || strlen($key) !== 32 || !is_string($bytes) || strlen($bytes) > 131072) return;
        $data = json_decode($bytes, true, 32);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['entries'] ?? null)) return;
        $this->secret = $key;
        $this->entries = $data['entries'];
    }
}
