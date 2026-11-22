import {applyPalette} from './workspace-palette.js';
const modes=new Set(['balanced','classic']);
let current={mode:'balanced',version:0};
export const workspaceDesign=()=>({...current});
export function applyWorkspaceDesign(value){
 if(!value||!modes.has(value.mode)||!Number.isSafeInteger(value.version)||value.version<0)return false;
 if(value.version<current.version)return false;
 const changed=current.mode!==value.mode;current={mode:value.mode,version:value.version,...(value.palette?{palette:value.palette}:{})};
 if(typeof document!=='undefined'){document.documentElement.dataset.workspaceDesign=current.mode;applyPalette(current.palette);}
 if(changed&&typeof window!=='undefined')window.dispatchEvent(new Event('omnigo-workspace-design-changed'));
 return true;
}
if(typeof document!=='undefined'){
 const style=document.createElement('link');style.rel='stylesheet';style.href='/balanced-design.css?v=0.9.221';style.dataset.workspaceDesignStyles='';document.head.append(style);
 applyWorkspaceDesign(current);
}
