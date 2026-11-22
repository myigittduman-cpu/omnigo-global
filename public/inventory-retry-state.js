const delay=5*60*1000;

export function inventoryErrorSources(data){
 return [...(data.progress?.providers||[]),{provider:'inventory-projection',error:data.sync?.error,lastSuccess:data.sync?.lastSuccess}];
}

// Per-customer display state only. The server keeps owning every retry and job.
export function persistentInventoryErrors(previous={},providers=[],now=Date.now()){
 const next={};
 for(const provider of providers){
  const old=previous[provider.provider];
  const recovered=old&&provider.lastSuccess&&provider.lastSuccess!==old.lastSuccess;
  const failed=Boolean(provider.error)||['retry','incomplete'].includes(provider.state);
  if(failed){
   next[provider.provider]=old&&!recovered?old:{since:now,lastSuccess:provider.lastSuccess||null};
  }else if(old&&!recovered){
   next[provider.provider]=old;
  }
 }
 return {state:next,visible:Object.values(next).some(error=>now-error.since>=delay)};
}

const memory=new Map();
export function inventoryRetryVisible(company,providers){
 const key=`omnigo.inventory.retry.${company}`;
 let previous=memory.get(key)||{};
 try{previous=JSON.parse(sessionStorage.getItem(key)||'null')||previous;}catch{}
 const result=persistentInventoryErrors(previous,providers);
 memory.set(key,result.state);
 try{sessionStorage.setItem(key,JSON.stringify(result.state));}catch{}
 return result.visible;
}
