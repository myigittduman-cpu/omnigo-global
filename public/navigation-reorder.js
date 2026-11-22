// Move only existing entries. Invalid and external drag payloads leave the order intact.
export function reorderNavigation(ids,source,target){
 const from=ids.indexOf(source),to=ids.indexOf(target);
 if(from<0||to<0||from===to)return [...ids];
 const result=[...ids];result.splice(from,1);result.splice(to,0,source);return result;
}
