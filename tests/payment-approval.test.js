import test from 'node:test';
import assert from 'node:assert/strict';
import {paymentIsApproved,canApprovePayment} from '../public/payment-approval.js';
import {receivedPaymentsView} from '../public/received-payments-view.js';
import {paymentReceiptPayload} from '../public/payment-receipt.js';
import {canDeletePayment} from '../public/payment-delete.js';
import {paymentLabel,paymentRecords} from '../public/payment-records.js';
import {paymentDayView} from '../public/payment-day-view.js';
import {mutationPrompt} from '../public/action-confirmation.js';
import {saveFeedbackFor} from '../public/save-feedback-policy.js';
import {receivedPaymentAmount} from '../public/payment-amount.js';
const admin={user:{role:'admin'}},client={user:{role:'client'}};
const paid={id:3,version:1,channel:'idefix',status:'paid',expectedDate:'2026-11-03',paidDate:'2026-11-03',expectedCents:100000,actualCents:90000,confirmation:'Banka'};
const done={...paid,version:2,paymentApproval:{at:'2026-11-07T00:00:00Z',userId:1}};
const report=items=>({from:'2026-11-01',to:'2026-11-30',items});
test('Tutarın yanındaki tik yalnız yönetici onaylı gerçek ödemeyi gösterir',()=>{
 assert.match(receivedPaymentAmount(done),/payment-amount-line[^>]*>[^<]+<span class="payment-approved-check"/);
 for(const item of [paid,{...paid,verification:'confirmed'},{...done,status:'pending'},{...done,integration:'n11'}]){
  assert.doesNotMatch(receivedPaymentAmount(item),/payment-approved-check/);
 }
 for(const manage of [false,true]){
  assert.match(receivedPaymentsView(report([done]),1,manage),/payment-amount-line[^>]*>[^<]+<span class="payment-approved-check"/);
  assert.match(paymentDayView([done],manage),/payment-amount-line[^>]*>[^<]+<span class="payment-approved-check"/);
 }
});
test('Onay yalnız yöneticide ve tamamlanmamış banka kaydında sunulur',()=>{
 assert.equal(canApprovePayment(paid,admin),true);
 for(const item of [done,{...paid,status:'pending'},{...paid,integration:'idefix'},{...paid,archivedAt:'x'},
  {...paid,paidDate:''},{...paid,actualCents:null},{...paid,version:undefined}])assert.equal(canApprovePayment(item,admin),false);
 assert.equal(canApprovePayment(paid,client),false);
 assert.equal(paymentIsApproved(paid),false);assert.equal(paymentIsApproved(done),true);
 const before=receivedPaymentsView(report([paid]),1,true,'pending');assert.match(before,/data-received-receipt="3"/);assert.match(before,/data-received-approve="3"/);assert.doesNotMatch(before,/payment-approved-check/);
 const after=receivedPaymentsView(report([done]),1,true);assert.match(after,/payment-approved-row/);assert.match(after,/payment-approved-check[^>]*>✓/);assert.match(after,/>Ödendi</);assert.match(after,/payment-difference-note/);assert.doesNotMatch(after,/data-received-(?:receipt|approve)=/);
 const customer=receivedPaymentsView(report([paid,done]),1,false);assert.doesNotMatch(customer,/data-received-(?:receipt|approve)=/);assert.match(customer,/>Ödendi</);
});
test('Onaylı kaydın düzeltilmesi yöneticiye açılır, banka girişi ayrı eylem olarak kalır',()=>{
 assert.equal(canDeletePayment(done,admin),false);
 assert.throws(()=>paymentReceiptPayload(done,{},'2026-11-07'),/girilemez/);
 assert.equal(paymentLabel(done),'Ödendi');
 assert.doesNotMatch(paymentRecords([done],true),/data-calendar-(?:edit|receipt)=/);
 assert.match(receivedPaymentsView(report([done]),1,true),/data-received-edit="3"/);
 assert.doesNotMatch(paymentDayView([done],true),/data-day-payment-receipt=/);
});
test('Onay sorusu ve başarı bildirimi ortak kurumsal akışı kullanır',()=>{
 const path='/records/payments/3/approve?company=1';assert.match(mutationPrompt(path,'POST'),/^Ödemeyi onaylıyor musunuz\?/);
 assert.equal(saveFeedbackFor(path,'POST',done).kind,'success');
 assert.equal(saveFeedbackFor(path,'GET',done),null);
});
