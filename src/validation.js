import { fail } from './security.js';
export const kinds=['channels','sales','payments','tasks','reports'];
function text(v,label,max=250,optional=false){if(optional&&(v===undefined||v===''))return '';if(typeof v!=='string'||!v.trim()||v.length>max)fail(400,label+' alanını kontrol edin.');return v.trim();}
function choice(v,values,label){if(!values.includes(v))fail(400,label+' geçersiz.');return v;}
function date(v){const parsed=new Date(v);if(typeof v!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(v)||!Number.isFinite(parsed.getTime())||parsed.toISOString().slice(0,10)!==v)fail(400,'Tarih geçersiz.');return v;}
function month(v){if(typeof v!=='string'||!/^\d{4}-(0[1-9]|1[0-2])$/.test(v))fail(400,'Dönem geçersiz.');return v;}
function integer(v,label){if(!Number.isSafeInteger(v)||v<0||v>100000000000)fail(400,label+' geçersiz.');return v;}
export function account(data){return {email:text(data.email,'E-posta',200).toLowerCase(),name:text(data.name,'Ad',100),password:text(data.password,'Şifre',128)};}
export function validateAccount(data,{storeUser=false}={}){
  const a=account(data);
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(a.email))fail(400,'Geçerli bir e-posta yazın.');
  if(storeUser){
    if(data.password.includes('\u0000')||Buffer.byteLength(data.password,'utf8')>128)fail(400,'Şifre boş olamaz ve UTF-8 biçiminde 128 baytı aşamaz.');
    a.password=data.password;
  }else if(a.password.length<12)fail(400,'Şifre en az 12 karakter olmalı.');
  return a;
}
export function validateRecord(kind,p){
 if(!kinds.includes(kind)||!p||typeof p!=='object')fail(400,'Kayıt türü geçersiz.');
 if(kind==='channels')return {name:text(p.name,'Pazaryeri',60),status:choice(p.status,['active','inactive'],'Mağaza durumu'),storeName:text(p.storeName,'Mağaza adı',150,true),connection:'not_connected'};
 if(kind==='sales')return {channel:text(p.channel,'Pazaryeri',60),month:month(p.month),grossCents:integer(p.grossCents,'Brüt satış'),cancelCents:integer(p.cancelCents,'İptal'),returnCents:integer(p.returnCents,'İade'),orders:integer(p.orders,'Sipariş sayısı'),source:text(p.source,'Bilgi kaynağı',250)};
 if(kind==='payments'){
  const result={channel:text(p.channel,'Pazaryeri',60),expectedDate:date(p.expectedDate),expectedCents:integer(p.expectedCents,'Beklenen tutar'),status:choice(p.status,['pending','sent','paid'],'Ödeme durumu'),source:text(p.source,'Bilgi kaynağı',250),actualCents:null,paidDate:'',confirmation:''};
  if(result.status==='paid'){result.actualCents=integer(p.actualCents,'Gerçekleşen tutar');result.paidDate=date(p.paidDate);if(result.paidDate>new Date().toLocaleDateString('sv-SE',{timeZone:'Europe/Istanbul'}))fail(400,'Hesaba geçiş tarihi gelecekte olamaz.');result.confirmation=text(p.confirmation,'Teyit kaynağı',250);}
  return result;
 }
 if(kind==='tasks')return {title:text(p.title,'Başlık',150),description:text(p.description,'Açıklama',5000),channel:text(p.channel,'Pazaryeri',60),date:date(p.date),status:choice(p.status,['planned','running','done'],'Çalışma durumu'),visibility:choice(p.visibility,['internal','client'],'Görünürlük'),owner:text(p.owner,'Sorumlu',100)};
 return {title:text(p.title,'Başlık',150),month:month(p.month),content:text(p.content,'Rapor içeriği',30000),status:choice(p.status,['draft','published'],'Rapor durumu')};
}
