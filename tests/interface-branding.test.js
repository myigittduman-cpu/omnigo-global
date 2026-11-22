import test from 'node:test';
import assert from 'node:assert/strict';
import {t,html,setLanguage} from '../public/i18n.js';
import {setCustomerLanguage} from '../public/customer-language.js';

test('Both account roles show Omnigo wording for application-owned labels',()=>{
 setLanguage('tr');
 for(const role of ['admin','client']){
  setCustomerLanguage(role);
  assert.equal(t('Yöneticinin planladığı ödeme kayıtları'),'Omnigo’nun planladığı ödeme kayıtları');
  assert.equal(t('Yöneticinin hakediş kaydı bekleniyor'),'Omnigo’nun hakediş kaydı bekleniyor');
  assert.equal(t('Yönetici tarafından girilen hakedişler'),'Omnigo tarafından girilen hakedişler');
  assert.equal(t('Yöneticinizle görüşün.'),'Omnigo ile görüşün.');
  assert.equal(t('YÖNETİCİ PORTALI'),'OMNIGO PORTALI');
 }
 setCustomerLanguage('admin');
});
test('Brand wording preserves role keys, implicit form values and customer content',()=>{
 const content='Yönetici adlı müşterinin notu';
 const markup=html`<option value="admin">Yönetici</option><option>Yönetici</option><p>${content}</p>`;
 assert.match(markup,/<option value="admin">Omnigo<\/option>/);
 assert.match(markup,/<option value="Yönetici">Omnigo<\/option>/);
 assert.ok(markup.includes('<p>'+content+'</p>'));
});
