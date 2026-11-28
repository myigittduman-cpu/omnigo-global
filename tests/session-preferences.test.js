import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

function fixture({initial=false,failSave=false,otherChanges=false,beforeSignOut}={}){
 const source=readFileSync(new URL('../public/session-preferences.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replaceAll('export ','');
 const writes=[],events={},status={textContent:''},choice={checked:false,disabled:true,addEventListener:(name,handler)=>events[name]=handler};
 const form={isConnected:true,elements:{keepSession:choice},querySelector:()=>status};
 let baseline,busy=false,signedOut=false,persisted=initial;
 const entry={reset:()=>baseline=choice.checked,async save(){
  if(busy)return false;if(choice.checked===baseline)return true;
  busy=true;choice.disabled=true;
  try{if(await entry.work()===false)return false;entry.reset();return true;}
  finally{busy=false;choice.disabled=false;}
 }};
 const drafts={hasOtherChanges:()=>otherChanges,track:(_,options)=>{entry.work=options.save;entry.reset();return entry;}};
 const api=async(path,method='GET',data,options)=>{
  if(method==='GET')return {keepSession:persisted};
  writes.push({path,method,data,options});if(failSave)throw new Error('Bağlantı kurulamadı.');
  persisted=data.keepSession;return {keepSession:persisted,signedOut:!persisted};
 };
 const bind=Function('api','t',source+';return bindSessionPreference;')(api,x=>x);
 return {choice,status,writes,form,async open(){await bind(form,{active:()=>true,drafts,beforeSignOut,onSignedOut:()=>signedOut=true});},async change(value){choice.checked=value;await events.change();},isSignedOut:()=>signedOut};
}

test('Oturumu açık tut işaretlenince ayrıca Kaydet gerektirmeden kalıcı tercih kaydedilir',async()=>{
 const f=fixture();await f.open();assert(!f.choice.disabled);assert(!f.choice.checked);
 await f.change(true);assert.deepEqual(f.writes,[{path:'/account/session',method:'PUT',data:{keepSession:true},options:{confirmed:true}}]);
 assert.match(f.status.textContent,/açık tutulacak/);assert(!f.choice.disabled);
 await f.open();assert(f.choice.checked,'Kayıt yeniden açılınca korunur');
 await f.change(true);assert.equal(f.writes.length,1,'Aynı tercih tekrar yazılmaz');
});

test('Kaydedilemeyen oturum tercihi işaretli kalmaz; hata gösterilir ve tekrar denenebilir',async()=>{
 const f=fixture({failSave:true});await f.open();await f.change(true);
 assert(!f.choice.checked);assert(!f.choice.disabled);assert.equal(f.status.textContent,'Bağlantı kurulamadı.');assert(!f.isSignedOut());
 await f.change(true);assert.equal(f.writes.length,2);
});

test('Kalıcı oturumu kapatmak onay yolunu ve yalnız mevcut cihazdan çıkışı kullanır',async()=>{
 const f=fixture({initial:true});await f.open();assert(f.choice.checked);
 await f.change(false);assert.deepEqual(f.writes[0].data,{keepSession:false});assert.equal(f.writes[0].options.confirmed,false);assert(f.isSignedOut());
});

test('Diğer kaydedilmemiş ayarlar veya vazgeçilen çıkış kalıcı oturumu kapatmaz',async()=>{
 for(const options of [{otherChanges:true},{beforeSignOut:async()=>false}]){
  const f=fixture({initial:true,...options});await f.open();await f.change(false);
  assert.equal(f.writes.length,0);assert(f.choice.checked);assert(!f.isSignedOut());
 }
});
