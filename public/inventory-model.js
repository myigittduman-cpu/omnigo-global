import {inventoryModelDefinition} from './inventory-model-definitions.js';
export function inventoryModel(mode='marketplace',settings={}){
 const horoz=mode==='horoz',store=mode!=='marketplace',online=mode!=='store';
 return {horoz,store,online,showStore:store&&!horoz,showTotal:!horoz,
  stockColumns:horoz?1:1+Number(store)+Number(online),
  summary:inventoryModelDefinition(mode).label+(settings.marketplaceManagement&&['horoz','both'].includes(mode)?' + pazaryeri yönetimi':''),
  explanation:inventoryModelDefinition(mode).description+(settings.marketplaceManagement?' Pazaryerine stok gönderme işlemi yalnız Omnigo yöneticilerine açıktır.':'')};
}
