<?php
declare(strict_types=1);
namespace Omnigo;

/** Per-account, per-company copies of generated exports. */
final class FileCenterArchive
{
    public const MAX_BYTES = 32 * 1024 * 1024;
    private const PAGE_SIZE = 10;
    private const TYPES = [
        'pdf'=>'application/pdf',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    private const CATEGORIES = ['orders', 'stock', 'finance'];

    public function __construct(private Database $db, private DeliveryCipher $cipher, private string $root) {}

    public function listing(int $company, int $actor, int $page): array
    {
        if (!$this->available()) return ['items'=>[], 'page'=>0, 'pageSize'=>self::PAGE_SIZE, 'total'=>0];
        $scope = [$company,$actor];
        $total = (int)$this->db->one('SELECT COUNT(*) n FROM file_center_archives
            WHERE company_id=? AND owner_id=? AND removed_at IS NULL', $scope)['n'];
        $page = max(0, min($page, (int)ceil($total / self::PAGE_SIZE) - 1));
        $offset = $page * self::PAGE_SIZE;
        $rows = $this->db->all('SELECT id,payload,updated FROM file_center_archives
            WHERE company_id=? AND owner_id=? AND removed_at IS NULL
            ORDER BY id DESC LIMIT '.self::PAGE_SIZE." OFFSET $offset", $scope);
        return ['items'=>array_map(self::metadata(...), $rows), 'page'=>$page, 'pageSize'=>self::PAGE_SIZE, 'total'=>$total];
    }

    public function save(int $company, int $actor, string $category, string $name, string $bytes): array
    {
        $type = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($category, self::CATEGORIES, true) || !isset(self::TYPES[$type]) ||
            !preg_match('/^[\p{L}\p{N} .,()_&+-]{1,180}$/uD', $name)) {
            Http::fail(400, 'Dosya adı veya türü geçersiz.');
        }
        if (strlen($bytes) > self::MAX_BYTES) Http::fail(413, 'Dosya arşiv sınırını aşıyor.');
        $signature = $type === 'pdf' ? '%PDF-' : "PK\x03\x04";
        if (!str_starts_with($bytes, $signature)) Http::fail(415, 'PDF veya Excel dosyası okunamadı.');
        if (!$this->available()) FileCenterSchema::install($this->db);
        $key = hash('sha256', $category."\0".$name."\0".$bytes);
        $folder = $this->folder($company, $actor);
        if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) Http::fail(503, 'Dosya arşivi hazır değil.');
        $lock = fopen($folder.'/.archive.lock', 'c');
        if (!$lock) Http::fail(503, 'Dosya arşivi hazır değil.');
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) Http::fail(503, 'Dosya arşivi hazır değil.');
            $scope = [$company,$actor,$key];
            $row = $this->db->one('SELECT id,payload,updated FROM file_center_archives WHERE company_id=? AND owner_id=? AND file_key=?', $scope);
            $path = $folder.'/'.$key.'.bin';
            if (!$row || !is_file($path)) {
                $sealed = $this->cipher->seal($company, self::keyScope($actor, $key), ['bytes'=>base64_encode($bytes)]);
                $temporary = tempnam($folder, '.report-');
                if ($temporary === false || file_put_contents($temporary, $sealed) !== strlen($sealed) || !rename($temporary, $path)) {
                    Http::fail(503, 'Dosya arşive yazılamadı.');
                }
                $temporary = null;
                chmod($path, 0600);
            }
            if ($row) return self::metadata($row);
            $updated = gmdate('c');
            $payload = json_encode(['name'=>$name, 'category'=>$category, 'type'=>$type, 'size'=>strlen($bytes)],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            try {
                $this->db->run('INSERT INTO file_center_archives(company_id,owner_id,file_key,payload,updated) VALUES(?,?,?,?,?)',
                    [...$scope,$payload,$updated]);
            } catch (\Throwable $error) {
                @unlink($path);
                throw $error;
            }
            return self::metadata(['id'=>$this->db->insertId(), 'payload'=>$payload, 'updated'=>$updated]);
        } finally {
            if (is_string($temporary)) @unlink($temporary);
            fclose($lock);
        }
    }

    public function read(int $company, int $actor, int $id): array
    {
        if (!$this->available()) Http::fail(404, 'Dosya bulunamadı.');
        $row = $this->db->one('SELECT id,file_key,payload,updated FROM file_center_archives
            WHERE company_id=? AND owner_id=? AND id=? AND removed_at IS NULL', [$company,$actor,$id]);
        if (!$row || !preg_match('/^[a-f0-9]{64}$/D', $row['file_key'])) Http::fail(404, 'Dosya bulunamadı.');
        $key = $row['file_key'];
        $path = $this->folder($company, $actor).'/'.$key.'.bin';
        if (!is_file($path) || filesize($path) > self::MAX_BYTES * 2) Http::fail(404, 'Dosya bulunamadı.');
        $result = $this->cipher->open($company, self::keyScope($actor, $key), file_get_contents($path));
        $meta = self::metadata($row);
        $bytes = base64_decode($result['bytes'], true);
        if ($bytes === false || strlen($bytes) !== $meta['size']) Http::fail(503, 'Arşiv dosyası okunamadı.');
        return ['bytes'=>$bytes, 'mime'=>self::TYPES[$meta['type']], 'name'=>$meta['name']];
    }

    public function remove(int $company, int $actor, mixed $selection): int
    {
        $ids = FileCenterSelection::items($selection, static fn(mixed $id): bool => is_int($id) && $id > 0);
        if (!$this->available()) return 0;
        $markers = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->run("UPDATE file_center_archives SET removed_at=?
            WHERE company_id=? AND owner_id=? AND removed_at IS NULL AND id IN ($markers)",
            [gmdate('c'),$company,$actor,...$ids])->rowCount();
    }

    private function available(): bool { return FileCenterSchema::available($this->db,FileCenterSchema::ARCHIVES); }
    private static function keyScope(int $actor, string $key): string { return 'file_center_'.$actor.':'.$key; }
    private function folder(int $company, int $actor): string { return $this->root.'/'.$company.'/'.$actor; }

    private static function metadata(array $row): array
    {
        return ['id'=>(int)$row['id'], 'createdAt'=>$row['updated'], ...json_decode($row['payload'], true, 16, JSON_THROW_ON_ERROR)];
    }
}
