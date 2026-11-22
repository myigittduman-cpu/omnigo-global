// Shared only by the isolated, synthetic demo data provider.
export const matchesText=(value,query)=>!query||JSON.stringify(value).toLocaleLowerCase('tr-TR').includes(query.toLocaleLowerCase('tr-TR'));
export function matchesDate(value,query){
 const day=String(value).slice(0,10),from=query.get('from'),to=query.get('to');
 return (!from||day>=from)&&(!to||day<=to);
}
export function paginate(items,query,pageSize){
 const total=items.length,last=Math.max(0,Math.ceil(total/pageSize)-1),page=Math.min(last,Math.max(0,Math.floor(Number(query.get('page'))||0)));
 return {items:items.slice(page*pageSize,(page+1)*pageSize),total,page,pageSize,hasMore:(page+1)*pageSize<total};
}
