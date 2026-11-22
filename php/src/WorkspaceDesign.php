<?php
declare(strict_types=1);
namespace Omnigo;

/** One installation-wide presentation setting, independent of tenants and accounts. */
final class WorkspaceDesign
{
    public function __construct(private string $file){}
    public static function shared():self{return new self(dirname(__DIR__).'/storage/workspace-design.json');}
    public function read():array
    {
        if(!is_file($this->file))return ['mode'=>'balanced','version'=>0];
        $raw=@file_get_contents($this->file);$value=is_string($raw)&&strlen($raw)<=4096?json_decode($raw,true):null;
        if(!is_array($value)||!in_array($value['mode']??null,['balanced','classic'],true)||!is_int($value['version']??null)||$value['version']<1){error_log('Omnigo workspace design: invalid configuration; previous layout retained.');return ['mode'=>'classic','version'=>0];}
        return array_intersect_key($value,array_flip(['mode','version','palette']));
    }
    public function save(array $actor,array $data):array
    {
        Security::admin($actor);$mode=Validation::choice($data['mode']??null,['balanced','classic']);
        if(!is_int($data['version']??null)||$data['version']<0)Http::fail(400,'Düzen sürümünü kontrol edin.');
        $lock=@fopen($this->file.'.lock','c');if(!$lock)Http::fail(503,'Uygulama düzeni şu anda kaydedilemiyor.');
        $temporary=null;
        try{
            if(!flock($lock,LOCK_EX))Http::fail(503,'Uygulama düzeni şu anda kaydedilemiyor.');
            $current=$this->read();if($current['version']!==$data['version'])Http::fail(409,'Uygulama düzeni başka bir cihazda değişti. Ayarları yeniden açın.');
            $palette=$data['palette']??($current['palette']??[]);
            if($palette instanceof \stdClass)$palette=(array)$palette;
            if(!is_array($palette)||array_diff_key($palette,array_flip(['background','surface','text','muted','accent','border'])))Http::fail(400,'Renk alanını kontrol edin.');
            foreach($palette as $color)if(!is_string($color)||!preg_match('/^#[0-9a-f]{6}$/iD',$color))Http::fail(400,'Altı basamaklı bir renk seçin.');
            if($mode===$current['mode']&&$palette==($current['palette']??[]))return $current;
            $next=['mode'=>$mode,'version'=>$current['version']+1,...($palette?['palette'=>$palette]:[])];$bytes=json_encode($next,JSON_THROW_ON_ERROR)."\n";
            $temporary=tempnam(dirname($this->file),'workspace-design-');
            if($temporary===false)Http::fail(503,'Uygulama düzeni şu anda kaydedilemiyor.');
            @chmod($temporary,0600);
            if(file_put_contents($temporary,$bytes,LOCK_EX)!==strlen($bytes)||!rename($temporary,$this->file))Http::fail(503,'Uygulama düzeni şu anda kaydedilemiyor.');
            $temporary=null;return $next;
        }finally{if(is_string($temporary)&&is_file($temporary))unlink($temporary);flock($lock,LOCK_UN);fclose($lock);}
    }
}
