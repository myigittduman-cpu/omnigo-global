<?php
declare(strict_types=1);
namespace Omnigo;

/** Provider-scoped, read-only catalogue totals. Unknown source evidence is never an active offer. */
final class IntegrationImportCatalogSummary
{
    public function __construct(private Database $db){}

    public function attach(int $company,array $reports,?int $now=null):array
    {
        $summaries=$this->forProviders($company,array_column($reports,'provider'),$now);
        return array_map(static fn($report)=>isset($summaries[$report['provider']??''])?[...$report,'currentCatalogSummary'=>$summaries[$report['provider']]]:$report,$reports);
    }

    public function forProviders(int $company,array $providers,?int $now=null):array
    {
        $now??=time();$providers=array_values(array_unique(array_filter($providers,fn($provider)=>is_string($provider)&&isset(IntegrationImportPresentation::PROVIDERS[$provider]))));
        if(!$providers)return [];$result=[];
        foreach($providers as $provider)$result[$provider]=$this->empty($provider);
        if(!IntegrationImportSchema::tableAvailable($this->db,'inventory_listings','company_id'))return $result;
        $sources=$this->sources($company,$providers,$now);
        $cutoffs=[];
        foreach($sources as $provider=>$source)$cutoffs[IntegrationImportPresentation::PROVIDERS[$provider]]=$source['startedAt'];
        $labels=array_map(fn($provider)=>IntegrationImportPresentation::PROVIDERS[$provider],$providers);
        $facts=$this->facts($company,$labels,$now,$cutoffs);$byLabel=[];foreach($facts as $fact)$byLabel[$fact['provider']][]=$fact;
        foreach($providers as $provider){
            $summary=$result[$provider];$rows=$byLabel[$summary['providerLabel']]??[];
            $source=$sources[$provider];$fresh=$source['state']==='verified';
            $summary['sourceState']=$source['state'];$summary['sourceCheckedAt']=$source['checkedAt'];
            foreach(['catalogRecords','products','incompleteRecords','missingNameRecords','unknownStockRecords','unknownSaleStatusRecords','invalidEvidenceRecords','staleRecords'] as $key)$summary[$key]=0;
            $summary['saleActiveProducts']=$fresh?0:null;$summary['saleInactiveProducts']=$fresh?0:null;$summary['saleUnknownProducts']=0;
            $summary['saleActiveRecords']=$fresh?0:null;$summary['saleInactiveRecords']=$fresh?0:null;$summary['saleUnknownRecords']=0;
            foreach($rows as $row){
                $records=(int)$row['catalog_records'];$summary['catalogRecords']+=$records;$summary['products']++;
                if($fresh){$summary['saleActiveRecords']+=(int)$row['active_records'];$summary['saleInactiveRecords']+=(int)$row['inactive_records'];$summary['saleUnknownRecords']+=$records-(int)$row['active_records']-(int)$row['inactive_records'];}
                else $summary['saleUnknownRecords']+=$records;
                $summary['unknownStockRecords']+=(int)$row['unknown_stock'];$summary['unknownSaleStatusRecords']+=(int)$row['unknown_sale'];
                $summary['missingNameRecords']+=(int)$row['missing_names'];
                $summary['invalidEvidenceRecords']+=(int)$row['invalid_evidence'];$summary['staleRecords']+=$fresh?(int)$row['stale_records']:$records;
                $summary['incompleteRecords']+=$fresh?(int)$row['incomplete_records']:$records;
                if($fresh&&(int)$row['has_active']===1)$summary['saleActiveProducts']++;
                elseif($fresh&&(int)$row['all_closed']===1)$summary['saleInactiveProducts']++;
                else $summary['saleUnknownProducts']++;
            }
            if(!$rows&&!$fresh)$summary['incompleteRecords']=null;
            $result[$provider]=$summary;
        }
        return $result;
    }

