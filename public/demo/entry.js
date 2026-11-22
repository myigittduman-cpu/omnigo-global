import './app.js';
const controls='#logout,[data-shipment-add],[data-shipment-undo],[data-shipment-restore],[data-shipment-edit-mode],[data-shipment-save],[data-shipment-status],[data-shipment-document],[data-shipment-select-all],[data-shipment-select],[data-support="new"],[data-support-status],.support-reply textarea,.support-reply [type="submit"],input[type="file"],[data-shipment-status-select],[data-shipment-status-save],[data-shipment-delete]';
function refreshDemoControls(){
 document.querySelectorAll(controls).forEach(element=>{element.disabled=true;element.title='Demo yalnızca örnek içerikleri incelemek içindir.';});
 document.querySelectorAll('.connection-status').forEach(element=>{element.textContent='Demo görünümü · Gerçek bağlantı veya otomatik aktarım yapılmaz.';});
 document.querySelectorAll('.channel-health strong').forEach(element=>{element.textContent='Örnek bağlantı durumu';});
 document.querySelectorAll('.context-sector').forEach(element=>{element.textContent='4 örnek mağaza · Tanıtım verileri';});
 document.querySelectorAll('a[href="/notification-settings.html"],a[href="/account-deletion.html"]').forEach(element=>element.remove());
}
let scheduled=false;
const observer=new MutationObserver(()=>{if(scheduled)return;scheduled=true;requestAnimationFrame(()=>{scheduled=false;observer.disconnect();refreshDemoControls();observer.observe(document.body,{childList:true,subtree:true});});});
refreshDemoControls();observer.observe(document.body,{childList:true,subtree:true});
