import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {transferStartFeedback} from '../public/customer-transfer-plan.js';
import {saveFeedbackFor} from '../public/save-feedback-policy.js';
import {sourceAnsweredLabel} from '../public/inbox-reply-delivery.js';

const source=name=>readFileSync(new URL(`../public/${name}.js`,import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replaceAll('export ','');
const fixture=(name,bindings,fn)=>Function(...Object.keys(bindings),source(name)+`;return ${fn};`)(...Object.values(bindings));
const t=value=>value;

test('Aktarım bildirimi sırayı, kısmi kabulü, tamamlanan çalışmayı ve hatayı ayırır',()=>{
 for(const [value,kind] of [
  [{markets:{idefix:{state:'scheduled'}}},'pending'],
  [{markets:{idefix:{state:'active'},n11:{state:'waiting'}},inventory:'scheduled'},'pending'],
  [{markets:{idefix:{state:'active'}},inventory:'error'},'error'],
  [{markets:{idefix:{state:'active'}},setupRun:{state:'complete'}},'success'],
  [{markets:{idefix:{state:'active'}}},'info'],
  [{markets:{}},'error'],
 ])assert.equal(transferStartFeedback(value).kind,kind);
 assert.match(transferStartFeedback({markets:{idefix:{state:'scheduled'},n11:{state:'error'}}}).message,/Bazı bağlantılar/);
});

test('Yeniden aktarım bildirimi yalnız sunucunun onayladığı açık işlemde çıkar',()=>{
 const path='/integrations/n11?company=7',data={action:'retry'},result={message:'Aktarım sıraya alındı.'};
 assert.deepEqual(saveFeedbackFor(path,'POST',result,data),{kind:'pending',message:result.message});
 assert.equal(saveFeedbackFor(path,'GET',result,data),null);
 assert.equal(saveFeedbackFor(path,'POST',{},data),null);
});

test('Genel yenileme, okuma başarılıysa başarı ve hata oluştuysa nedenini bildirir',async()=>{
 const updates=[],messages=[],errors=[];
 const node={classList:{add(){},remove(){}},isConnected:true};
 const document={querySelector:()=>({set textContent(value){updates.push(value);}})};
 const bind=fixture('page-refresh',{i18nT:t,currentLocale:()=> 'tr',workspaceIcon:()=>'',document,showFeedback:(m,options)=>messages.push([m,options.kind])},'bindPageRefresh');
 const app={querySelector:()=>node};
 const click=async refresh=>{bind(app,{refresh,current:()=>true,notify:message=>errors.push(message)});await node.onclick();};
 await click(async()=>false);
 await click(async()=>{throw new Error('Bağlantı kesildi.');});
 await click(async()=>true);
 assert.deepEqual(messages,[['Liste yenilendi.','success']]);
 assert.deepEqual(errors,['Bağlantı kesildi.']);
 assert.equal(updates.length,1);
 assert.equal(node.disabled,false);
});

function newRecordsFixture(request){
 const messages=[],calls=[];
 const start={},refresh={},status={classList:{toggle(){}}};
 const panel={setAttribute(){},querySelector:key=>({'[data-new-records-start]':start,'[data-new-records-refresh]':refresh,'[data-new-records-status]':status})[key]};
 const bind=fixture('customer-new-records',{api:async(...args)=>{calls.push(args);return request(...args);},confirmMutation:async()=>true,
  t,text:String.raw,showFeedback:(message,options)=>messages.push([message,options.kind])},'bindNewRecordsPanel');
 const load=bind(panel,'/endpoint',()=>true);
 return {messages,calls,start,refresh,status,load};
}

test('Yeni kayıtların ilk durumu sessiz, kuyruğa alındı bildirimi yalnız tıklama sonrası görünür',async()=>{
 const f=newRecordsFixture(async(path,method)=>method==='POST'?{run:{state:'queued'}}:{run:null});
 await f.load();
 assert.deepEqual(f.messages,[]);
 await f.start.onclick();
 await f.start.onclick();
 assert.equal(f.calls.filter(args=>args[1]==='POST').length,1);
 assert.deepEqual(f.messages,[['Yeni kayıtlar sıraya alındı.','pending']]);
 assert.equal(f.start.disabled,true);
 await f.refresh.onclick();
 assert.deepEqual(f.messages.at(-1),['Aktarım durumu güncellendi.','success']);
});

test('Yeni kayıtlar için eksik kuyruk onayı veya geçersiz ağ yanıtı olumlu gösterilmez',async()=>{
 const f=newRecordsFixture(async()=>({run:null}));
 await f.load();
 await f.start.onclick();
 assert.deepEqual(f.messages,[['Aktarımın kuyruğa alındığı doğrulanamadı.','error']]);
 assert.equal(f.start.disabled,false);
});

function inboxFixture(provider,request){
 const messages=[],renders=[],calls=[];
 const state={company:7,user:{id:3,role:'admin'},view:'inbox',data:{channels:[{name:'Koçtaş',status:'active'}]}};
 const click=fixture('inbox',{
  api:async(...args)=>{calls.push(args);return request(...args);},t,labelMap:value=>value,sourceAnsweredLabel,
  workspaceToken:value=>({company:value.company}),sameWorkspace:(value,token)=>value.company===token.company,
  selectedQuestionProvider:()=>provider,selectedQuestionSort:()=> 'newest',selectedInboxStatus:()=> 'waiting',
  showFeedback:(message,options)=>messages.push([message,options.kind,renders.length]),toast:message=>messages.push([message,'error',renders.length]),
  document:{createElement:()=>({}),head:{append(){}}},
 },'inboxClick');
 const button={dataset:{inbox:'local'},isConnected:true};
 return {messages,calls,button,state,refresh:()=>click(button,state,()=>renders.push(1))};
}

test('Pazaryeri soruları başarıyı tablodan sonra, Koçtaş sıra kabulünü bir kez gösterir',async()=>{
 for(const [provider,expected] of [['hepsiburada','success'],['koctas','pending']]){
  const f=inboxFixture(provider,async(path,method)=>method==='POST'?{jobId:'job'}:{available:true,items:[]});
  await Promise.all([f.refresh(),f.refresh()]);
  assert.equal(f.calls.filter(args=>args[1]==='POST').length,provider==='koctas'?1:0);
  assert.match(f.messages[0][0],/^Liste yenilendi/);
  assert.deepEqual(f.messages[0].slice(1),[expected,1]);
  assert.equal(f.messages.length,1);
  assert.equal(f.button.disabled,false);
 }
});

test('Pazaryeri sorularında başarısız okuma ve reddedilen tarama başarı olarak bildirilmez',async()=>{
 const f=inboxFixture('koctas',async(path,method)=>{
  if(method==='POST')throw new Error('Koçtaş bağlantısı kapalı.');
  return {available:true,items:[]};
 });
 await f.refresh();
 assert.deepEqual(f.messages,[['Koçtaş bağlantısı kapalı.','error',1]]);
 const unavailable=inboxFixture('n11',async()=>({available:false,items:[]}));
 await unavailable.refresh();
 assert.deepEqual(unavailable.messages,[['Soru listesi şu anda alınamıyor.','error',1]]);
});
