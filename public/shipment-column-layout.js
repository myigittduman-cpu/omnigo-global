import {shipmentColumnWidths} from './shipment-column-widths.js';

const addressKeys=new Set(['deliveryAddress','invoiceAddress']);
// Minimum reading space and a wrap target, rather than fixed percentages.
const rules={orderedAt:[66,100,1],customerName:[42,150,2],recipientName:[42,150,2],phone:[65,115,1],province:[38,100,1],district:[42,130,1],productName:[70,210,4],invoiceCents:[70,130,1],quantity:[32,60,1],invoiceName:[42,150,2],recipientEmail:[52,150,3],customerType:[48,110,1]};
const canvas=document.createElement('canvas'),context=canvas.getContext('2d');
function measure(element,text){
 const style=getComputedStyle(element);context.font=style.fontWeight+' '+style.fontSize+' '+style.fontFamily;
 return context.measureText(text).width;
}
function textLines(cell){
 const clone=cell.cloneNode(true);clone.querySelectorAll('button').forEach(button=>button.remove());
 clone.querySelectorAll('br').forEach(br=>br.replaceWith('\n'));
 clone.querySelectorAll('time span,.shipment-customer-line,.shipment-return-quantity').forEach(line=>line.append('\n'));
 return clone.textContent.trim().split(/[\r\n]+/).map(line=>line.trim()).filter(Boolean);
}
export function fitShipmentColumns(root){
 const table=root.querySelector('.shipment-scroll table');if(!table)return;
 const headings=[...table.querySelectorAll('thead th')];
 if(['grouped','invoice'].includes(table.dataset.shipmentLayout)){headings.forEach(heading=>heading.style.removeProperty('width'));return;}
 if(!matchMedia('(min-width:1024.01px)').matches){headings.forEach(heading=>heading.style.removeProperty('width'));return;}
 const width=table.parentElement.clientWidth,rows=[...table.querySelectorAll('tr[data-shipment-row]')];
 if(!width)return;
 const columns=headings.map((heading,index)=>{
  const key=[...heading.classList].find(name=>name.startsWith('shipment-head-'))?.slice(14);
  if(addressKeys.has(key))return {address:true};
  if(key==='rowStatus')return {minimum:84,preferred:84};
  const [minimum,maximum,lines]=rules[key]||[40,140,2];
  const title=heading.firstChild?.textContent.trim()||'';
  let wordMinimum=Math.max(minimum,...title.split(/\s+/).map(word=>measure(heading,word)+18)),preferred=wordMinimum;
  for(const row of rows){const cell=row.cells[index];if(!cell)continue;
   for(const text of textLines(cell)){
    const words=text.split(/[\s@._/\-]+/),longest=Math.max(0,...words.map(word=>measure(cell,word)));
    wordMinimum=Math.max(wordMinimum,Math.min(maximum,longest+18));
    preferred=Math.max(preferred,Math.min(maximum,Math.max(longest,measure(cell,text)/lines)+18));
   }
  }
  preferred=Math.min(maximum,preferred);
  const keepValueTogether=['orderedAt','phone','invoiceCents','quantity','customerType'].includes(key);
  return {floor:keepValueTogether?preferred:minimum,minimum:keepValueTogether?preferred:Math.min(preferred,wordMinimum),preferred};
 });
 shipmentColumnWidths(width,columns).forEach((value,index)=>headings[index].style.width=value+'px');
}
export function bindShipmentColumnLayout(root){
 let width=-1;
 const fit=()=>{if(root.isConnected)fitShipmentColumns(root);};
 const observer=new ResizeObserver(entries=>{
  if(!root.isConnected){observer.disconnect();return;}
  const next=entries[0].contentRect.width;if(Math.abs(next-width)<1)return;width=next;fit();
 });
 observer.observe(root);document.fonts?.ready.then(fit);return fit;
}
