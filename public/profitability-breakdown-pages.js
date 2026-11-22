import {t} from './i18n.js';

export function bindProfitabilityBreakdown(dialog){
 const viewport=dialog.querySelector('[data-breakdown-window]');
 if(!viewport)return;
 const list=viewport.querySelector('[data-breakdown-rows]');
 const pager=dialog.querySelector('[data-breakdown-pager]');
 const previous=pager.querySelector('[data-breakdown-prev]'),next=pager.querySelector('[data-breakdown-next]');
 const entries=Array.from(list.querySelectorAll('section')).flatMap(section=>
  Array.from(section.querySelector('dl').children,row=>({header:section.querySelector('h3'),row})));
 const starts=[0];
 let page=0;
 function render(){
  list.replaceChildren();
  let lastHeader=null,group=null,index=starts[page];
  for(;index<entries.length;index++){
   const {header,row}=entries[index],newGroup=lastHeader!==header;
   if(newGroup){
    const section=document.createElement('section');
    group=document.createElement('dl');group.className='profit-breakdown-list';
    section.append(header.cloneNode(true),group);list.append(section);lastHeader=header;
   }
   group.append(row);
   if(list.getBoundingClientRect().height>viewport.clientHeight+1&&index>starts[page]){
    row.remove();
    if(newGroup)group.parentElement.remove();
    break;
   }
  }
  starts.length=page+1;
  if(index<entries.length)starts.push(index);
  previous.disabled=page===0;next.disabled=index>=entries.length;
  pager.querySelector('[data-breakdown-page]').textContent=t('Sayfa')+' '+(page+1);
 }
 function layout(){
  page=0;starts.splice(0,starts.length,0);pager.hidden=true;
  render();
  if(starts.length>1){pager.hidden=false;render();}
 }
 previous.onclick=()=>{if(page>0){page--;render();}};
 next.onclick=()=>{if(page+1<starts.length){page++;render();}};
 layout();
 const observer=new ResizeObserver(changes=>{
  const {width,height}=changes[0].contentRect;
  if(!viewport.isConnected||!dialog.open){observer.disconnect();return;}
  if(width!==lastWidth||height!==lastHeight){lastWidth=width;lastHeight=height;layout();}
 });
 let lastWidth=0,lastHeight=0;
 observer.observe(dialog);
 dialog.addEventListener('close',()=>observer.disconnect(),{once:true});
}
