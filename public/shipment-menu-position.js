// Keep the mobile panel in the table card's coordinate space so page scrolling moves it with its row.
export function followShipmentMenu(menu){
 const panel=menu.querySelector('.shipment-operations-panel'),trigger=menu.querySelector('summary');
 if(!panel||!trigger)return ()=>{};
 const card=menu.closest('[data-shipment-table]');
 const scroller=menu.closest('.shipment-scroll,.orders-table-wrap,.shipment-tracking-scroll');
 const footer=card?.querySelector('.shipment-table-foot');
 const bottomNav=document.querySelector('.mobile-nav');
 const mobile=matchMedia('(max-width:1024px), (hover:none) and (pointer:coarse)');
 const events=new AbortController();
 const gap=8,edge=12,productName=menu.hasAttribute('data-shipment-product-name');
 let frame=0;

 function place(left,top){
  panel.style.setProperty('--shipment-menu-left',left+'px');
  panel.style.setProperty('--shipment-menu-top',top+'px');
 }
 function position(){
  frame=0;if(!menu.isConnected||!menu.open){stop();return;}
  const anchor=trigger.getBoundingClientRect();
  if(mobile.matches&&card&&scroller){
   const cardBounds=card.getBoundingClientRect();
   const tableBounds=scroller.getBoundingClientRect();
   const visible=anchor.right>tableBounds.left&&anchor.left<tableBounds.right;
   const anchorLeft=Math.max(anchor.left,tableBounds.left)-cardBounds.left-card.clientLeft;
   const left=Math.min(anchorLeft,Math.max(edge,card.clientWidth-panel.offsetWidth-edge));
   let top=anchor.bottom+gap-cardBounds.top-card.clientTop;
   if(productName&&top+panel.offsetHeight>card.clientHeight-edge){
    top=Math.max(edge,anchor.top-cardBounds.top-card.clientTop-panel.offsetHeight-gap);
   }
   place(left,top);
   panel.style.setProperty('--shipment-menu-visibility',visible?'visible':'hidden');
   if(footer&&!productName){
    const footerEnd=footer.getBoundingClientRect().bottom-cardBounds.top-card.clientTop;
    const room=Math.max(0,Math.ceil(top+panel.offsetHeight+(bottomNav?.offsetHeight||0)+gap-footerEnd));
    card.style.setProperty('--shipment-menu-scroll-room',room+'px');
   }
   return;
  }
  card?.style.removeProperty('--shipment-menu-scroll-room');
  const box=panel.getBoundingClientRect();
  const bounds=scroller?.getBoundingClientRect();
  if(bounds&&(anchor.right<=bounds.left||anchor.left>=bounds.right)){menu.open=false;return;}
  if(anchor.bottom<0||anchor.top>innerHeight||anchor.right<0||anchor.left>innerWidth){menu.open=false;return;}
  const below=anchor.bottom+gap,above=anchor.top-gap-box.height;
  const top=below+box.height<=innerHeight-edge?below:above>=edge?above:Math.max(edge,innerHeight-edge-box.height);
  place(Math.max(edge,Math.min(anchor.left,innerWidth-box.width-edge)),top);
  panel.style.setProperty('--shipment-menu-visibility','visible');
 }
 const schedule=()=>{if(!frame)frame=requestAnimationFrame(position);};
 const observer=new MutationObserver(()=>{if(!menu.isConnected)stop();});
 const resizeObserver=new ResizeObserver(schedule);
 function stop(){
  cancelAnimationFrame(frame);
  events.abort();
  observer.disconnect();
  resizeObserver.disconnect();
  panel.style.removeProperty('--shipment-menu-top');
  panel.style.removeProperty('--shipment-menu-left');
  panel.style.removeProperty('--shipment-menu-visibility');
  card?.style.removeProperty('--shipment-menu-scroll-room');
 }
 observer.observe(document.body,{childList:true,subtree:true});
 resizeObserver.observe(trigger);
 resizeObserver.observe(panel);
 if(card)resizeObserver.observe(card);
 window.addEventListener('resize',schedule,{passive:true,signal:events.signal});
 mobile.addEventListener('change',schedule,{signal:events.signal});
 document.addEventListener('scroll',event=>{
  if(!mobile.matches||!card||event.target===scroller)schedule();
 },{capture:true,passive:true,signal:events.signal});
 document.addEventListener('click',event=>{
  const target=event.target;
  if(!menu.contains(target)&&!target.closest?.('dialog[open]'))menu.open=false;
 },{capture:true,signal:events.signal});
 menu.addEventListener('toggle',()=>{if(!menu.open)stop();},{signal:events.signal});
 position();return stop;
}
