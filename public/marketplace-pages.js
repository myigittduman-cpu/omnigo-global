export function fitMarketplacePages(body){
 const content=body.querySelector('.market-list-content');
 const list=content.querySelector('.marketplace-settings-list');
 const footer=body.querySelector('.market-list-footer');
 const nav=document.createElement('nav');
 nav.setAttribute('aria-label','Pazaryerleri');
 nav.innerHTML='<button class="btn" type="button">Önceki</button><span role="status"></span><button class="btn" type="button">Sonraki</button>';
 footer.prepend(nav);
 const [prev,next]=nav.querySelectorAll('button');
 let page=0,size=1;
 function fit(){
  if(!content.isConnected||!content.clientHeight)return;
  const cards=[...list.children];
  const first=Math.min(page*size,Math.max(0,cards.length-1));
  function display(start,count){cards.forEach((card,i)=>card.hidden=i<start||i>=start+count);}
  nav.hidden=true;display(0,cards.length);
  if(content.scrollHeight<=content.clientHeight+1){page=0;size=Math.max(1,cards.length);return;}
  nav.hidden=false;
  // Fit the tallest page, including cards with wrapped labels or inline errors.
  for(size=cards.length-1;size>1;size--){
   let fits=true;
   for(let i=0;i<cards.length;i+=size){display(i,size);if(content.scrollHeight>content.clientHeight+1){fits=false;break;}}
   if(fits)break;
  }
  size=Math.max(1,size);page=Math.floor(first/size);
  display(page*size,size);
  const total=Math.ceil(cards.length/size);
  prev.disabled=page===0;next.disabled=page+1===total;
  nav.querySelector('span').textContent=(page+1)+' / '+total;
 }
 prev.onclick=()=>{page--;fit();};next.onclick=()=>{page++;fit();};
 const observer=new ResizeObserver(()=>{if(content.isConnected)fit();else observer.disconnect();});
 observer.observe(content);fit();return fit;
}
