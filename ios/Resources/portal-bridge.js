(() => {
 if(window!==window.top||location.origin!=='__OMNIGO_PORTAL_ORIGIN__'||window.__OMNIGO_IOS__)return;
 Object.defineProperty(window,'__OMNIGO_IOS__',{value:true});
 Object.defineProperty(window,'__OMNIGO_MOBILE_PUSH__',{value:(command,data={})=>window.webkit.messageHandlers.omnigoReport.postMessage({command,data})});
 Object.defineProperty(window,'__OMNIGO_SET_LANGUAGE__',{value:language=>{
  if(!['tr','en','de'].includes(language))return Promise.resolve();
  return window.webkit.messageHandlers.omnigoReport.postMessage({command:'set_language',data:{language}});
 }});
 window.dispatchEvent(new Event('omnigo-native-language-ready'));
 const send=(command,filename,contents)=>{
  if(contents.length>44739244)return Promise.reject(new Error('Belge çok büyük.'));
  return window.webkit.messageHandlers.omnigoReport.postMessage({command,filename,contents});
 };
 Object.defineProperty(window,'__OMNIGO_SAVE_REPORT__',{value:(filename,contents)=>send('save_report',filename,contents)});
 Object.defineProperty(window,'__OMNIGO_SHARE_REPORT__',{value:(filename,contents)=>send('share_report',filename,contents)});
 const encode=file=>new Promise((resolve,reject)=>{
  const reader=new FileReader();reader.onload=()=>resolve(String(reader.result).split(',')[1]);
  reader.onerror=()=>reject(new Error('Belge okunamadı.'));reader.readAsDataURL(file);
 });
 const validFiles=data=>data?.files?.length===1&&/\.(pdf|xlsx|txt|jpe?g)$/.test(data.files[0].name)&&data.files[0].size>0&&data.files[0].size<=33554432;
 const originalShare=navigator.share?.bind(navigator),originalCanShare=navigator.canShare?.bind(navigator);
 Object.defineProperty(navigator,'canShare',{configurable:true,value:data=>data?.files?validFiles(data):Boolean(originalCanShare?.(data))});
 Object.defineProperty(navigator,'share',{configurable:true,value:async data=>{
  if(!data?.files){if(originalShare)return originalShare(data);throw new Error('Bu içerik paylaşılamıyor.');}
  if(!validFiles(data))throw new Error('Bu belge paylaşılamıyor.');
  const file=data.files[0],result=await send('share_report',file.name,await encode(file));
  if(result==='cancelled')throw new DOMException('Paylaşım iptal edildi.','AbortError');
  if(result!=='shared')throw new Error('Paylaşım tamamlanamadı.');
 }});
 const originalClick=HTMLAnchorElement.prototype.click;
 HTMLAnchorElement.prototype.click=function(){
  if(!this.href.startsWith('blob:'+location.origin+'/')||!/^.+\.(txt|pdf|xlsx|jpe?g)$/.test(this.download))return originalClick.call(this);
  const url=this.href,name=this.download;
  (async()=>{const blob=await(await fetch(url)).blob();if(blob.size>33554432)throw new Error('Belge çok büyük.');await window.__OMNIGO_SAVE_REPORT__(name,await encode(blob));})().catch(error=>alert(error.message));
 };
 const removeInstall=()=>document.querySelectorAll('.install-control').forEach(element=>element.remove());
 removeInstall();new MutationObserver(removeInstall).observe(document,{childList:true,subtree:true});
})();
