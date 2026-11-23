import test from 'node:test';
import assert from 'node:assert/strict';
import {returnsView} from '../public/returns-view.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';
const model={provider:'all',status:'all',search:'',page:0,busy:false,error:'',data:{items:[],total:0,sources:[]}};
test('İade ekranı eksik bağlantıda sıfır talep iddiasında bulunmaz',()=>{
 const html=returnsView({...model,data:{items:[],total:0,sources:[{provider:'Pazarama',state:'partial',coverage:'completed_only',lastSuccessAt:null}]}});
 assert.match(html,/Bekleyen iade talepleri henüz/);assert.match(html,/Bağlantı kapsamı için aktarım ayrıntılarına/);
});
test('Talep tutarı, eksik tarih ve kaynak metinleri güvenli gösterilir',()=>{
 const html=returnsView({...model,data:{total:1,sources:[],items:[{provider:'n11',orderNumber:'00123',id:'5',createdAt:null,quantity:null,amountCents:null,group:'pending',statusLabel:'Yeni talep',product:'<img onerror="alert(1)">',reason:'<script>x</script>'}]}});
 assert.match(html,/00123/);assert.match(html,/Bildirilmedi/);assert.match(html,/&lt;script&gt;/);
 assert.doesNotMatch(html,/Ürün \/ satır|onerror|<script>|₺0/);assert.equal([...html.matchAll(/<th scope="col">/g)].length,9);
});
test('Ağ hatasında boş liste başarı gibi gösterilmez',()=>{
 const html=returnsView({...model,error:'Bağlantı kurulamadı'});assert.match(html,/Liste alınamadı/);assert.match(html,/Bağlantı kurulamadı/);
});
test('İptal ve iade menüsü yalnız yöneticiye görünür ve günü bilinen tarihe saat eklenmez',()=>{
 const names={channels:'Pazaryerlerim',returns:'İade Talepleri'};
 assert.match(workspaceNavigation(names,{user:{id:1,role:'admin'},view:'returns'}),/data-nav="returns"/);
 assert.doesNotMatch(workspaceNavigation(names,{user:{id:2,role:'client'},view:'channels'}),/data-nav="returns"/);
 const html=returnsView({...model,data:{total:1,sources:[],items:[{provider:'n11',orderNumber:'1',id:'2',createdAt:'2026-10-18T00:00:00+03:00',dateOnly:true,quantity:1,amountCents:100,group:'cancelled',statusLabel:'Talep iptal edildi'}]}});
 assert.doesNotMatch(html,/00:00/);
});
