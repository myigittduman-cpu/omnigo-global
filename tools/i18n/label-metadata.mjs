import fs from 'node:fs/promises';
import {parse} from '../../work/i18n-tooling/package/dist/acorn.mjs';
// Only top-level maps of display strings; no event handlers, requests or data mutation.
const allowed={
 'app.js':['names'],
 'orders-view.js':['states'],
 'operation-notification.js':['labels'],
 'notification-category.js':['notificationCategories'],
 'payment-breakdown.js':['amountLabels'],
 'payment-templates.js':['paymentSections'],
 'support.js':['labels'],
 'shipment-notification.js':['labels','actions'],
 'connection-health.js':['labels'],
 'inbox-conversation.js':['labels'],
 'inbox.js':['labels'],
 'inbox-status.js':['inboxStatuses'],
 'returns-view.js':['returnStatuses'],
 'shipment-documents.js':['labels']
};
const selected=process.argv.slice(2);
if(!selected.length)throw new Error('Specify reviewed presentation modules explicitly');
const folder='verification/localization-20261025/before-label-metadata';await fs.mkdir(folder,{recursive:true});
for(const file of selected){
 if(!allowed[file])throw new Error('Module not allowlisted');
 const source=await fs.readFile('public/'+file,'utf8'),tree=parse(source,{ecmaVersion:'latest',sourceType:'module'}),changes=[];
 for(const entry of tree.body){const statement=entry.type==='ExportNamedDeclaration'?entry.declaration:entry;if(statement?.type!=='VariableDeclaration')continue;
  for(const declaration of statement.declarations){if(!allowed[file].includes(declaration.id.name))continue;
   if(declaration.init.type!=='ObjectExpression'||declaration.init.properties.some(p=>p.computed||p.type!=='Property'||p.value.type!=='Literal'||typeof p.value.value!=='string'))throw new Error('Not a static label dictionary');
   changes.push({start:declaration.init.start,end:declaration.init.end});
  }
 }
 if(changes.length!==allowed[file].length)throw new Error('Missing declaration');
 await fs.writeFile(`${folder}/${file}`,source,{flag:'wx'});
 let result=source;for(const change of changes.sort((a,b)=>b.start-a.start))result=result.slice(0,change.start)+'localizedLabels('+result.slice(change.start,change.end)+')'+result.slice(change.end);
 result="import {labelMap as localizedLabels} from './i18n.js';\n"+result;
 parse(result,{ecmaVersion:'latest',sourceType:'module'});await fs.writeFile('public/'+file,result);
 console.log(file+': display labels only, original saved');
}
