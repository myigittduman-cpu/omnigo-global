<?php
declare(strict_types=1);
namespace Omnigo;

/** Full authenticated scan, including document hashes and missing references. */
final class BackupVerification
{
    public static function inspect(string $path, string $key, ?callable $consume=null): array
    {
        $manifest=null; $table=null; $columns=[]; $position=0; $count=0; $file=null;
        $counts=[]; $files=[]; $references=[]; $hash=null; $bytes=0; $totalBytes=0;
        $cipher=DeliveryCipher::withKey($key);
        foreach (BackupStream::records($path,$key) as $record) {
            $type=$record['type'];
            if ($file!==null && !in_array($type,['chunk','fileEnd'],true)) throw new \RuntimeException('Evrak kaydı tamamlanmamış.');
            switch ($type) {
                case 'manifest':
                    if ($manifest!==null || ($record['format']??null)!==2 || !is_array($record['tables']??null) || !$record['tables'] || array_values(array_intersect(BackupArchive::TABLES,$record['tables']))!==$record['tables']) throw new \RuntimeException('Yedek şeması geçersiz.');
                    $manifest=$record; break;
                case 'table':
                    if (!$manifest || $table!==null || ($manifest['tables'][$position]??null)!==($record['name']??null)) throw new \RuntimeException('Tablo sırası geçersiz.');
                    $columns=$record['columns']??[];
                    if (!$columns || count(array_unique($columns))!==count($columns)) throw new \RuntimeException('Sütunlar geçersiz.');
                    foreach ($columns as $column) if (!is_string($column) || !preg_match('/^[a-z_]+$/D',$column)) throw new \RuntimeException('Sütun adı geçersiz.');
                    $table=$record['name']; $count=0; break;
                case 'row':
                    if (!$table || !is_array($record['value']??null) || array_keys($record['value'])!==$columns || in_array($table,BackupArchive::TRANSIENT,true)) throw new \RuntimeException('Yedek satırı geçersiz.');
                    foreach ($record['value'] as $value) if ($value!==null && !is_scalar($value)) throw new \RuntimeException('Hücre değeri geçersiz.');
                    foreach (BackupFiles::references($table,$record['value'],$cipher) as $ref) $references[$ref]=true;
                    $count++; break;
                case 'tableEnd':
                    if (!$table || ($record['count']??null)!==$count) throw new \RuntimeException('Satır sayısı uyuşmuyor.');
                    $counts[$table]=$count; $table=null; $position++; break;
                case 'file':
                    $file=$record['path']??'';
                    if (!$table || !BackupFiles::valid($file) || isset($files[$file]) || !isset($references[$file])) throw new \RuntimeException('Evrak yolu geçersiz.');
                    $hash=hash_init('sha256'); $bytes=0; break;
                case 'chunk':
                    $chunk=base64_decode($record['data']??'',true);
                    if ($file===null || $chunk===false || strlen($chunk)>262144) throw new \RuntimeException('Evrak parçası geçersiz.');
                    hash_update($hash,$chunk); $bytes+=strlen($chunk); break;
                case 'fileEnd':
                    if ($file===null || ($record['bytes']??null)!==$bytes || ($record['sha256']??null)!==hash_final($hash)) throw new \RuntimeException('Evrak bütünlüğü uyuşmuyor.');
                    $files[$file]=true; $totalBytes+=$bytes; $file=null; break;
                default: throw new \RuntimeException('Yedek kaydı tanınmıyor.');
            }
            if ($consume) $consume($record);
        }
        if (!$manifest || $table!==null || $file!==null || $position!==count($manifest['tables']) || array_diff_key($references,$files)) throw new \RuntimeException('Yedek tamamlanmamış.');
        return ['format'=>2,'createdAt'=>$manifest['createdAt'],'rows'=>$counts,'documents'=>count($files),'documentBytes'=>$totalBytes];
    }
}
