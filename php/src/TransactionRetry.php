<?php
declare(strict_types=1);
namespace Omnigo;

/** Use only for database-only callbacks whose rolled-back work is safe to repeat. */
final class TransactionRetry
{
    public static function run(Database $db, callable $action): mixed
    {
        for ($attempt=0;;$attempt++) {
            try { return $db->transaction($action); }
            catch (\PDOException $error) {
                $code=(int)($error->errorInfo[1]??0);
                $retry=$db->mysql()?in_array($code,[1205,1213],true):in_array($code & 0xff,[5,6],true);
                if (!$retry || $attempt>=3) throw $error;
                usleep(random_int(15000,30000)*(1<<$attempt));
            }
        }
    }
}
