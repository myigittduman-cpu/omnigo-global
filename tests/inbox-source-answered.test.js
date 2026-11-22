import test from 'node:test';
import assert from 'node:assert/strict';
import {conversationMarkup} from '../public/inbox-conversation.js';
import {inboxQuestionList} from '../public/inbox-question-list.js';
import {activeReplyState,replyDeliveryFeedback,watchReplyDelivery} from '../public/inbox-reply-delivery.js';

const job={id:'job',kind:'reply',state:'source_answered',provider:'Hepsiburada',created:'2026-11-20T10:20:00Z',error:'Önceki hata metni'};
const row={id:10,threadId:4,provider:job.provider,created:job.created,title:'Ürün',question:'Örnek soru',status:'answered',replyState:job.state,replyError:job.error};
const data={available:true,items:[row],jobs:[job],total:1};
const state={user:{id:10},company:1,inboxProvider:'hepsiburada',inboxJobsOpen:true,inboxData:data,data:{channels:[{name:job.provider}]}};
const record={id:row.threadId,provider:job.provider,status:'answered',canReply:false,replySupported:true,
 data:{questionStatus:1,recipients:[]},messages:[],jobs:[job]};
const oldError=/Önceki hata metni|Gönderilemedi|Yeniden dene|Yanıtınız gönderildi/;

test('Yanıtlanan sorunun satırı pazaryeri sonucunu ve inceleme eylemini gösterir',()=>{
 const list=inboxQuestionList(data,state);
 assert.match(list,/data-reply-state="source_answered"/);
 assert.match(list,/Pazaryerinde yanıtlandı/);
 assert.match(list,/>İncele</);
 assert.doesNotMatch(list,oldError);
 assert.doesNotMatch(list,/badge--problem|>Yanıtla</);
});

test('Konuşma eski hata veya onay formu göstermeden bu denemeden gönderim yapılmadığını açıklar',()=>{
 const markup=conversationMarkup({record});
 assert.match(markup,/Pazaryerinde yanıtlandı/);
 assert.match(markup,/Bu denemeden yeni yanıt gönderilmedi\./);
 assert.match(markup,/<textarea[^>]*disabled/);
 assert.doesNotMatch(markup,oldError);
 assert.doesNotMatch(markup,/data-resolve-inbox-job/);
});

test('Son mesaj işlemlerindeki kayıt, filtreler arasında aynı açıklamayı verir',async()=>{
 globalThis.document={createElement:()=>({}),head:{append(){}}};
 let inboxContent;
 try { ({inboxContent}=await import('../public/inbox.js')); }
 finally { delete globalThis.document; }
 for(const inboxStatus of ['waiting','answered','approval_pending','rejected']){
  const markup=inboxContent({...state,inboxStatus});
  const jobs=markup.slice(markup.indexOf('<details class="inbox-jobs"'));
  assert.match(jobs,/Pazaryerinde yanıtlandı/);
  assert.match(jobs,/Bu denemeden yeni yanıt gönderilmedi\./);
  assert.doesNotMatch(jobs,oldError);
 }
});

test('Gönderim takibi salt okunur sorgudan sonra doğru durumu bir kez bildirip durur',async t=>{
 let queued,completed=0;
 t.mock.method(globalThis,'setTimeout',fn=>{assert.equal(queued,undefined);queued=fn;return 1;});
 const requests=t.mock.method(globalThis,'fetch',async(path,opts)=>{
  assert.equal(path,'/api/inbox/threads/4?company=1');
  assert.equal(opts.method,'GET');
  return {ok:true,json:async()=>record};
 });
 assert.equal(activeReplyState('source_answered'),false);
 assert.deepEqual(replyDeliveryFeedback('source_answered'),{kind:'info',message:'Pazaryerinde yanıtlandı. Bu denemeden yeni yanıt gönderilmedi.'});
 watchReplyDelivery(state,row.threadId,job.id,{onUpdate(actual){assert.deepEqual(actual,record);completed++;},onTimeout:()=>assert.fail('unexpected timeout')});
 const first=queued;queued=undefined;await first();
 assert.equal(completed,1);assert.equal(requests.mock.callCount(),1);assert.equal(queued,undefined);
});

test('Gerçek başarısız, bekleyen ve onaylanan gönderimler eski anlamlarını korur',()=>{
 assert.match(replyDeliveryFeedback('failed').message,/gönderilemedi/);
 assert.equal(activeReplyState('verifying'),true);
 assert.equal(replyDeliveryFeedback('confirmed_sent').kind,'success');
 const failedJob={...job,state:'failed'};
 assert.match(conversationMarkup({record:{...record,jobs:[failedJob]}}),/Önceki hata metni/);
 const open=conversationMarkup({record:{...record,status:'waiting',canReply:true,data:{questionStatus:0,recipients:[]}}});
 assert.doesNotMatch(open,/<textarea[^>]*disabled/);
});
