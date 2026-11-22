/** Read requests may overlap; only the most recent request can paint this workspace. */
export function latestPageRead(model,current){
 let sequence=0,controller;
 return {
  begin(){controller?.abort();controller=new AbortController();const ticket=++sequence;model.loading=true;return {signal:controller.signal,active:()=>ticket===sequence&&current(),finish:()=>{if(ticket!==sequence)return false;model.loading=false;return current();}};},
  cancel(){sequence++;controller?.abort();model.loading=false;}
 };
}
