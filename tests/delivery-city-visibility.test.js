import test from 'node:test';import assert from 'node:assert/strict';
import {deliveryCityFilter} from '../public/delivery-city-filter.js';
import {deliveryWorkspaceScope} from '../public/delivery-scope.js';
test('Tekrarlanan şehir açılır alanı yalnız Vizyona çalışma alanında kaldırılır',()=>{
 for(const role of ['admin','client'])for(const company of [1,2,4,999]){
  const scope=deliveryWorkspaceScope({company,user:{role},data:{}}),markup=deliveryCityFilter({...scope,city:'Ankara'});
  assert.equal(markup.includes('data-city-filter'),company!==1);
 }
 assert.match(deliveryCityFilter({city:'Ankara'}),/data-city-filter/);
});
