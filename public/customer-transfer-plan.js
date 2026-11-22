export function createTransferPlan({start,companyId}){
 const entries=new Map();
 return {
  get count(){return entries.size;},
  has:provider=>entries.has(provider),
  prepare(provider,credentials){entries.set(provider,credentials);},
  discard(provider){entries.delete(provider);},
  clear(){entries.clear();},
  async send(profileVersion){
   const result=await start({profileVersion,connections:Object.fromEntries(entries)});
   if(Number(result.companyId)!==companyId)throw new Error('Aktarımın müşterisi doğrulanamadı.');
   for(const [provider,row] of Object.entries(result.markets)){
    if(row.state==='scheduled')entries.delete(provider);
   }
   return result;
  },
 };
}

export function transferStartFeedback(result){
 const states=Object.values(result.markets??{}).map(market=>market.state);
 const scheduled=states.includes('scheduled')||result.inventory==='scheduled';
 const needsAttention=states.some(state=>state==='error'||state==='waiting')||result.inventory==='error';
 if(scheduled)return {kind:'pending',message:needsAttention?'Bazı bağlantılar sıraya alındı. Diğerlerini son kontrolde inceleyin.':'Canlı aktarım sıraya alındı.'};
 if(needsAttention)return {kind:'error',message:'Canlı aktarım başlatılamadı. Son kontroldeki bağlantı durumlarını inceleyin.'};
 if(result.setupRun?.state==='complete')return {kind:'success',message:'Canlı aktarımın tamamlandığı doğrulandı.'};
 if(states.includes('active')||result.inventory==='active')return {kind:'info',message:'Aktarım görevleri kayıtlı. Sonraki kontrol zamanı korunuyor.'};
 return {kind:'error',message:'Canlı aktarım başlatılamadı. Son kontroldeki bağlantı durumlarını inceleyin.'};
}
