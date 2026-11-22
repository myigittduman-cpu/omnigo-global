import { transaction } from './database.js';
import { fail } from './security.js';
import { validateRecord } from './validation.js';
export const channelNames=['Hepsiburada','n11','Pazarama','idefix','PTT AVM','Koçtaş','Teknosa','Trendyol'];
export function createCompany(db,name){
 const id=Number(db.prepare('INSERT INTO companies(name) VALUES(?)').run(name).lastInsertRowid);
 for(const channel of channelNames)db.prepare('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)').run(id,'channels',JSON.stringify({name:channel,status:'active',storeName:'',connection:'not_connected'}),new Date().toISOString());
 return id;
}
export function listRecords(db,company,kind,user){
 const order=kind==='channels'?'ASC':'DESC';
 return db.prepare('SELECT * FROM records WHERE company_id=? AND kind=? ORDER BY id '+order).all(company,kind).map(r=>({...JSON.parse(r.payload),id:r.id,version:r.version,updated:r.updated})).filter(r=>user.role==='admin'||(kind!=='tasks'||r.visibility==='client')&&(kind!=='reports'||r.status==='published'));
}
export function saveRecord(db,user,company,kind,data,id){
 const value=validateRecord(kind,data);
 return transaction(db,()=>{
  const existing=id?db.prepare('SELECT * FROM records WHERE id=? AND company_id=? AND kind=?').get(id,company,kind):null;
  if(id&&!existing)fail(404,'Kayıt bulunamadı.');
  if(existing&&data.version!==existing.version)fail(409,'Bu kayıt başka bir işlemde değişti. Sayfayı yenileyip tekrar deneyin.');
  const channels=listRecords(db,company,'channels',{role:'admin'});
  if(kind==='channels'){
   if(channels.some(c=>c.name===value.name&&c.id!==id))fail(409,'Bu pazaryeri zaten tanımlı.');
   if(existing&&JSON.parse(existing.payload).name!==value.name)fail(400,'Pazaryeri adı değiştirilemez; mağaza adı ve aktiflik durumu düzenlenebilir.');
  }
  if(['sales','payments','tasks'].includes(kind)&&!(kind==='tasks'&&value.channel==='Tüm kanallar')&&!channels.some(c=>c.name===value.channel))fail(400,'Firma için tanımlanmış bir pazaryeri seçin.');
  if(kind==='sales'){
   const duplicate=listRecords(db,company,kind,{role:'admin'}).find(r=>r.channel===value.channel&&r.month===value.month&&r.id!==id);
   if(duplicate)fail(409,'Bu kanal ve ay için satış özeti zaten var. Mevcut kaydı düzenleyin.');
  }
  const updated=new Date().toISOString();
  if(existing)db.prepare('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?').run(JSON.stringify(value),updated,id);
  else id=Number(db.prepare('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)').run(company,kind,JSON.stringify(value),updated).lastInsertRowid);
  db.prepare('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)').run(user.id,company,existing?'Güncellendi':'Eklendi',id,existing?.payload||null,JSON.stringify(value),updated);
  return {...value,id,version:existing?existing.version+1:1,updated};
 });
}
export function dashboard(db,user,company,month){
 const result=Object.fromEntries(['channels','sales','payments','tasks','reports'].map(kind=>[kind,listRecords(db,company,kind,user)]));
 if(user.role!=='admin'){
  result.channels=result.channels.filter(r=>r.status==='active');
  const active=new Set(result.channels.map(r=>r.name));
  result.sales=result.sales.filter(r=>active.has(r.channel));
  result.tasks=result.tasks.filter(r=>['planned','running'].includes(r.status)&&(r.channel==='Tüm kanallar'||active.has(r.channel)));
  result.payments=result.payments.filter(r=>['pending','sent'].includes(r.status));
 }
 result.sales=result.sales.filter(r=>r.month===month);
 result.payments=result.payments.filter(r=>r.expectedDate.startsWith(month)||r.paidDate?.startsWith(month));
 result.reports=result.reports.filter(r=>r.month===month);
 result.tasks=result.tasks.filter(r=>r.date.startsWith(month));
 result.summary={grossCents:0,cancelCents:0,returnCents:0,orders:0,expectedCents:0,paidCents:0};
 for(const row of result.sales)for(const key of ['grossCents','cancelCents','returnCents','orders'])result.summary[key]+=row[key];
 for(const row of result.payments){if(row.status!=='paid'&&row.expectedDate.startsWith(month))result.summary.expectedCents+=row.expectedCents;if(row.status==='paid'&&row.paidDate.startsWith(month))result.summary.paidCents+=row.actualCents;}
 result.summary.netCents=result.summary.grossCents-result.summary.cancelCents-result.summary.returnCents;
 return result;
}
