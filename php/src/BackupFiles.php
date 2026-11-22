<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit allowlist: configuration and encryption keys never enter the archive. */
final class BackupFiles
{
    public static function valid(string $path): bool
    {
        return (bool)preg_match('#^(?:(?:shipment-documents|inbox-attachments|order-documents|operation-documents)/[a-f0-9]{48}\.bin|inventory-images/[1-9][0-9]*/[a-f0-9]{48}\.png|file-center/[1-9][0-9]*/[1-9][0-9]*/[a-f0-9]{64}\.bin)$#D',$path);
    }
    public static function references(string $table, array $row, DeliveryCipher $cipher): array
    {
        $paths=[];
        if($table==='operation_dossiers'){
            $value=$cipher->open((int)$row['company_id'],'operation-dossier:'.$row['dossier_key'],$row['payload']);
            foreach($value['files']??[] as $file)$paths[]='operation-documents/'.$file['key'].'.bin';
        }
        if($table==='inventory_products'&&str_starts_with((string)($row['image_url']??''),'inventory-image:')){
            if(!preg_match('/^inventory-image:([a-f0-9]{48})$/D',$row['image_url'],$match)||(int)$row['company_id']<1)throw new \RuntimeException('Ürün görseli başvurusu geçersiz.');
            $paths[]='inventory-images/'.(int)$row['company_id'].'/'.$match[1].'.png';
        }
        if($table==='order_documents'){
            $meta=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
            if(isset($meta['key']))$paths[]='order-documents/'.$meta['key'].'.bin';
        }
        if($table==='records'&&preg_match('/^file_center_([1-9][0-9]*)$/D',$row['kind'],$actor)){
            $meta=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
            $paths[]='file-center/'.(int)$row['company_id'].'/'.$actor[1].'/'.($meta['key']??'').'.bin';
        }
        if($table===FileCenterSchema::ARCHIVES){
            $paths[]='file-center/'.(int)$row['company_id'].'/'.(int)$row['owner_id'].'/'.$row['file_key'].'.bin';
        }
        if ($table==='shipment_entries') {
            $payload=$cipher->open((int)$row['company_id'],'shipment:'.$row['id'],$row['payload']);
            foreach ($payload['documents']??[] as $document) $paths[]='shipment-documents/'.($document['key']??'').'.bin';
        }
        if ($table==='inbox_jobs' && $row['kind']==='reply' && in_array($row['state'],['pending','running','unknown'],true)) {
            $payload=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
            foreach ($payload['attachments']??[] as $id) $paths[]='inbox-attachments/'.$id.'.bin';
        }
        foreach ($paths as $path) if (!self::valid($path)) throw new \RuntimeException('Evrak başvurusu geçersiz.');
        return $paths;
    }
    public static function records(string $storage, string $path): \Generator
    {
        if (!self::valid($path) || is_link($storage.'/'.$path) || is_link(dirname($storage.'/'.$path))) throw new \RuntimeException('Evrak yolu geçersiz.');
        $ancestor=rtrim($storage,'/\\');foreach(explode('/',$path) as $part){$ancestor.='/'.$part;if(is_link($ancestor))throw new \RuntimeException('Evrak yolu geçersiz.');}
        $file=@fopen($storage.'/'.$path,'rb');
        if (!$file) throw new \RuntimeException('Başvurulan evrak eksik; yedek tamamlanmadı.');
        try {
            yield ['type'=>'file','path'=>$path]; $hash=hash_init('sha256'); $bytes=0;
            while (!feof($file)) {
                $chunk=fread($file,262144); if ($chunk===false) throw new \RuntimeException('Evrak okunamadı.');
                if ($chunk==='') break;
                hash_update($hash,$chunk); $bytes+=strlen($chunk);
                yield ['type'=>'chunk','data'=>base64_encode($chunk)];
            }
            yield ['type'=>'fileEnd','bytes'=>$bytes,'sha256'=>hash_final($hash)];
        } finally { fclose($file); }
    }
    /** Only remove a directory created exclusively for this restore attempt. */
    public static function removeStaging(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
