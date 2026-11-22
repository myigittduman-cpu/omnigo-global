<?php
declare(strict_types=1);
namespace Omnigo;

final class StreamingBackup
{
    public static function create(Database $db, string $key, string $path, string $storage): array
    {
        $tables=BackupArchive::checkSchema($db); $cipher=DeliveryCipher::withKey($key);
        if ($db->mysql()) $db->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $buffered=$db->mysql()?$db->pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY):null;
        try {
            if ($db->mysql()) $db->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
            $db->transaction(function() use($db,$tables,$cipher,$key,$path,$storage) {
                $records=(function() use($db,$tables,$cipher,$storage) {
                    yield ['type'=>'manifest','format'=>2,'createdAt'=>gmdate('c'),'tables'=>$tables];
                    $seen=[];
                    foreach ($tables as $table) {
                        yield ['type'=>'table','name'=>$table,'columns'=>BackupArchive::columns($db,$table)];
                        $count=0;
                        if (!in_array($table,BackupArchive::TRANSIENT,true)) {
                            $query=$db->run("SELECT * FROM `$table`");
                            try { while ($row=$query->fetch()) {
                                yield ['type'=>'row','value'=>$row]; $count++;
                                foreach (BackupFiles::references($table,$row,$cipher) as $file) {
                                    if (isset($seen[$file])) continue;
                                    yield from BackupFiles::records($storage,$file); $seen[$file]=true;
                                }
                            }} finally { $query->closeCursor(); }
                        }
                        yield ['type'=>'tableEnd','count'=>$count];
                    }
                })();
                BackupStream::write($path,$key,$records);
            });
        } finally { if ($db->mysql()) $db->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered); }
        return BackupVerification::inspect($path,$key);
    }
}
