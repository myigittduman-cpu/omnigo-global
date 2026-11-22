<?php
declare(strict_types=1);

/** Pure comparison only. Similarities are candidates, never deletion decisions. */
final class ManualShipmentAudit
{
    public static function normalize(string $value): string
    {
        $value=strtr($value,['İ'=>'i','I'=>'i','ı'=>'i','Ş'=>'s','ş'=>'s','Ğ'=>'g','ğ'=>'g','Ü'=>'u','ü'=>'u','Ö'=>'o','ö'=>'o','Ç'=>'c','ç'=>'c']);
        return preg_replace('/[^\pL\pN]+/u',' ',mb_strtolower(trim($value),'UTF-8'));
    }
    public static function day(string $value): string
    {
        try{return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d');}
        catch(Throwable){return '';}
    }
    private static function same(string $a,string $b): bool {return $a!==''&&$b!==''&&self::normalize($a)===self::normalize($b);}
    private static function phone(string $s): string { $s=preg_replace('/\D/','',$s);return strlen($s)>=10?substr($s,-10):''; }
    private static function models(string $s): array
    {
        $tokens=explode(' ',self::normalize($s));
        return array_values(array_filter($tokens,fn($s)=>strlen($s)>=5&&preg_match('/[a-z]/',$s)&&preg_match('/[0-9]/',$s)));
    }
    public static function compare(array $a,array $b): ?array
    {
        if($a['company']!==$b['company']||$a['provider']!==$b['provider'])return null;
        $p=$a['payload'];$q=$b['payload'];$da=self::day($p['orderedAt']??'');$db=self::day($q['orderedAt']??'');
        if($da===''||$db===''||abs(strtotime($da)-strtotime($db))>86400)return null;
        $name=self::same($p['customerName']??'',$q['customerName']??'')||self::same($p['recipientName']??'',$q['recipientName']??'');
        $phone=self::phone($p['phone']??'');$phone=$phone!==''&&$phone===self::phone($q['phone']??'');
        $product=self::same($p['productName']??'',$q['productName']??'');
        $model=(bool)array_intersect(self::models($p['productName']??''),self::models($q['productName']??''));
        $amount=isset($p['invoiceCents'],$q['invoiceCents'])&&$p['invoiceCents']===$q['invoiceCents'];
        $quantity=isset($p['quantity'],$q['quantity'])&&$p['quantity']===$q['quantity'];
        $city=self::same($p['province']??'',$q['province']??'');
        if(!($name||$phone)||!($product||$model||($amount&&$quantity&&$city)))return null;
        $evidence=['sameDay'=>$da===$db,'name'=>$name,'phone'=>$phone,'product'=>$product,'model'=>$model,'amount'=>$amount,'quantity'=>$quantity,'city'=>$city];
        $strong=$evidence['sameDay']&&($product||$model)&&$quantity&&$city&&($name||$phone);
        return ['level'=>$strong?'strong_candidate':'candidate','evidence'=>$evidence];
    }
    public static function publicRow(array $row):array
    {
        $p=$row['payload'];return ['id'=>$row['id'],'company'=>$row['company'],'provider'=>$row['provider'],'date'=>self::day($p['orderedAt']??''),'city'=>$p['province']??'','product'=>$p['productName']??'','quantity'=>$p['quantity']??null,'amountCents'=>$p['invoiceCents']??null,'status'=>$row['status']??'','linked'=>$row['linked']??false,'sourceRow'=>$p['import']['sourceRow']??null,'orderNumber'=>$row['orderNumber']??null];
    }
    public static function run(array $shipments,array $sources):array
    {
        $manual=array_values(array_filter($shipments,fn($s)=>!$s['linked']));$rows=[];$duplicateCandidates=[];$exactImports=[];$seen=[];
        foreach($manual as $m){
            $matches=[];foreach($sources as $s){$match=self::compare($m,$s);if($match)$matches[]=[...$match,'source'=>self::publicRow($s)];}
            $strong=array_values(array_filter($matches,fn($m)=>$m['level']==='strong_candidate'));
            $rows[]=['manual'=>self::publicRow($m),'classification'=>count($strong)===1?'unique_strong_candidate':(count($strong)>1?'ambiguous':($matches?'candidate':($m['provider']==='PTT AVM'?'no_connection':(self::day($m['payload']['orderedAt'])<'2026-09-01'?'outside_stored_coverage':'unmatched_in_coverage')))),'matches'=>$matches];
            $imp=$m['payload']['import']??[];
            if(isset($imp['sourceHash'],$imp['sourceRow'])){$key=$m['company'].'|'.$m['provider'].'|'.$imp['sourceHash'].'|'.$imp['sourceRow'];if(isset($seen[$key]))$exactImports[]=[$seen[$key],$m['id']];else$seen[$key]=$m['id'];}
        }
        foreach($shipments as $i=>$a)foreach(array_slice($shipments,$i+1) as $b){
            if($a['linked']&&$b['linked'])continue;
            $match=self::compare($a,$b);if($match&&$match['level']==='strong_candidate')$duplicateCandidates[]=['a'=>self::publicRow($a),'b'=>self::publicRow($b),'evidence'=>$match['evidence'],'kind'=>$a['linked']||$b['linked']?'manual_and_automatic':'manual_pair'];
        }
        $missingCandidates=[];
        foreach($sources as $s){
            if(!str_starts_with((string)$s['id'],'history|')||!in_array($s['payload']['province']??'',['Ankara','Eskişehir','Kayseri','Kırıkkale','Kırşehir','Nevşehir'],true))continue;
            $matches=[];foreach($shipments as $m)if(self::compare($m,$s))$matches[]=$m['id'];
            if(!$matches)$missingCandidates[]=self::publicRow($s);
        }
        $summary=[];foreach($rows as $r)$summary[$r['classification']]=($summary[$r['classification']]??0)+1;
        return ['manualCount'=>count($manual),'sourceLineCount'=>count($sources),'summary'=>$summary,'exactRepeatedImportRows'=>$exactImports,'duplicateCandidates'=>$duplicateCandidates,'missingCandidates'=>$missingCandidates,'rows'=>$rows];
    }
}
