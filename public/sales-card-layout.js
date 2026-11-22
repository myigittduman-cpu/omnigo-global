// Align overview sections without hiding longer marketplace-specific details.
const bindings=new WeakMap();
const sections=['header','.channel-health','.summary-top','.summary-metrics','.summary-month'];

export function bindSalesCardLayout(root){
 bindings.get(root)?.();
 const grid=root.querySelector('.active-sales-grid, .compact-channel-grid');
 if(!grid)return ()=>{};
 let frame=0,disposed=false,width=-1;
 const refresh=()=>{
  if(disposed)return;
  cancelAnimationFrame(frame);
  frame=requestAnimationFrame(()=>{
   if(!grid.isConnected)return;
   const cards=[...grid.querySelectorAll('.active-sales-card, .compact-channel-card')].filter(card=>card.querySelector('.marketplace-summary'));
   const groups=sections.map(selector=>cards.map(card=>card.querySelector(selector)).filter(Boolean));
   for(const group of groups)for(const element of group)element.style.minHeight='';
   // Align only cards on the same visual row. A warning in a later row must not stretch healthy cards above it.
   const rows=new Map();for(const card of cards){const top=Math.round(card.getBoundingClientRect().top);if(!rows.has(top))rows.set(top,[]);rows.get(top).push(card);}
   for(const row of rows.values()){
    if(row.length<2)continue;
    for(const selector of sections){const group=row.map(card=>card.querySelector(selector)).filter(element=>element?.getClientRects().length);
     const height=Math.ceil(Math.max(0,...group.map(element=>element.getBoundingClientRect().height)));
     for(const element of group)element.style.minHeight=height+'px';
    }
   }
  });
 };
 const observer=new ResizeObserver(entries=>{
  const next=entries[0].contentRect.width;
  if(next!==width){width=next;refresh();}
 });
 observer.observe(grid);
 const dispose=()=>{disposed=true;cancelAnimationFrame(frame);observer.disconnect();};
 bindings.set(root,dispose);
 document.fonts?.ready.then(refresh);
 refresh();
 return refresh;
}
