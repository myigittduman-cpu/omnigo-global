import {transaction} from './database.js';
// Adaptörler yalnızca doğrulanmış, salt okunur pazaryeri servisleriyle kaydedilir.
// Henüz bir canlı adaptör tanımlı değildir; işlere sahte başarı sonucu verilmez.
export class SyncQueue {
 constructor(db,adapters=new Map(),clock=Date.now){this.db=db;this.adapters=adapters;this.clock=clock;}
 enqueue(companyId,provider){this.db.prepare("INSERT INTO jobs(company_id,provider,next_run) VALUES(?,?,?) ON CONFLICT(company_id,provider) DO UPDATE SET state='pending',attempts=0,error=NULL,next_run=excluded.next_run WHERE jobs.state!='running'").run(companyId,provider,this.clock());}
 async runNext(){
  const job=transaction(this.db,()=>{const row=this.db.prepare("SELECT * FROM jobs WHERE state='pending' AND next_run<=? ORDER BY next_run LIMIT 1").get(this.clock());if(!row)return null;this.db.prepare("UPDATE jobs SET state='running',attempts=attempts+1,next_run=? WHERE id=?").run(this.clock()+300000,row.id);return {...row,attempts:row.attempts+1};});
  if(!job)return false;
  const adapter=this.adapters.get(job.provider);
  if(!adapter){this.db.prepare("UPDATE jobs SET state='blocked',error=? WHERE id=?").run('Pazaryeri bağlantısı henüz yapılandırılmadı.',job.id);return true;}
  try{
   // Adaptör sayfalama, sabit kimliklerle tekrar güvenli kayıt ve atomik dönem yayını sağlamalıdır.
   await adapter.syncReadOnly({companyId:job.company_id,signal:AbortSignal.timeout(120000)});
   this.db.prepare("UPDATE jobs SET state='pending',attempts=0,error=NULL,next_run=? WHERE id=?").run(this.clock()+Math.max(60000,adapter.intervalMs||900000),job.id);
  }catch(error){
   const blocked=error.status===401||error.status===403;const exhausted=job.attempts>=5;
   const delay=Math.max(1000,Math.min(3600000,Number(error.retryAfterMs)||1000*2**job.attempts));
   this.db.prepare('UPDATE jobs SET state=?,error=?,next_run=? WHERE id=?').run(blocked?'blocked':exhausted?'failed':'pending',blocked?'Bağlantı yetkisi doğrulanamadı.':exhausted?'Yeniden deneme sınırına ulaşıldı.':'Geçici bağlantı sorunu; yeniden denenecek.',this.clock()+delay,job.id);
  }
  return true;
 }
 recoverExpiredLeases(){this.db.prepare("UPDATE jobs SET state='pending',error='Yarım kalan iş yeniden sıraya alındı.' WHERE state='running' AND next_run<?").run(this.clock());}
}
