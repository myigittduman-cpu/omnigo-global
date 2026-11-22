import test from 'node:test';
import assert from 'node:assert/strict';
import {scrollInventoryTable} from '../public/inventory-page-scroll.js';

function fixture(position = 'sticky') {
 const calls = [];
 const header = {getBoundingClientRect: () => ({bottom: 180})};
 const root = {
  isConnected: true,
  querySelector: () => ({getBoundingClientRect: () => ({top: 420})}),
  closest: () => ({querySelector: () => header}),
 };
 const viewport = {scrollY: 900, getComputedStyle: () => ({position}), scrollTo: value => calls.push(value)};
 return {root, viewport, calls};
}

test('Sayfa değişiminde tablo başı sabit menünün altında görünür', () => {
 const {root, viewport, calls} = fixture();
 scrollInventoryTable(root, viewport);
 assert.deepEqual(calls, [{top: 1128, behavior: 'instant'}]);
});

test('Mobilde sabit olmayan menü için gereksiz boşluk bırakılmaz', () => {
 const {root, viewport, calls} = fixture('static');
 scrollInventoryTable(root, viewport);
 assert.equal(calls[0].top, 1308);
});

test('Kullanıcı başka ekrana geçtiğinde kaydırma yapılmaz', () => {
 const {root, viewport, calls} = fixture();
 root.isConnected = false;
 scrollInventoryTable(root, viewport);
 assert.equal(calls.length, 0);
});
