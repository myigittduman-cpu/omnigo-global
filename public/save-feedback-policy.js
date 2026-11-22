/** Success means the server committed a user-facing write, never merely a click. */
export function saveFeedbackFor(path,method,result={},data){
 const url=new URL(path,'https://local.invalid'),route=url.pathname;
 if(route==='/inventory/commands'){
  if(method==='POST'&&result.state==='pending')return {kind:'pending',message:'Stok kaydı sıraya alındı.'};
  if(result.state==='done')return {kind:'success',message:'Stok bilgileri kaydedildi.',key:url.searchParams.get('requestId')};
  return null;
 }
 if(!['POST','PUT','PATCH'].includes(method)||result?.changed===false)return null;
 if(/^\/records\/payments\/\d+\/approve$/.test(route)&&result.paymentApproval?.at)return {kind:'success',message:'Bilgileriniz kaydedildi.'};
 if(['/operations/dossier','/operations/attention'].includes(route))return {kind:'success',message:'Bilgileriniz kaydedildi.'};
 if(/^\/integrations\/[a-z0-9]+$/.test(route)&&method==='POST'&&data?.action==='retry'){
  return typeof result.message==='string'&&result.message?{kind:'pending',message:result.message}:null;
 }
 if(/^\/admin\/users(?:\/\d+(?:\/portrait)?)?$/.test(route))return {kind:'success',message:'Bilgileriniz kaydedildi.'};
 const routes=[/^\/management\/(customer|logo|marketplaces(?:-bulk|\/[a-z0-9]+)?|permissions(?:-bulk)?|users(?:\/\d+(?:\/(?:avatar|profile|password))?)?)$/,/^\/(companies|users(?:\/\d+)?|records\/(?:payments|tasks|reports|sales|channels)(?:\/\d+)?)$/,/^\/account\/(username|preferences|navigation|avatar)$/,/^\/inventory\/(purchase-price|products|image|visibility)$/,/^\/integrations\/[a-z0-9]+$/,/^\/orders\/-?\d+\/documents\/(invoice|delivery)$/,/^\/shipments(?:\/\d+)?(?:\/(?:cell|status|invoice|documents\/[a-z]+|attachments))?$/,/^\/deliveries\/.*\/(correction|commit)$/];
 if(!routes.some(pattern=>pattern.test(route)))return null;
 if(result?.state==='pending'||result?.queued===true)return {kind:'pending',message:'Kaydınız işleme alındı.'};
 return {kind:'success',message:'Bilgileriniz kaydedildi.'};
}
