import test from 'node:test';
import assert from 'node:assert/strict';
import {applicationPageMarkup} from '../public/application-page-builder.js';
import {customPagesContent} from '../public/custom-pages.js';
import fs from 'node:fs';
import {loadCustomPages} from '../public/custom-page-data.js';

test('özel sayfa servis hatası ana çalışma alanını engellemez; oturum ve erişim hataları gizlenmez',async()=>{
 const source=fs.readFileSync(new URL('../public/app.js',import.meta.url),'utf8');
 assert.match(source,/loadCustomPages\(api,state.company\)/);
 const failure=status=>async()=>{throw Object.assign(new Error('Kontrol'),{status});};
 assert.deepEqual(await loadCustomPages(failure(503),1),{items:[],unavailable:true});
 for(const status of [401,403])await assert.rejects(loadCustomPages(failure(status),1),error=>error.status===status);
});

test('özel sayfa metni güvenli biçimde oluşturulur',()=>{
 const markup=applicationPageMarkup({layout:'wide',content:'Başlık\n<script>alert(1)</script>',notice:'<b>Not</b>',actionLabel:'Aç',actionUrl:'javascript:alert(1)'});
 assert.match(markup,/custom-page--wide/);
 assert.match(markup,/&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
 assert.match(markup,/&lt;b&gt;Not&lt;\/b&gt;/);
 assert.doesNotMatch(markup,/href=/);
});

test('dış bağlantı yeni sekmede güvenli açılır',()=>{
 const markup=applicationPageMarkup({layout:'standard',content:'İçerik',actionLabel:'Doküman',actionUrl:'https://example.com/help'});
 assert.match(markup,/target="_blank" rel="noopener"/);
});

test('yayımlanan sayfalar ortak sekme düzeninde gösterilir',()=>{
 const state={user:{role:'client',companyId:1},company:1,data:{customPages:{items:[
  {slug:'duyurular',title:'Duyurular',summary:'Güncel bilgi',layout:'standard',content:'Birinci sayfa',role:'both',scope:'all'},
  {slug:'rehber',title:'Rehber',summary:'',layout:'two-column',content:'İkinci sayfa',role:'both',scope:'all'}
 ]}}};
 const markup=customPagesContent(state);
 assert.equal(state.customPageSlug,'duyurular');
 assert.match(markup,/data-custom-page="duyurular" aria-pressed="true"/);
 assert.match(markup,/data-custom-page="rehber"/);
 assert.match(markup,/Birinci sayfa/);
});
