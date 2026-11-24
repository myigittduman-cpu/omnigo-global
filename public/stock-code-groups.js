const skuKey=value=>String(value??'').trim().toUpperCase();

export function stockCodeGroups(items,code=item=>item.productCode){
 const groups=new Map();
 for(const item of items){
  const displayCode=String(code(item)??'').trim();
  const key=skuKey(displayCode)||Symbol();
  if(!groups.has(key))groups.set(key,{code:displayCode,items:[]});
  groups.get(key).items.push(item);
 }
 return [...groups.values()];
}

export function stockCodeQuantity(items){
 let total=0;
 for(const {quantity} of items){
  if(!Number.isSafeInteger(quantity)||quantity<0)return null;
  total+=quantity;
  if(!Number.isSafeInteger(total))return null;
 }
 return total;
}
