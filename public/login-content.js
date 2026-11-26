import {copyableTableFor,hasTableTextSelection} from './table-text-selection.js';
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
   const tableCopy=type==='copy'&&(applicationCopyDepth>0||hasTableTextSelection(root));
   const tableSelection=type==='selectstart'&&copyableTableFor(event.target);
   if(!navigationDrag&&!tableCopy&&!tableSelection&&!editableContentTarget(event.target))event.preventDefault();
  };
  root.addEventListener(type,handler);handlers.push([type,handler]);
 }
 const keydown=event=>{
  const key=event.key.toLowerCase();
  if(!editableContentTarget(event.target)&&(event.ctrlKey||event.metaKey)&&['a','c','x'].includes(key)
    &&!(key==='c'&&hasTableTextSelection(root)))event.preventDefault();
 };
 root.addEventListener('keydown',keydown);handlers.push(['keydown',keydown]);
 const dispose=()=>{disposeHeadings();for(const [type,handler] of handlers)root.removeEventListener(type,handler);bindings.delete(root);};
 bindings.set(root,dispose);return dispose;
}
