import {t} from './i18n.js';
import {icon} from './ui.js';
import {loadingState} from './content-state.js';

const definitions=[
 ['accounts','Kullanıcı hesapları','customers'],
 ['sections','Kullanıcı Yetkileri','store','Mağaza erişimini, kullanıcı rollerini ve kişiye özel izinleri düzenleyin.'],
 ['defaults','Rol Varsayılanları','tasks'],
 ['inventory','Stok modeli','inventory','Bu mağazada kullanılacak stok modelini seçin.'],
 ['cities','Teslimat şehirleri','shipments','Şehir Dışı Teslimatlar ve Şehir Dışı Ürünler için kullanılacak illeri seçin.'],
 ['notifications','Bildirimler','notifications','Bu mağazanın kullanıcılarına gösterilecek bildirim türlerini belirleyin.'],
 ['scope','Çalışma kapsamı','channels','Mağazada kullanılacak canlı aktarım ekranlarını ve manuel işlemleri seçin.'],
];

export function bindPermissionTabs(form,{canSelect,onSelect},initialTab='accounts'){
 const body=form.closest('.dialog-body');
 const nav=document.createElement('div');
 nav.className='permission-tabs';
 nav.setAttribute('role','tablist');
 nav.setAttribute('aria-label',t('Mağaza ayarları'));
 const panels=document.createElement('div');
 panels.className='permission-panels';
 form.before(nav);
 form.prepend(panels);
 const cities=form.querySelector('[data-city-settings]');
 const inventory=document.createElement('div');
 inventory.dataset.inventoryModelSettings='';
 const scope=form.querySelector('[data-permission-work-scope]');
 const nodes={
  sections:[form.querySelector('[data-permission-overview]'),form.querySelector('[data-permission-users]')],
  inventory:[inventory],cities:[cities],notifications:[form.querySelector('[data-notification-settings]')],scope:[scope],
 };
 const controls=new Map();
 const scroll=new Map();
 let active=initialTab==='sections'?'sections':null,selecting=false,queued=null,query='';
 for(const [key,label,symbol,description] of definitions){
  if(key==='scope'&&!scope)continue;
  const button=document.createElement('button');
  button.type='button';button.className='btn permission-tab';button.id='permission-tab-'+key;
  button.dataset.permissionTab=key;button.setAttribute('role','tab');button.setAttribute('aria-controls','permission-panel-'+key);
  button.innerHTML=icon(symbol);
  const caption=document.createElement('span');caption.textContent=t(label);button.append(caption);
  nav.append(button);
  const panel=document.createElement('section');
  panel.dataset.permissionPanel=key;panel.id='permission-panel-'+key;
  panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',button.id);
  if(nodes[key]){
   const heading=document.createElement('header');heading.className='permission-panel-heading';
   const title=document.createElement('h3');title.textContent=t(label);
   const help=document.createElement('p');help.textContent=t(description);
   heading.append(title,help);panel.append(heading,...nodes[key]);panels.append(panel);
  }else{
   panel.dataset.permissionExternal=key;
   form.after(panel);
  }
  controls.set(key,{button,panel,external:!nodes[key]});
  button.onclick=()=>void select(key);
 }

 function refresh(){
  const visible=active??initialTab;
  const external=controls.get(visible).external;
  form.hidden=external;
  body.querySelector('.permission-store small').hidden=external;
  for(const [key,{button,panel}] of controls){
   const selected=key===visible;
   button.setAttribute('aria-selected',String(selected));button.tabIndex=selected?0:-1;
   panel.hidden=!selected;
  }
  cities.querySelectorAll('details').forEach(el=>el.open=true);
  cities.querySelectorAll('[data-city-setting-row]').forEach(el=>el.hidden=!el.dataset.citySettingRow.toLocaleLowerCase('tr').includes(query));
 }
 async function select(key,focus=false){
  if(!controls.has(key))return;
  if(selecting){queued={key,focus};return;}
  if(key===active)return;
  const old=controls.get(active??initialTab);
  const previousPosition={body:body.scrollTop,content:(old.external?old.panel:panels).scrollTop};
  selecting=true;
  nav.setAttribute('aria-busy','true');
  let allowed=false;
  try{
   allowed=await canSelect(key,active)&&form.isConnected;
   if(!allowed||(queued&&queued.key!==key))return;
   if(active)scroll.set(active,previousPosition);
   const selected=controls.get(key);
   active=key;refresh();
   const scroller=selected.external?selected.panel:panels;
   const target=scroll.get(key)||{body:0,content:0};
   let initial=null;
   const restore=()=>{
    if(active!==key||!form.isConnected)return;
    if(!initial||scroller.scrollTop===initial.content)scroller.scrollTop=target.content;
    if(!initial||body.scrollTop===initial.body)body.scrollTop=target.body;
   };
   onSelect(key,selected.panel,restore);
   restore();
   initial={body:body.scrollTop,content:scroller.scrollTop};
   if(focus||!document.activeElement||document.activeElement===document.body)selected.button.focus({preventScroll:true});
  }finally{
   if(!active&&form.isConnected){active='sections';refresh();}
   selecting=false;nav.removeAttribute('aria-busy');
   const next=queued;queued=null;
   if(allowed&&next&&next.key!==active)void select(next.key,next.focus);
  }
 }
 nav.onkeydown=event=>{
  const keys=[...controls.keys()],index=keys.indexOf(active??initialTab);
  const destination={Home:0,End:keys.length-1,ArrowLeft:(index+keys.length-1)%keys.length,ArrowUp:(index+keys.length-1)%keys.length,ArrowRight:(index+1)%keys.length,ArrowDown:(index+1)%keys.length};
  if(!Object.hasOwn(destination,event.key))return;
  event.preventDefault();void select(keys[destination[event.key]],true);
 };
 if(scope)nav.dataset.workScope='';
 refresh();
 if(!active){
  controls.get(initialTab).panel.innerHTML=loadingState('Kullanıcılar yükleniyor…');
  void select(initialTab);
 }
 return {refresh,searchCities(value){query=value;refresh();}};
}
