/** Keep dependent controls in sync with the server's grant validation. */
export function applyPermissionChoice(grants,page,action,enabled){
 const rights=grants[page];
 if(!rights)return;
 rights[action]=enabled;
 if(action==='view'&&!enabled){
  for(const key of Object.keys(rights))rights[key]=false;
  return;
 }
 if(page==='shipments'){
  if(action==='bulkStatus'&&enabled){rights.selectRows=true;rights.status=true;}
  if(['selectRows','status'].includes(action)&&!enabled)rights.bulkStatus=false;
 }
 if(page==='inventory'){
  if(action==='editPurchasePrice'&&enabled)rights.viewPurchasePrice=true;
  if(action==='viewPurchasePrice'&&!enabled)rights.editPurchasePrice=false;
 }
 if(!Object.hasOwn(rights,'documents'))return;
 if(['upload','replaceDocuments','deleteDocuments'].includes(action)&&enabled)rights.documents=true;
 if(action==='documents'&&!enabled){
  for(const key of ['upload','replaceDocuments','deleteDocuments'])if(Object.hasOwn(rights,key))rights[key]=false;
 }
 if(action==='replaceDocuments'&&enabled)rights.upload=true;
 if(action==='upload'&&!enabled)rights.replaceDocuments=false;
}

export const permissionChoiceValue=control=>control.dataset.permission==='view'?control.value==='shown':control.checked;
