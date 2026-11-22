import test from 'node:test';
import assert from 'node:assert/strict';
import {paymentMonthRange,paymentMonthFilter,bindPaymentMonthFilter} from '../public/payment-month-filter.js';
import {workspaceRange,workspaceDateRange,dashboardQuery} from '../public/workspace-date-range.js';
import {now} from '../public/ui.js';

const state=()=>({user:{id:5},company:7,contextRevision:1,view:'payments',month:'2026-01',dateRange:{company:7,from:'2026-08-04',to:'2026-09-03',automatic:false}});

test('Hakediş açılışı başka sayfanın aralığı yerine içinde bulunulan tam ayı kullanır',()=>{
 const model=state(),custom={...model.dateRange};
 assert.deepEqual(paymentMonthRange(model,'2026-10-31'),{company:7,from:'2026-10-01',to:'2026-10-31',automatic:true});
 assert.deepEqual(paymentMonthRange(model,'2026-11-01'),{company:7,from:'2026-11-01',to:'2026-11-30',automatic:true});
 assert.deepEqual(model.dateRange,custom);
 model.view='overview';assert.deepEqual(workspaceRange(model),custom);
});

test('Seçilen hakediş ayı korunur, artık yıl ve yıl geçişi doğru hesaplanır',()=>{
 const model=state();paymentMonthRange(model,'2026-10-31');
 model.paymentMonth.month='2024-02';model.paymentMonth.automatic=false;
 assert.equal(paymentMonthRange(model,'2026-11-01').to,'2024-02-29');
 model.paymentMonth.month='2026-12';assert.equal(paymentMonthRange(model).to,'2026-12-31');
 model.paymentMonth.month='2027-01';assert.equal(paymentMonthRange(model).from,'2027-01-01');
 assert.match(dashboardQuery(model),/month=2027-01&from=2027-01-01&to=2027-01-31/);
});

test('Müşteri, kullanıcı veya oturum değişiminde eski ay taşınmaz',()=>{
 for(const mutate of [model=>model.company=8,model=>model.user.id=6,model=>model.contextRevision++]){
  const model=state();paymentMonthRange(model,'2026-10-31');model.paymentMonth.month='2024-02';model.paymentMonth.automatic=false;
  mutate(model);assert.equal(paymentMonthRange(model,'2026-11-01').from,'2026-11-01');
 }
});

test('Hakedişte yalnız isimli ay seçimi bulunur; tarih kutuları, görünür Ay ve Filtrele olmaz',()=>{
 const model=state(),markup=workspaceDateRange(model);
 assert.match(markup,/data-payment-month-filter/);assert.match(markup,new RegExp('<option value="'+now().slice(0,7)+'" selected'));
 assert.doesNotMatch(markup,/<button|<input|<span>Ay|Filtrele|Başlangıç|Bitiş/);
 assert.match(markup,/>Ocak<\/option>/);assert.match(markup,/>Aralık<\/option>/);
});

test('Ay seçimi ek bir buton olmadan raporu yeniler ve eski gün seçimini temizler',async()=>{
 const model=state();paymentMonthFilter(model);model.paymentDay='2026-10-03';
 let changed,loads=0;
 const select={value:'2027-01',disabled:false,isConnected:true,addEventListener:(name,handler)=>{assert.equal(name,'change');changed=handler;},setAttribute(){},removeAttribute(){}};
 bindPaymentMonthFilter({querySelector:()=>select},model,async propagate=>{assert.equal(propagate,true);loads++;assert.equal(select.disabled,true);assert.equal(workspaceRange(model).to,'2027-01-31');});
 await changed();assert.equal(loads,1);assert.equal(model.paymentDay,'');assert.equal(select.disabled,false);
});
