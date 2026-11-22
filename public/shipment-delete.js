import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
export function bindShipmentDelete({model,current,endpoint,paintTable,refresh,invalidate,canLeave}){
 return {async click(button){
  const restore=button.hasAttribute('data-shipment-restore');
  if(!restore&&!button.hasAttribute('data-shipment-delete'))return false;
  if(model.busy||model.writing||!canLeave())return true;
  const row=restore?model.data?.deleted:model.data?.items.find(item=>item.id===Number(button.dataset.shipmentDelete));
  if(!row)return true;
  model.writing=true;invalidate();model.message=restore?i18nT('Satır geri getiriliyor…'):i18nT('Satır siliniyor…');paintTable();
  try{await api(endpoint(row.id+(restore?'/restore':'/delete')),'POST',{version:row.version});if(current())model.message=restore?i18nT('Satır geri getirildi.'):i18nT('Satır silindi. Son silmeyi geri al düğmesiyle geri getirebilirsiniz.');}
  catch(error){if(current())model.message=error.message;}
  finally{model.writing=false;if(current())await refresh();}
  return true;
 }};
}
