<?php
declare(strict_types=1);
namespace Omnigo;

/** Local product photos verified against an exact manufacturer and model. */
final class InboxQuestionPhotoCatalog
{
    private array $catalog;

    public function __construct(?array $catalog=null)
    {
        $manifest=dirname(__DIR__).'/resources/inbox-question-photo-catalog.json';
        $entries=$catalog??(is_file($manifest) ? json_decode((string)file_get_contents($manifest),true,32) : []);
        $this->catalog=is_array($entries)?$entries:[];
    }

    public function url(string $title,array $modelHints=[]): string
    {
        $matches=self::identities($title,array_keys($this->catalog),$modelHints);
        if(count($matches)!==1)return '';
        $file=$this->catalog[$matches[0]]['file']??null;
        return is_string($file)&&preg_match('/^[a-z0-9][a-z0-9-]{2,100}\.(?:webp|png)$/D',$file)
            ? '/assets/inbox-products/'.$file : '';
    }

    /** Match whole tokens including variant suffixes. Every accepted key belongs to the reviewed manifest. */
    public static function identities(string $title,array $knownKeys,array $modelHints=[]): array
    {
        if($title==='')return [];
        preg_match_all('/[\p{L}\p{N}]+(?:[-.\/][\p{L}\p{N}]+)*/u',$title,$tokenMatch);
        $words=array_map(self::key(...),$tokenMatch[0]);
        if(!$words)return [];
        // A manufacturer is named in the title, rather than taken from an arbitrary SKU or URL.
        $brands=array_unique(array_filter(array_map(static function($key): string {
            return is_string($key)&&str_contains($key,':')?explode(':',$key,2)[0]:'';
        },$knownKeys)));
        $mentioned=array_values(array_intersect($brands,$words));
        if(count($mentioned)!==1||!in_array($mentioned[0],array_slice($words,0,4),true))return [];
        $brand=$mentioned[0];
        $modelLike=array_values(array_unique(array_filter($words,static fn(string $word): bool=>
            preg_match('/^(?=[a-z0-9.\/-]{6,24}$)(?=(?:.*[a-z]){2})(?=(?:.*[0-9]){2})[a-z0-9.\/-]+$/D',$word)===1
        )));
        if(count($modelLike)>1)return [];
        $models=$modelLike?:array_map(static fn($hint): string=>is_string($hint)?self::key($hint):'', $modelHints);
        $models=array_unique([...$words,...$models]);
        $matches=[];
        foreach($knownKeys as $key){
            if(!is_string($key)||!str_starts_with($key,$brand.':'))continue;
            $model=substr($key,strlen($brand)+1);
            $code=self::key($model);
            if($code!==''&&in_array($code,$models,true)&&(!$modelLike||in_array($code,$modelLike,true)))$matches[]=$key;
        }
        return $matches;
    }

    private static function key(string $value): string
    {
        return mb_strtolower(str_replace(['İ','I','ı'],'i',trim($value)),'UTF-8');
    }
}
