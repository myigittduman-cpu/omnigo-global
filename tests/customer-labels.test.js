import test from 'node:test';
import assert from 'node:assert/strict';
import {notificationList} from '../public/notification-view.js';
import {healthResult} from '../public/connection-health.js';
test('Yönetici bildiriminde her kaydın kendi müşterisi önce ve güvenli metinle gösterilir',()=>{
 const html=notificationList({items:[{type:'order',id:1,provider:'hepsiburada',company:'Birinci <script>'},{type:'operation',id:2,provider:'n11',company:'İkinci & Firma',events:['cancelled']}]},'','all','all',true);
 const rows=html.split('<li>').slice(1);
 assert.match(rows[0],/notification-company">Birinci &lt;script&gt;/);
 assert.match(rows[1],/notification-company">İkinci &amp; Firma/);
 assert.ok(rows[0].indexOf('Birinci')<rows[0].indexOf('yeni sipariş'));
 assert.doesNotMatch(rows[0],/İkinci|<script>/);
 assert.doesNotMatch(notificationList({items:[{type:'resolved',id:1,company:'Birinci'}]}),/notification-company/);
});
test('Bağlantı görünümü seçili müşterinin adını bir kez gösterir',()=>{
 const html=healthResult({company:{id:1,name:'A & B'},page:0,items:[{companyId:1,companyName:'A & B',channel:'n11',storeName:'Mağaza',active:true,status:'ready'}]});
 assert.match(html,/<p class="report-note">A &amp; B · Son kontrol/);
 assert.equal((html.match(/A &amp; B/g)||[]).length,1);
 assert.match(html,/Pazaryerlerinin durumu/);
});
