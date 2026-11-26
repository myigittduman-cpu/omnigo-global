import {constrainTableSelection,isTableTextTarget,hasTableTextSelection,tableSelectionText} from './table-text-selection.js';
import {observeTableHeadings} from './table-sticky-headings.js';

// UI selection policy only; authorized copy buttons and document exports remain available.
const bindings=new WeakMap();
const nonTextInputs=new Set(['button','submit','reset','checkbox','radio','file','hidden','range','color','image']);
let applicationCopyDepth=0;
export function editableContentTarget(target){
 const element=target?.nodeType===1?target:target?.parentElement;
 const field=element?.closest('input,textarea,[contenteditable]');
 if(!field)return false;
 if(field.tagName==='INPUT'||field.tagName==='TEXTAREA')return !field.disabled&&!field.readOnly&&!nonTextInputs.has(field.type);
 return field.isContentEditable===true;
}
export function withApplicationCopy(operation){
 applicationCopyDepth++;
 try{return operation();}finally{applicationCopyDepth--;}
}
export function bindPortalContentProtection(root){
 if(!root)return ()=>{};
 if(bindings.has(root))return bindings.get(root);
 const handlers=[];
 const disposeHeadings=observeTableHeadings(root);
 for(const type of ['selectstart','copy','cut','dragstart']){
  const handler=event=>{
   const navigationDrag=type==='dragstart'&&event.target?.closest?.('.navigation-order-dialog [data-order-id][draggable="true"]');
   const editable=editableContentTarget(event.target);
   if(type==='copy'&&!editable&&applicationCopyDepth===0){
    event.preventDefault();
    const value=tableSelectionText(root);
    if(value)event.clipboardData?.setData('text/plain',value);
    return;
   }
   const tableCopy=type==='copy'&&applicationCopyDepth>0;
   const tableSelection=type==='selectstart'&&isTableTextTarget(event.target);
   if(!navigationDrag&&!tableCopy&&!tableSelection&&!editable)event.preventDefault();
  };
  root.addEventListener(type,handler);handlers.push([type,handler]);
 }
 const keydown=event=>{
  const key=event.key.toLowerCase();
  if(!editableContentTarget(event.target)&&(event.ctrlKey||event.metaKey)&&['a','c','x'].includes(key)
    &&!(key==='c'&&hasTableTextSelection(root)))event.preventDefault();
 };
 root.addEventListener('keydown',keydown);handlers.push(['keydown',keydown]);
 const doc=root.ownerDocument,view=doc?.defaultView;
 const updateSelection=()=>constrainTableSelection(root);
 let dragCell=null,dragPointer=null,finishFrame=0;
 const clearDrag=()=>{
  if(finishFrame)view.cancelAnimationFrame(finishFrame);
  finishFrame=0;
  if(!dragCell)return;
  dragCell.removeAttribute('data-table-text-drag-origin');
  root.removeAttribute('data-table-text-drag');
  dragCell=null;dragPointer=null;
 };
 const pointerDown=event=>{
  if(event.button!==0)return;
  clearDrag();
  const editable=editableContentTarget(event.target);
  if(isTableTextTarget(event.target)){
   if(!editable&&view&&['mouse','pen'].includes(event.pointerType)){
    dragCell=event.target.closest('tbody td');
    dragPointer=event.pointerId;
    dragCell.setAttribute('data-table-text-drag-origin','');
    root.setAttribute('data-table-text-drag','');
   }
   return;
  }
  if(!event.shiftKey&&!editable&&!event.target?.closest?.('button,a,[role="button"],input,select,textarea,label,summary')
   &&hasTableTextSelection(root))doc.getSelection().removeAllRanges();
 };
 const pointerEnd=event=>{
  if(!dragCell||event.pointerId!==dragPointer||finishFrame)return;
  // Keep the mask through the browser's final native selection update.
  finishFrame=view.requestAnimationFrame(()=>{
   finishFrame=0;
   updateSelection();
   clearDrag();
  });
 };
 root.addEventListener('pointerdown',pointerDown);handlers.push(['pointerdown',pointerDown]);
 doc?.addEventListener('selectionchange',updateSelection);
 view?.addEventListener('pointerup',pointerEnd,true);
 view?.addEventListener('pointercancel',pointerEnd,true);
 view?.addEventListener('blur',clearDrag);
 const dispose=()=>{
  disposeHeadings();
  clearDrag();
  doc?.removeEventListener('selectionchange',updateSelection);
  view?.removeEventListener('pointerup',pointerEnd,true);
  view?.removeEventListener('pointercancel',pointerEnd,true);
  view?.removeEventListener('blur',clearDrag);
  for(const [type,handler] of handlers)root.removeEventListener(type,handler);
  bindings.delete(root);
 };
 bindings.set(root,dispose);return dispose;
}
