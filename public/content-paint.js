import {preserveTablePosition} from './table-page-scroll.js';
const painted=new WeakMap();
/** Retain identical live views and the current scroll lane; never clone a form. */
export function paintContent(target,markup){
 if(!target||painted.get(target)===markup)return false;
 const restore=preserveTablePosition(target);
 target.innerHTML=markup;painted.set(target,markup);restore();return true;
}
