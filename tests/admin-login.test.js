import test from 'node:test';
import assert from 'node:assert/strict';
import {accountTitle} from '../public/admin-login.js';

test('Hesap kartları kaldırılırken çalışma alanındaki hesap unvanları korunur',()=>{
 assert.equal(accountTitle({role:'client'}),'Müşteri hesabı');
 assert.equal(accountTitle({role:'admin',name:'Yiğit Duman'}),'E-Ticaret Uzmanı');
 assert.equal(accountTitle({role:'admin',name:'Diğer hesap'}),'Omnigo hesabı');
});