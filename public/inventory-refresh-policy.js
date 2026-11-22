export const isActiveConnectionImport=run=>['queued','running','retry'].includes(run?.state);
export const activeConnectionImports=data=>Array.isArray(data?.connectionImports)&&data.connectionImports.some(isActiveConnectionImport);

/** Polling must not overwrite edits; a previously clicked button is not an edit. */
export function canRefreshInventory(model,{dialogOpen=false,focusedTag='',draftQuery=model.query}={}){
 return !model.loading&&!model.busy&&!model.selected.size&&!dialogOpen&&!['INPUT','TEXTAREA','SELECT'].includes(focusedTag)&&draftQuery===model.query;
}
export function inventoryRefreshDelay(data){
 return activeConnectionImports(data)||data?.activation?.marketplaceActive&&(!data.initialImport?.complete||data.metadata?.pending>0)?3000:15000;
}
