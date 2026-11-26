import {transitionSlogans} from './transition-slogans.js';

const tones=['#ddc184','#e1ebfc','#96b4da','#96a9c8'];

export function addTransitionAmbient(dialog){
 if(dialog.querySelector('.transition-ambient'))return;
 const layer=document.createElement('div');
 layer.className='transition-ambient';
 layer.setAttribute('aria-hidden','true');
 const fragment=document.createDocumentFragment();
 for(const [index,slogan] of transitionSlogans.entries()){
  const column=index%8,row=Math.floor(index/8);
  const center=column>=2&&column<=5&&row>=4&&row<=11;
  const right=column>=4,bottom=row>=8;
  const cycle=17+index%9;
  const label=document.createElement('span');
  label.textContent=slogan;
  label.dataset.side=right?'right':'left';
  label.dataset.zone=row>=3&&row<=12?'edge':'corner';
  const layout={
   x:(2+Math.min(column,7-column)*12+index*13%6)+'%',
   y:(row*6+index*17%5)+'%',
   tone:tones[(index+Math.floor(index/4))%tones.length],
   time:cycle+'s',delay:-(index*53%240)/240*cycle+'s',
   size:(.8+index*19%170/100)+'vw',alpha:center?.10:.12+index%4*.03,
   blur:(.35+index%5*.25)+'px',
   dx:(right?1:-1)*(18+index%7*7)+'px',dy:(bottom?1:-1)*(8+index%4*5)+'px',
  };
  for(const [property,value] of Object.entries(layout))label.style.setProperty('--'+property,value);
  fragment.append(label);
 }
 layer.append(fragment);
 dialog.prepend(layer);
}
