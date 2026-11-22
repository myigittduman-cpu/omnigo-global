/** Presentation only: keep source lines and financial amounts untouched. */
export function summarizeOrderProducts(products = []) {
 const groups = new Map();
 for (const item of products) {
  // A package/line ID identifies a shipment, not a different product.
  const identity = ['title','sku','barcode','productId','variantId','variant','color','size']
   .map(field => item[field] ?? null);
  const key = JSON.stringify(identity);
  const quantity = Number.isSafeInteger(item.quantity) && item.quantity >= 0 ? item.quantity : null;
  if (!groups.has(key)) groups.set(key, {title:item.title, quantity});
  else {
   const group = groups.get(key), total = group.quantity === null || quantity === null ? null : group.quantity + quantity;
   group.quantity = Number.isSafeInteger(total) ? total : null;
  }
 }
 return [...groups.values()];
}
