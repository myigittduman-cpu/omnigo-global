export function navigationRowSizes(count,maxColumns){
 if(!Number.isInteger(count)||count<1)return [];
 const rows=Math.ceil(count/Math.max(1,maxColumns)),base=Math.floor(count/rows),extra=count%rows;
 return Array.from({length:rows},(_,index)=>base+Number(index<extra));
}
/** Geometry only. Access, visibility and menu order remain owned by existing policy. */
export function bindNavigationBalance(holder,{signal}={}){
 let frame=0;
 const layout=()=>{
  const grid=holder.querySelector('.workspace-navigation-grid');if(!grid||!grid.getBoundingClientRect().width)return;
  const nodes=[...grid.children].filter(node=>!node.hidden&&(node.matches('.workspace-menu-link')||node.matches('.workspace-menu-customer')&&node.childElementCount));
  const rows=navigationRowSizes(nodes.length,innerWidth<=1200?4:7);grid.dataset.balanced='true';let index=0;
  for(const size of rows)for(let column=0;column<size;column++){
   const node=nodes[index++];node.style.setProperty('--workspace-menu-row-size',String(size));node.toggleAttribute('data-menu-row-end',column===size-1);
  }
 };
 const schedule=()=>{cancelAnimationFrame(frame);frame=requestAnimationFrame(layout);};
 const resize=new ResizeObserver(schedule),mutation=new MutationObserver(schedule);
 resize.observe(holder);mutation.observe(holder,{subtree:true,childList:true,attributes:true,attributeFilter:['hidden']});
 signal?.addEventListener('abort',()=>{resize.disconnect();mutation.disconnect();cancelAnimationFrame(frame);},{once:true});
 return layout;
}
