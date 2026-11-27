// VİZYONA's permanent workspace ID; menu labels and marketplace names are not identifiers.
const skuQuantityCompanyId=1;
export const shipmentProductMode=companyId=>Number(companyId)===skuQuantityCompanyId?'skuQuantity':'fullName';

export const shipmentProductNames=items=>[...new Set(items.map(item=>String(item.productName??item.title??'').trim()).filter(Boolean))];
