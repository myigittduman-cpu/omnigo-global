/** A presentation-only fifth choice. Persist the original arithmetic mode unchanged. */
export const managedInventoryMode='marketplace-managed';
export function inventoryModeValue(settings={},option){return settings.marketplaceManagement&&option?managedInventoryMode:settings.mode||'marketplace';}
export function inventoryModeSelection(value,option){
 if(value===managedInventoryMode){if(!option||!['horoz','both'].includes(option.baseMode))throw new TypeError('Bu müşteri için pazaryeri yönetimi açık değil.');return {mode:option.baseMode,marketplaceManagement:true};}
 if(!['horoz','both','store','marketplace'].includes(value))throw new TypeError('Stok modelini kontrol edin.');
 return {mode:value};
}
