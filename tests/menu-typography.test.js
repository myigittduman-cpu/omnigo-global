import test from 'node:test';
import assert from 'node:assert/strict';
import {applyMenuAppearance,menuStyles} from '../public/menu-appearance.js';
import {applyPreferences} from '../public/user-preferences.js';
test('Tüm menü vurguları özgün uygulama yazı tipini kullanır; tema ve kayıtlı seçim korunur',()=>{
 const links=new Map(),root={dataset:{},ownerDocument:{head:{append:link=>links.set(link.id,link)},getElementById:id=>links.get(id),createElement:()=>({addEventListener(){}}),dispatchEvent(){}}};
 for(const menuStyle of Object.keys(menuStyles))for(const theme of ['classic-navy','light','burgundy','petrol-smoke','graphite-copper']){
  applyPreferences({theme,menuStyle},root);assert.equal(root.dataset.theme,theme);assert.equal(root.dataset.menuStyle,menuStyle);
 }
 assert.deepEqual([...links.keys()],['menu-appearance']);
 applyMenuAppearance(undefined,root);assert.equal(root.dataset.menuStyle,'modern');assert.equal(links.size,1);
});
