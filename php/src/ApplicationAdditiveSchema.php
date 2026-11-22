<?php
declare(strict_types=1);
namespace Omnigo;

/** Additive release migration: no existing business table or sync job is modified. */
final class ApplicationAdditiveSchema
{
    public static function ensure(Database $db):void
    {
        foreach(['identity'=>fn()=>LoginIdentity::install($db),'profiles'=>fn()=>AdminLoginProfiles::seed($db),'documents'=>fn()=>OrderDocumentSchema::ensure($db),'connection_imports'=>fn()=>IntegrationImportSchema::ensure($db)] as $step=>$action){
            try{$action();}
            catch(\PDOException $error){
                $frames=array_map(fn($frame)=>basename($frame['file']??'').':'.($frame['line']??0),array_slice($error->getTrace(),0,5));
                preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|REFERENCES|ALTER) command denied\b/i',$error->getMessage(),$denied);
                error_log('Omnigo additive schema step='.$step.' sqlstate='.$error->getCode().' driver='.(int)($error->errorInfo[1]??0).' denied='.strtoupper($denied[1]??'unknown').' frames='.implode(',',$frames));
                throw $error;
            }
        }
    }
}
