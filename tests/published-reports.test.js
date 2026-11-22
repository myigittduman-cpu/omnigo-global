import test from 'node:test';
import assert from 'node:assert/strict';
import {content} from '../public/views.js';
test('Müşteri yayımlanan raporu açabilir; düzenleme yok ve içerik HTML olarak çalışmaz',()=>{
 const state={user:{role:'client'},view:'reports',data:{reports:[{id:1,title:'<script>başlık</script>',content:'<img src=x onerror=alert(1)>',month:'2026-10',status:'published'}]}};
 const html=content(state);assert.match(html,/data-report="1"/);assert.doesNotMatch(html,/data-edit|data-add|<script>|<img src=x/);assert.match(html,/&lt;img/);
 assert.match(content({...state,user:{role:'admin'}}),/data-edit="reports"/);
 assert.match(content({...state,data:{reports:[]}}),/Ekibiniz raporu yayımladığında/);
});
