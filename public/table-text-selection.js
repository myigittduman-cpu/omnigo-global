export function copyableTableFor(node){
 const element=node?.nodeType===1?node:node?.parentElement;
 return element?.closest('table[data-copyable-table]')??null;
}

export function hasTableTextSelection(root){
 const selection=root.ownerDocument?.getSelection();
 if(!selection||selection.isCollapsed||!selection.rangeCount)return false;
 const table=copyableTableFor(selection.anchorNode);
 if(!table||!root.contains(table))return false;
 for(let index=0;index<selection.rangeCount;index++){
  const range=selection.getRangeAt(index);
  if(copyableTableFor(range.startContainer)!==table||copyableTableFor(range.endContainer)!==table)return false;
 }
 return true;
}
