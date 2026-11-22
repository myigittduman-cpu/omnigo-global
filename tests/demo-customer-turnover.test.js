import test from 'node:test';
import assert from 'node:assert/strict';
import {demoFinancialView} from '../public/demo/visibility.js';

test('Demo ciro kalemleri iptal sonrası gösterilir ve iade ikinci defa düşülmez',()=>{
 const raw={grossCents:20000,cancelCents:7000,returnCents:5000,orders:4,quantity:5,cancelOrders:1,cancelQty:2,averageCents:5000};
 const row=demoFinancialView(raw,true);
 assert.equal(row.grossCents,13000);
 assert.equal(row.netCents,13000);
 assert.equal(row.panelSalesCents,13000);
 assert.equal(row.orders,3);
 assert.equal(row.quantity,3);
 assert.equal(row.averageCents,4333);
 assert.equal(row.turnoverDisplayBasis,'after_cancellations');
 for(const key of ['cancelCents','cancelOrders','cancelQty','returnCents'])assert.equal(key in row,false);
 assert.equal(demoFinancialView(raw).grossCents,20000);
});
