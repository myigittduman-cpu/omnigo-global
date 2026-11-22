import test from 'node:test';import assert from 'node:assert/strict';
import {navigationRowSizes} from '../public/workspace-navigation-balance.js';
test('Gizlenen menülerden kalan öğeler boş satır bırakmadan dengelenir',()=>{
 assert.deepEqual(navigationRowSizes(14,7),[7,7]);assert.deepEqual(navigationRowSizes(13,7),[7,6]);assert.deepEqual(navigationRowSizes(8,7),[4,4]);assert.deepEqual(navigationRowSizes(7,7),[7]);assert.deepEqual(navigationRowSizes(9,4),[3,3,3]);
 for(const max of[4,7])for(let count=1;count<=20;count++){const rows=navigationRowSizes(count,max);assert.equal(rows.reduce((sum,n)=>sum+n,0),count);assert(Math.max(...rows)<=max);assert(Math.max(...rows)-Math.min(...rows)<=1);}
});
