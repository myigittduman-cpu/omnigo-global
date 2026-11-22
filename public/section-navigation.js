import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';

// Per-workspace memory only. Returning to a list restores its filters, page and explicit selection.
export function createSectionNavigation(model,fields,initialKey=null){
 const saved=new Map();let key=initialKey;
 const remember=()=>{if(key!==null&&model.screen==='details')saved.set(key,structuredClone(Object.fromEntries(fields.filter(field=>field in model).map(field=>[field,model[field]]))));};
 return {
  enter(next,defaults){remember();Object.assign(model,structuredClone(saved.get(next)||defaults));key=next;model.screen='details';model.data=null;},
  back(){remember();key=null;model.screen='selection';},
  clear(){saved.clear();},
 };
}
export function sectionPageHeader(title,attribute,subtitle=''){
 return i18nHtml`<header class="section-page-header"><button type="button" class="btn section-back" ${attribute}><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg>Geri</button><div><h2>${esc(title)}</h2>${subtitle?i18nHtml`<p>${esc(subtitle)}</p>`:''}</div></header>`;
}
export function focusSectionPage(root){
 const target=root.querySelector('.section-page-header h2,[data-section-selection]');
 if(target){target.tabIndex=-1;target.focus({preventScroll:true});target.scrollIntoView({block:'start',behavior:'instant'});}
}
