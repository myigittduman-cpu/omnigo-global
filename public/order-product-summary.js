import {stockCodeGroups,stockCodeQuantity} from './stock-code-groups.js';

/** Summarize products within one order without changing the source lines. */
export function summarizeOrderProducts(products = []) {
 return stockCodeGroups(products).map(({code,items})=>({
  title:items.find(item=>item.title)?.title??'',
  quantity:stockCodeQuantity(items),
  ...(code?{sku:code}:{}),
 }));
}
