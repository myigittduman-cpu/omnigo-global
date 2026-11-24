import test from 'node:test';
import assert from 'node:assert/strict';
import {shipmentNotificationLabel,shipmentNotificationDetail} from '../public/shipment-notification.js';
import {inboxStatusFilters,selectedInboxStatus,conversationStatus} from '../public/inbox-status.js';
test('Sevkiyat bildirimi kim/satır/değişiklik ve eski-yeni bilgiyi güvenli gösterir',()=>{
 const event={actorName:'Ayşe <img>',rowId:41,provider:'n11',operation:'edit',fields:['phone'],createdAt:'2026-10-11T12:00:00Z',month:'2026-10',row:{customerName:'Örnek',orderedAt:'2026-10-11T12:00:00+03:00'},changes:[{field:'phone',before:'05000000000',after:'05001112233'},{field:'deliveryAddress',before:'Eski adres',after:'<script>Yeni adres</script>'}]};
 const label=shipmentNotificationLabel(event),detail=shipmentNotificationDetail(event);
 assert.match(label,/#41/);assert.match(label,/Ayşe &lt;img&gt;/);assert.match(label,/Tel no/);assert.doesNotMatch(label,/05001112233/);
 assert.match(detail,/05000000000/);assert.match(detail,/05001112233/);assert.match(detail,/Önceki bilgi/);assert.match(detail,/Yeni bilgi/);assert.doesNotMatch(detail,/<script>|<img>/);
});
test('Yanıt bekleyenler pazaryeri listesinden açılır, onay ve ret sekmeleri ayrı kalır',()=>{
 const html=inboxStatusFilters({inboxData:{waitingCount:560}});
 assert.doesNotMatch(html,/data-inbox-status="waiting"|560/);
 assert.match(html,/data-inbox-status="answered" aria-pressed="false"[^>]*>Onaylanan/);
 assert.match(html,/data-inbox-status="approval_pending"[^>]*>Onay bekleyen/);
 assert.match(html,/data-inbox-status="rejected"[^>]*>Reddedildi/);
 assert.doesNotMatch(html,/Yayınlandı|Tümü|Diğer durumlar/);
 assert.equal(selectedInboxStatus({inboxStatus:'all'}),'waiting');assert.equal(selectedInboxStatus({inboxStatus:'published'}),'waiting');
 assert.equal(conversationStatus({data:{questionStatus:0,localReplyState:'sent',replyNeeded:true}}),'answered');
 assert.equal(selectedInboxStatus({inboxStatus:'waiting'}),'waiting');
});
