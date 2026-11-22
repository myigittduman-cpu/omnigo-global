<?php
declare(strict_types=1);
namespace Omnigo;

/** Credentials never leave the backend; the encrypted envelope is included in data backups. */
final class IntegrationVault
{
    public function __construct(private Database $db, private string $keyPath, private ?\Closure $beforeSave=null) {}
    private function key(): string
    {
        $key = is_file($this->keyPath) ? file_get_contents($this->keyPath) : '';
        if (strlen($key)!==32) throw new \RuntimeException('Güvenli anahtar deposu hazır değil.');
        return hash_hkdf('sha256',$key,32,'omnigo-marketplace-v1');
    }
    private function provider(string $provider): string
    {
        if (!in_array($provider,['koctas','pazarama','n11','hepsiburada','pttavm','idefix','trendyol'],true)) throw new \InvalidArgumentException('Pazaryeri geçersiz.');
        return $provider;
    }
    public function get(int $company,string $provider='koctas'): ?array
    {
        $this->provider($provider);
        $row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
        if (!$row) return null;
        $data=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
        $plain=openssl_decrypt(base64_decode($data['cipher'],true),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,base64_decode($data['nonce'],true),base64_decode($data['tag'],true),$provider.':'.$company);
        if ($plain===false) throw new \RuntimeException('Bağlantı anahtarı doğrulanamadı.');
        return json_decode($plain,true,16,JSON_THROW_ON_ERROR);
    }
    public function save(int $company,array $config,string $provider='koctas',?\Closure $afterSave=null): void
    {
        $this->provider($provider);
        $nonce=random_bytes(12); $tag='';
        $cipher=openssl_encrypt(json_encode($config,JSON_THROW_ON_ERROR),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$nonce,$tag,$provider.':'.$company);
        if ($cipher===false) throw new \RuntimeException('Anahtar saklanamadı.');
        $payload=json_encode(['cipher'=>base64_encode($cipher),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag)],JSON_THROW_ON_ERROR);
        $this->db->transaction(function() use($company,$payload,$provider,$afterSave,$config) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if ($this->beforeSave) ($this->beforeSave)($company,$provider);
            $row=$this->db->one('SELECT id,version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
            if ($row) $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$row['id']]);
            else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'integration_'.$provider,$payload,gmdate('c')]);
            if ($afterSave) $afterSave($row);
            // Only the seven explicit connection-save callbacks create a new import run.
            // Cipher rotation, test vault writes and automatic retries are not new imports.
            if($afterSave&&!empty($config['enabled'])&&!empty($config['revision'])&&IntegrationImportSchema::available($this->db))
                (new IntegrationImportRuns($this->db))->begin($company,$provider,(int)($row['version']??0)+1,(string)$config['revision']);
            if(in_array($provider,['n11','idefix'],true))$this->db->run("UPDATE jobs SET state='pending',attempts=0,error=NULL,next_run=? WHERE company_id=? AND provider=? AND state<>'running'",[time()+65,$company,$provider.' Sorular']);
        });
    }
}
