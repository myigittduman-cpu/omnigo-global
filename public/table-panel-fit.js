// Keep the table controls within reach while retaining access to long rows.
const viewportSelector='.corporate-table-viewport,.report-table-scroll,.finance-table-scroll';

export function tableHeightBudget(viewportBottom,contentTop,frameTop,afterFrame,minimum=150){
 return Math.max(minimum,Math.floor(viewportBottom-contentTop-frameTop-afterFrame));
}

export function fitTablePanels(root){
 const view=root.ownerDocument.defaultView;
 let observedMain,frame,scheduled;
 const resize=new view.ResizeObserver(schedule);

 function apply(){
  scheduled=undefined;
  const main=root.querySelector('.workspace > main.main');
  if(main!==observedMain){
   resize.disconnect();
   if(main){
    resize.observe(main);
    const header=main.previousElementSibling;
    if(header)resize.observe(header);
   }
   observedMain=main;
  }
  const frames=main?[...main.querySelectorAll(viewportSelector)].filter(node=>
   node.clientWidth>0&&!node.closest('dialog,[data-print-only],.print-document')&&
   !node.parentElement.closest(viewportSelector)&&node.querySelector('table')):[];
  // Reports with several visible tables keep their document layout.
  const next=frames.length===1?frames[0]:null;
  if(frame!==next){
   frame?.style.removeProperty('--table-panel-height');
   frame=next;
  }
  if(!frame)return;
  const mainBox=main.getBoundingClientRect(),frameBox=frame.getBoundingClientRect();
  const app=main.closest('#app');
  const desktop=app&&app.closest('.omnigo-desktop-shell');
  const appBottom=desktop?app.getBoundingClientRect().bottom:view.innerHeight;
  const mobileNav=root.querySelector('.mobile-nav');
  const navBox=mobileNav?.getBoundingClientRect();
  const bottom=navBox?.height&&view.getComputedStyle(mobileNav).position==='fixed'?Math.min(appBottom,navBox.top):appBottom;
  const budget=tableHeightBudget(bottom,mainBox.top+view.scrollY+(desktop?app.scrollTop:0),frameBox.top-mainBox.top,Math.max(0,mainBox.bottom-frameBox.bottom));
  const value=budget+'px';
  if(frame.style.getPropertyValue('--table-panel-height')!==value)frame.style.setProperty('--table-panel-height',value);
 }
 function schedule(){if(scheduled===undefined)scheduled=view.requestAnimationFrame(apply);}
 view.addEventListener('resize',schedule);
 schedule();
 return {schedule,disconnect(){
  view.removeEventListener('resize',schedule);
  resize.disconnect();
  if(scheduled!==undefined)view.cancelAnimationFrame(scheduled);
  frame?.style.removeProperty('--table-panel-height');
 }};
}
