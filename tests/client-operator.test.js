import test from 'node:test';
import assert from 'node:assert/strict';
import {api,upload,setCsrf,setOperatorToken} from '../public/api.js';
import {startClientOperator,operatorNameError} from '../public/client-operator.js';
import {notificationList} from '../public/notification-view.js';

test('Ad soyad en az üç harf içerir; boşluk isteğe bağlıdır ve harf olarak sayılmaz',()=>{
 for(const value of ['Ali','AliVeli','Ali Veli','  Ayşe  Öztürk  ','A Bu','Işıl','O’Neil','Jose\u0301'])assert.equal(operatorNameError(value),'',value);
 for(const value of ['', 'Ab','A B','A-B','A\u0301 B\u0301','123','Ali1','<Ali>','Ali\nVeli','Ali\u200bVeli', 'A'.repeat(101),null])assert.notEqual(operatorNameError(value),'',String(value));
});
test('Kişi anahtarı yalnız sunucu yanıtından alınır; JSON ve belge yüklemelerine aynı oturum bağlamı gider',async()=>{
 const originalFetch=globalThis.fetch,seen=[];
 globalThis.fetch=async(url,options)=>{seen.push({url,...options});return {ok:true,json:async()=>url.endsWith('/session/operator')?{operatorName:'Ayşe Örnek',operatorToken:'server-token'}:{}};};
 try{
  setCsrf('csrf');setOperatorToken('old-token');
  const user=await startClientOperator({id:2,role:'client',name:'Vizyona'},'Ayşe Örnek');
  assert.equal(user.name,'Vizyona');assert.equal(user.operatorName,'Ayşe Örnek');assert.equal(seen[0].headers['X-Omnigo-Operator'],'');
  await api('/shipments/1','PUT',{version:2});await upload('/shipments/1/documents/invoice',new FormData());
  for(const request of seen.slice(1)){assert.equal(request.headers['X-Omnigo-Operator'],'server-token');assert.equal(request.headers['X-CSRF-Token'],'csrf');}
  await startClientOperator({id:1,role:'admin'},'');await api('/me');assert.equal(seen.at(-1).headers['X-Omnigo-Operator'],'');
  globalThis.fetch=async()=>({ok:false,status:400,json:async()=>({error:'Ad ve soyad gerekli.'})});
  await assert.rejects(startClientOperator({id:2,role:'client'},'Tekisim'),/Ad ve soyad gerekli/);
 }finally{globalThis.fetch=originalFetch;setOperatorToken('');setCsrf('');}
});
test('Yönetici bildiriminde firma, kişi ve yapılan işlem güvenli metin olarak görünür',()=>{
 const items=[{type:'support',id:1,company:'Vizyona',actorName:'Ayşe <Örnek>',operation:'created'},{type:'user',id:2,company:'Vizyona',actorName:'Mehmet Örnek',operation:'reply'},{type:'shipment',id:3,company:'Vizyona',actorName:'Yiğit Duman',operation:'status',rowId:12,provider:'n11',fields:['rowStatus']}];
 const html=notificationList({items});assert.match(html,/Ayşe &lt;Örnek&gt;/);assert.match(html,/Mehmet Örnek/);assert.match(html,/Yiğit Duman/);assert.equal(html.match(/Vizyona/g).length,3);assert.match(html,/durumu değiştirdi/);assert.match(html,/destek talebi oluşturdu/);
});
