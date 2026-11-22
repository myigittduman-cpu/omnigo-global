import test from 'node:test';
import assert from 'node:assert/strict';
import {conversationMarkup,inboxStatuses} from '../public/inbox-conversation.js';
import {marketplacePanels} from '../public/inbox-panels.js';
const record={id:12,provider:'Koçtaş',data:{recipients:[{}]},messages:[{sender:'CUSTOMER_USER',body:'<script>özel</script>',created:'2026-10-10'}],jobs:[]};
test('Yanıt sorunun altında ve güvenli; Koçtaş dosya eki açık',()=>{
 const html=conversationMarkup({record,draft:'Deneme',files:[{id:'safe',name:'<img>.pdf'}]});
 assert.match(html,/data-inline-reply/);assert.match(html,/name="attachments"[^>]*multiple >/);assert.ok(!html.includes('<script>'));assert.ok(!html.includes('<img>'));
 const panels=marketplacePanels([{id:1,threadId:12,provider:'Koçtaş',created:'2026-10-10',title:'Soru'}],[],new Set(),{id:12,html});
 assert.match(panels,/<\/button><section class="inbox-inline"/);
 assert.match(html,/class="inbox-dialog-history"[^>]*role="region"/);
 assert.match(html,/&lt;script&gt;özel&lt;\/script&gt;/);
 assert.equal(Object.keys(inboxStatuses).length,4);
});
test('Pazarama dosya eki kapalı; gönderilmiş yanıt tekrar gönderilemez',()=>{
 const html=conversationMarkup({record:{...record,provider:'Pazarama',data:{questionStatus:0,localReplyState:'sent'}}});
 assert.doesNotMatch(html,/name="attachments"|data-remove-attachment/);
 assert.match(html,/<textarea[^>]*disabled/);
 assert.match(html,/<button type="submit"[^>]*disabled[^>]*>Yanıtı gönder/);
});
