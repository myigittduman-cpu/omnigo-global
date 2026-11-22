import {test} from 'node:test';
import assert from 'node:assert/strict';
import {notificationTime} from '../public/notification-time.js';
import {notificationList} from '../public/notification-view.js';
test('Bildirim tarihi Türkiye saatinde gösterilir; eksik zaman uydurulmaz',()=>{
 for(const value of ['2026-10-10T22:30:00Z','2026-10-10 22:30:00','2026-10-11T01:30:00+03:00'])assert.match(notificationTime(value),/11\.10\.2026 01:30/);
 for(const value of [null,undefined,'','geçersiz'])assert.equal(notificationTime(value),'');
 const html=notificationList({items:[{type:'order',id:1,provider:'n11',createdAt:'2026-10-10T22:30:00Z'}]});
 assert.match(html,/datetime="2026-10-10T22:30:00.000Z"/);assert.match(html,/11\.10\.2026 01:30/);
});
