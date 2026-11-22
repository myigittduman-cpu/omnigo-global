import './input-history.js';
import {t} from './i18n.js';

// Runs once before the page's controller. Only the shipped, static HTML exists
// here: never run this over a dashboard, uploaded content or an API response.
export function localizeStaticPage(doc){
 const walker=doc.createTreeWalker(doc.documentElement,NodeFilter.SHOW_TEXT);
 const nodes=[];let node;
 while((node=walker.nextNode())){
  if(node.parentElement?.closest('script,style,textarea,code,pre,[translate="no"]'))continue;
  nodes.push(node);
 }
 for(const textNode of nodes)textNode.nodeValue=t(textNode.nodeValue);
 for(const element of doc.querySelectorAll('[title],[aria-label],[placeholder],[alt]')){
  if(element.closest('[translate="no"]'))continue;
  for(const attribute of ['title','aria-label','placeholder','alt'])if(element.hasAttribute(attribute))element.setAttribute(attribute,t(element.getAttribute(attribute)));
 }
}
if(globalThis.document?.createTreeWalker&&!document.querySelector('.workspace'))localizeStaticPage(document);
