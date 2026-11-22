import test from 'node:test';
import assert from 'node:assert/strict';
import {marketplacePanels} from '../public/inbox-panels.js';

test('Pazaryeri panelleri kaynağı, firma etiketini ve konuşma hedefini korur',()=>{
 const rows=[{provider:'Koçtaş',company:'Firma A',id:10,threadId:4,title:'Boyutu nedir?',preview:'<script>örnek</script>',created:'2026-10-08T09:00:00Z',unread:true,replyNeeded:true},{provider:'Pazarama',company:'Firma B',id:11,threadId:5,title:'Teslimat',preview:'Ne zaman?',created:'2026-10-08T10:00:00Z',unread:false}];
 const html=marketplacePanels(rows,[{name:'Koçtaş'},{name:'Pazarama'},{name:'n11'}]);
 assert.equal((html.match(/class="inbox-marketplace"/g)||[]).length,2);
 assert.match(html,/data-inbox-thread="4" data-inbox-read="10"/);
 assert.match(html,/data-inbox-thread="5" data-inbox-read="11"/);
 assert.match(html,/Firma A/);assert.match(html,/Firma B/);
 assert.match(html,/1 okunmamış/);
 assert.ok(!html.includes('<script>'));assert.match(html,/&lt;script&gt;/);
 assert.doesNotMatch(html,/aria-label="n11 mesajları"/);
});

test('Boş sayfa toplam mesaj sayısı olarak sunulmaz',()=>{
 const html=marketplacePanels([],[{name:'Koçtaş',status:'active'},{name:'PTT AVM',status:'active'}]);
 assert.match(html,/Bu filtrede soru bulunmuyor/);
 assert.match(html,/Soru aktarımı henüz etkin değil · PTT AVM/);
 assert.doesNotMatch(html,/class="inbox-marketplace"|0 kayıt/);
});
