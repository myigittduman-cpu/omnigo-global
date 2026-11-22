import {currentLanguage,t} from './i18n.js';

// A language hint is the only data sent to the existing, origin-scoped host bridge.
function syncNativeLanguage(){
 if(typeof window.__OMNIGO_SET_LANGUAGE__==='function')
  Promise.resolve(window.__OMNIGO_SET_LANGUAGE__(currentLanguage())).catch(()=>{});
}
for(const event of ['omnigo-language-change','omnigo-native-language-ready','omnigo-report-ready'])
 window.addEventListener(event,syncNativeLanguage);
syncNativeLanguage();

// Older Windows packages inject their own toolbar. Translate only that known
// application chrome; never observe customer records or editable form contents.
function bindDesktopToolbar(){
 const toolbar=document.getElementById('omnigo-desktop-toolbar');if(!toolbar)return false;
 const original=new WeakMap();
 const translate=(node,key,value,write)=>{
  const fields=original.get(node)||{};const prior=fields[key];
  const source=prior?.rendered===value?prior.source:value;
  const rendered=t(source);fields[key]={source,rendered};original.set(node,fields);
  if(rendered!==value)write(rendered);
 };
 const render=()=>{
  const walker=document.createTreeWalker(toolbar,NodeFilter.SHOW_TEXT);let node;
  while((node=walker.nextNode())){const target=node;translate(target,'text',target.nodeValue,value=>{target.nodeValue=value;});}
  for(const element of [toolbar,...toolbar.querySelectorAll('[title],[aria-label]')])
   for(const name of ['title','aria-label'])if(element.hasAttribute(name))translate(element,name,element.getAttribute(name),value=>element.setAttribute(name,value));
 };
 new MutationObserver(render).observe(toolbar,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['title','aria-label']});
 window.addEventListener('omnigo-language-change',render);render();return true;
}
if(!bindDesktopToolbar()&&window.__OMNIGO_DESKTOP__){
 const observer=new MutationObserver(()=>{if(bindDesktopToolbar())observer.disconnect();});
 observer.observe(document.body,{childList:true});
}