    /** Shared metadata-only scope for rows observed during a verified merchant catalogue pass. */
    public function sources(int $company,array $providers,int $now):array
    {
        if(!$providers)return [];
        $slots=implode(',',array_fill(0,count($providers),'?'));
        $kinds=array_map(fn($provider)=>'integration_'.$provider,$providers);
        // Credential metadata only; encrypted envelopes are neither selected nor decoded.
        $credentials=array_column($this->db->all("SELECT kind,updated,version FROM records WHERE company_id=? AND kind IN ($slots)",[$company,...$kinds]),null,'kind');
        $jobs=IntegrationImportSchema::tableAvailable($this->db,'inventory_catalog_jobs','company_id')?array_column($this->db->all("SELECT provider,last_success,error FROM inventory_catalog_jobs WHERE company_id=? AND provider IN ($slots)",[$company,...$providers]),null,'provider'):[];
        $progress=IntegrationImportSchema::tableAvailable($this->db,'inventory_catalog_progress','company_id')?array_column($this->db->all("SELECT provider,state,started_at,completed_at FROM inventory_catalog_progress WHERE company_id=? AND provider IN ($slots)",[$company,...$providers]),null,'provider'):[];
        $priorPasses=IntegrationImportSchema::tableAvailable($this->db,'inventory_catalog_verified_passes','company_id')
            ?array_column($this->db->all("SELECT provider,started_at,completed_at FROM inventory_catalog_verified_passes WHERE company_id=? AND provider IN ($slots)",[$company,...$providers]),null,'provider'):[];
        $trendyolPassVersion=null;
        if(isset($credentials['integration_trendyol'])&&IntegrationImportSchema::tableAvailable($this->db,'inventory_trendyol_checkpoints','company_id')){
            $checkpoint=$this->db->one('SELECT payload FROM inventory_trendyol_checkpoints WHERE company_id=?',[$company]);
            $trendyolPassVersion=json_decode($checkpoint['payload']??'{}',true)['lastVerifiedVersion']??null;
        }
        $cutoffs=[];
        foreach($providers as $provider){
            $current=$progress[$provider]??[];
            $prior=$priorPasses[$provider]??null;
            if(($current['state']??null)==='running'&&$prior&&($provider!=='trendyol'||$trendyolPassVersion!==null)&&
                (int)$prior['completed_at']===$this->timestamp($jobs[$provider]['last_success']??null)&&
                (int)$prior['completed_at']<=(int)$current['started_at']){
                $progress[$provider]=[...$prior,'state'=>'complete'];
            }
            $run=$progress[$provider]??[];$start=(int)($run['started_at']??0);$end=(int)($run['completed_at']??0);
            if(($run['state']??'')==='complete'&&$start>0&&$end>=$start&&$end<=$now+300)$cutoffs[IntegrationImportPresentation::PROVIDERS[$provider]]=$start;
        }
        $sources=[];
        foreach($providers as $provider){
            $job=$jobs[$provider]??null;
            $credential=$credentials['integration_'.$provider]??null;
            $configured=$this->timestamp($credential['updated']??null);
            $success=$this->timestamp($job['last_success']??null);
            $start=$cutoffs[IntegrationImportPresentation::PROVIDERS[$provider]]??null;
            $end=(int)($progress[$provider]['completed_at']??0);
            $fresh=$configured!==null&&$success!==null&&$start!==null&&$start>=$configured&&$start<=$success&&$end>=$configured&&$success>=$configured&&$success>=$now-1800&&$success<=$now+300&&empty($job['error']);
            if($fresh&&$provider==='trendyol'){
                // Older checkpoints have no version. A same-second connection change cannot be dated reliably.
                $fresh=$trendyolPassVersion===null?$start>$configured:$trendyolPassVersion===(int)$credential['version'];
            }
            $sources[$provider]=['state'=>$configured===null||!$job?'unavailable':($fresh?'verified':'unverified'),'checkedAt'=>$success!==null?gmdate('c',$success):null,'startedAt'=>$start];
        }
        return $sources;
    }

