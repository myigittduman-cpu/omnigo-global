import test from 'node:test';
import assert from 'node:assert/strict';
import {paymentAmount,revealSavedPayment} from '../public/payment-input.js';

test('Türkçe tutarlar ve kuruşlar kayıpsız dönüştürülür; boş kalem sıfır sayılmaz',()=>{
 for(const [input,expected] of [['1.234,56',123456],['1234,56',123456],['1234.56',123456],['1.234',123400],['0,01',1],['0',0],[' 1 234,5 ',123450]])assert.equal(paymentAmount(input),expected);
 assert.equal(paymentAmount('',{optional:true}),null);
 assert.equal(paymentAmount('-1.234,50',{negative:true}),-123450);
 assert.equal(paymentAmount('12,5',{rate:true}),1250);
 assert.equal(paymentAmount('100',{rate:true}),10000);
});
test('Geçersiz, aşırı ve belirsiz tutarlar reddedilir',()=>{
 for(const value of ['', '1e3','12,345','1.23.45','abc','Infinity','-1','1000000001'])assert.throws(()=>paymentAmount(value));
 assert.throws(()=>paymentAmount('100,01',{rate:true}));
 assert.throws(()=>paymentAmount('1.234',{rate:true}));
});
test('Kaydedilen hakediş seçili dönemin dışında kaybolmaz; mevcut kapsam korunur',()=>{
 const state={company:7,view:'payments',month:'2026-10',paymentDay:'2026-10-04',dateRange:{company:7,from:'2026-10-01',to:'2026-10-31',automatic:false}};
 revealSavedPayment(state,{expectedDate:'2026-11-01',status:'pending'});
 assert.equal(state.month,'2026-11');assert.equal(state.paymentDay,'');assert.equal(state.dateRange.to,'2026-11-30');
 const range=state.dateRange;revealSavedPayment(state,{expectedDate:'2026-11-19',status:'pending'});assert.equal(state.dateRange,range);
 revealSavedPayment(state,{expectedDate:'2026-11-01',status:'paid',paidDate:'2028-02-29'});assert.equal(state.dateRange.to,'2028-02-29');
});
