export const menuStyles=Object.freeze({
 modern:'Modern Kurumsal (varsayılan)',
 inter:'Dengeli Inter',
 gold:'İnce Altın',
 prestige:'Klasik Prestij'
});
export const menuStyleFor=value=>Object.hasOwn(menuStyles,value)?value:'modern';

/** Only the navigation changes; account colors and table typography stay independent. */
export function applyMenuAppearance(value,root){
 const style=menuStyleFor(value);
 root.dataset.menuStyle=style;
 const doc=root.ownerDocument;
 if(!doc?.head)return;
 for(const [id,href] of [['menu-appearance','/menu-appearance.css'],['menu-font-'+style,'/menu-font-'+style+'.css']]){
  if(doc.getElementById(id))continue;
  const link=doc.createElement('link');link.id=id;link.rel='stylesheet';link.href=href;
  link.addEventListener('load',()=>doc.fonts?.ready.then(()=>doc.dispatchEvent(new Event('omnigo:menu-appearance'))),{once:true});
  doc.head.append(link);
 }
 doc.dispatchEvent(new Event('omnigo:menu-appearance'));
}