    /** Aggregate on the server by canonical product, without returning source payloads to PHP/UI. */
    private function facts(int $company,array $labels,int $now,array $cutoffs):array
    {
        $sale=$this->saleExpression();$slots=implode(',',array_fill(0,count($labels),'?'));
        $sourceCutoff='CASE l.provider';$sourceParams=[];
        foreach($labels as $label){$sourceCutoff.=' WHEN ? THEN ?';$sourceParams[]=$label;$sourceParams[]=isset($cutoffs[$label])?gmdate('Y-m-d\TH:i:s',$cutoffs[$label]):'9999-12-31T23:59:59';}
        $sourceCutoff.=" ELSE '9999-12-31T23:59:59' END";
        $hasProducts=IntegrationImportSchema::tableAvailable($this->db,'inventory_products','company_id');
        $products=$hasProducts?' LEFT JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key=l.product_key':'';
        // A verified sample retired before the intake filter was introduced retains its historical listing.
        $visibleSamples=$hasProducts&&in_array('PTT AVM',$labels,true)&&IntegrationImportSchema::tableAvailable($this->db,'inventory_removed_products','product_key')
            ?" AND NOT (l.provider='PTT AVM' AND LOWER(TRIM(l.sku))='deneme' AND LOWER(TRIM(COALESCE(p.title,'')))='deneme'
                AND LOWER(TRIM(COALESCE(p.sku,'')))='deneme' AND EXISTS (SELECT 1 FROM inventory_removed_products hidden
                WHERE hidden.company_id=l.company_id AND hidden.product_key=l.product_key))":'';
        $missingName=$hasProducts?"CASE WHEN TRIM(COALESCE(p.title,''))='' OR LOWER(TRIM(p.title)) LIKE 'stok kodu:%' OR (TRIM(COALESCE(p.sku,''))<>'' AND LOWER(TRIM(p.title))=LOWER(TRIM(p.sku))) THEN 1 ELSE 0 END":'1';
        $sql="SELECT provider,product_key,COUNT(*) catalog_records,SUM(stock_unknown) unknown_stock,SUM(CASE WHEN sale_status IS NULL THEN 1 ELSE 0 END) unknown_sale,SUM(name_missing) missing_names,
            SUM(evidence_invalid) invalid_evidence,SUM(CASE WHEN recent_source=0 THEN 1 ELSE 0 END) stale_records,
            SUM(CASE WHEN evidence_invalid=1 OR stock_unknown=1 OR sale_status IS NULL OR recent_source=0 OR name_missing=1 THEN 1 ELSE 0 END) incomplete_records,
            SUM(CASE WHEN recent_source=1 AND sale_status=1 THEN 1 ELSE 0 END) active_records,
            SUM(CASE WHEN recent_source=1 AND sale_status=0 THEN 1 ELSE 0 END) inactive_records,
            MAX(CASE WHEN recent_source=1 AND sale_status=1 THEN 1 ELSE 0 END) has_active,
            MIN(CASE WHEN recent_source=1 AND sale_status=0 THEN 1 ELSE 0 END) all_closed
            FROM (SELECT l.provider,l.product_key,$sale sale_status,CASE WHEN JSON_VALID(evidence) THEN 0 ELSE 1 END evidence_invalid,$missingName name_missing,
                CASE WHEN source_quantity IS NULL OR source_quantity<0 OR source_quantity>100000000 THEN 1 ELSE 0 END stock_unknown,
                CASE WHEN observed_at>=? AND observed_at<=? AND observed_at>=($sourceCutoff) THEN 1 ELSE 0 END recent_source
                FROM inventory_listings l$products WHERE l.company_id=? AND l.provider IN ($slots) AND l.remote_id NOT LIKE 'order-sku:%'$visibleSamples) source_rows
            GROUP BY provider,product_key";
        return $this->db->all($sql,[gmdate('Y-m-d\TH:i:s',$now-1800),gmdate('Y-m-d\TH:i:s',$now+300),...$sourceParams,$company,...$labels]);
    }

    public function saleExpression():string
    {
        if($this->db->mysql())return "CASE WHEN JSON_VALID(evidence) THEN CASE WHEN JSON_TYPE(JSON_EXTRACT(evidence,'$.saleActive')) IN ('INTEGER','BOOLEAN') THEN CASE JSON_UNQUOTE(JSON_EXTRACT(evidence,'$.saleActive')) WHEN '1' THEN 1 WHEN 'true' THEN 1 WHEN '0' THEN 0 WHEN 'false' THEN 0 ELSE NULL END ELSE NULL END ELSE NULL END";
        return "CASE WHEN JSON_VALID(evidence) THEN CASE JSON_TYPE(evidence,'$.saleActive') WHEN 'true' THEN 1 WHEN 'false' THEN 0 WHEN 'integer' THEN CASE JSON_EXTRACT(evidence,'$.saleActive') WHEN 1 THEN 1 WHEN 0 THEN 0 ELSE NULL END ELSE NULL END ELSE NULL END";
    }

    private function empty(string $provider):array
    {
        return ['scope'=>'current_provider_catalog','provider'=>$provider,'providerLabel'=>IntegrationImportPresentation::PROVIDERS[$provider],
            'sourceState'=>'unavailable','sourceCheckedAt'=>null,'catalogRecords'=>null,'products'=>null,'saleActiveRecords'=>null,'saleInactiveRecords'=>null,'saleUnknownRecords'=>null,'saleActiveProducts'=>null,'saleInactiveProducts'=>null,
            'saleUnknownProducts'=>null,'incompleteRecords'=>null,'missingNameRecords'=>null,'unknownStockRecords'=>null,'unknownSaleStatusRecords'=>null,'invalidEvidenceRecords'=>null,'staleRecords'=>null];
    }

    private function timestamp(mixed $value):?int
    {
        if(!is_string($value)||$value==='')return null;$at=strtotime($value);return $at!==false&&$at>0?$at:null;
    }
}
