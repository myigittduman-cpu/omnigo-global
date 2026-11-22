import test from 'node:test';
import assert from 'node:assert/strict';
import {overviewDailyData} from '../public/balanced-overview.js';
import {applyWorkspaceDesign,workspaceDesign} from '../public/workspace-design-state.js';
test('Günlük grafik aktarılan verileri kullanır, eksik bir günü sıfıra dönüştürmez',()=>{
 const data={channels:[{name:'Hepsiburada',status:'active'},{name:'n11',status:'active'},{name:'Koçtaş',status:'inactive'}],marketplaceSales:{channels:[
  {channel:'Hepsiburada',available:true,days:[{date:'2026-10-30',turnoverCents:11550,amountCents:10050},{date:'2026-10-31',turnoverCents:5000}]},
  {channel:'n11',available:true,days:[{date:'2026-10-30',turnoverCents:8450,amountCents:-2050}]},
  {channel:'Koçtaş',available:true,days:[{date:'2026-10-30',amountCents:9000000}]}
 ]}};
 const before=JSON.stringify(data),result=overviewDailyData(data);
 assert.deepEqual(result.days,[{date:'2026-10-30',amountCents:20000},{date:'2026-10-31',amountCents:null}]);
 assert.equal(result.known,2);assert.equal(result.total,2);assert.equal(JSON.stringify(data),before);
});
test('Pazaryeri kapsamı ve doğrulanmış ciro günlük grafiğe taşınır',()=>{
 const data={channels:[{name:'Hepsiburada',status:'active'},{name:'idefix',status:'active'}],marketplaceSales:{channels:[{channel:'Hepsiburada',available:true,refundsVerified:true,days:[{date:'2026-10-31',amountCents:1000}]}]}};
 assert.equal(overviewDailyData(data).known,1);
 data.marketplaceSales.channels.push({channel:'idefix',available:true,refundsVerified:false,days:[{date:'2026-10-31',turnoverCents:500}]});
 assert.deepEqual(overviewDailyData(data),{days:[{date:'2026-10-31',amountCents:1500}],known:2,total:2});
 assert.deepEqual(overviewDailyData({channels:[],sales:[]}).days,[]);
});
test('Geciken ağ yanıtı yeni tasarım seçimini geri çeviremez',()=>{
 assert.equal(applyWorkspaceDesign({mode:'classic',version:1}),true);
 assert.equal(applyWorkspaceDesign({mode:'balanced',version:0}),false);
 assert.deepEqual(workspaceDesign(),{mode:'classic',version:1});
 assert.equal(applyWorkspaceDesign({mode:'balanced',version:2}),true);
 assert.equal(applyWorkspaceDesign({mode:'other',version:3}),false);
 assert.deepEqual(workspaceDesign(),{mode:'balanced',version:2});
});
