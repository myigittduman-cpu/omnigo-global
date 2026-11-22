export async function loadCustomPages(api,company){
 try{const result=await api(`/custom-pages?company=${encodeURIComponent(company)}`);return Array.isArray(result?.items)?result:{items:[],unavailable:true};}
 catch(error){
  if(error.status===401||error.status===403)throw error;
  return {items:[],unavailable:true};
 }
}
