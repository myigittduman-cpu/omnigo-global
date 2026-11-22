import test from 'node:test';
import assert from 'node:assert/strict';
import {shipmentMonthGroups,defaultShipmentMonth} from '../public/shipment-calendar.js';
import {shipmentMonthFilter} from '../public/shipments-view.js';
import {setLanguage} from '../public/i18n.js';

test('Shipment month filter lists month names in calendar order and preserves API month keys',()=>{
 setLanguage('tr');
 const [group]=shipmentMonthGroups(new Date('2026-10-31T12:00:00Z'));
 assert.equal(group.year,'2026');
 assert.deepEqual(group.months.map(month=>month.label),['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık']);
 assert.equal(group.months[9].value,'2026-10');assert.equal(group.months[9].disabled,false);
 assert.equal(group.months[0].disabled,true,'Existing backend history boundary is respected');
 const html=shipmentMonthFilter({month:'2026-10'});
 assert.match(html,/<select[^>]*data-shipment-month-filter/);
 assert.match(html,/<option value="2026-10" selected[^>]*>Ekim<\/option>/);
 assert.doesNotMatch(html,/<input|10\.2026/);
});
test('Default shipment month changes at Istanbul midnight, including the year boundary',()=>{
 assert.equal(defaultShipmentMonth(new Date('2026-10-31T20:59:59Z')),'2026-10');
 assert.equal(defaultShipmentMonth(new Date('2026-10-31T21:00:00Z')),'2026-11');
 const nextYear=new Date('2026-12-31T21:00:00Z');
 assert.equal(defaultShipmentMonth(nextYear),'2027-01');
 const group=shipmentMonthGroups(nextYear).find(group=>group.year==='2027');
 assert.equal(group.months[0].label,'Ocak');assert.equal(group.months[0].disabled,false);assert.equal(group.months[1].disabled,true);
});
