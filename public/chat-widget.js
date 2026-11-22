import {api} from './api.js';
import {bindChatDock} from './chat-dock.js';
import {chatContacts,chatMessages,chatPeople,chatSelectionIncludes,chatShell,chatThreads} from './chat-view.js';

export function bindChatWidget(state){
 let current=null;
 const stop=()=>{current?.dispose();current=null;};
 window.addEventListener('omnigo-session-ended',stop);
 navigator.serviceWorker?.addEventListener('message',event=>{
  const id=event.data?.threadId;
  if(event.data?.type!=='omnigo-chat-open'||!Number.isSafeInteger(id)||id<1)return;
  window.__OMNIGO_CHAT_LAUNCH__=id;window.dispatchEvent(new Event('omnigo-chat-launch'));
 });
 window.addEventListener('omnigo-session-ready',()=>{
  stop();
  const review=document.body.classList.contains('store-review-active');
  if(state.user&&!state.user.mustChangePassword&&!state.demo&&!review)current=mountChat(state);
 });
}

function mountChat(state){
 const owner=state.user,ownerId=Number(owner.id),admin=owner.role==='admin',abort=new AbortController(),signal=abort.signal;
 const root=document.createElement('aside');
 root.className='portal-chat';root.dataset.open='false';root.innerHTML=chatShell(admin);document.body.append(root);
 const select=name=>root.querySelector('[data-chat-'+name+']');
 const panel=root.querySelector('.chat-panel'),trigger=select('toggle'),badge=select('badge'),error=select('error');
 const home=select('home'),creation=select('creation'),threadView=select('thread'),list=select('threads'),messageList=select('messages');
 const createForm=select('create'),compose=select('compose'),draft=compose.elements.text;
 const peopleList=select('people'),groupList=select('contacts');
 const footer=select('footer'),deleteToggle=select('delete-toggle');
 const selectionBar=select('selection'),selectAll=select('select-all'),deleteSelection=select('delete-selection');
 const messages=new Map(),retired=new Set(),drafts=new Map();
 const dock=bindChatDock(root,trigger,select('dock'),ownerId,signal);
 let active=null,revision=0,threadChanges=0,threads=[],contacts=null,hasMore=false,signature='',listSignature='',lastRead=0;
 let timer,heartbeat=0,syncing=false,sending=false,draftKey=null,groupRequest=null,selection=null;
 let directoryCompany,contactRequest=null,peopleMarkup='',groupMarkup='',creating=false;
 const contactCompany=()=>admin?(Number(state.company)||null):Number(owner.companyId??owner.company_id??state.company)||null;
 const valid=()=>!signal.aborted&&state.user===owner;
 const opened=()=>root.dataset.open==='true';
 const request=(path,method='GET',body)=>api('/chat'+path,method,body,{signal});

 function showError(message=''){error.textContent=message;error.hidden=!message;}
 function open(value,focus=false){
  updateContactScope();
  root.dataset.open=String(value);trigger.setAttribute('aria-expanded',String(value));
  panel.inert=!value;panel.setAttribute('aria-hidden',String(!value));
  if(value){showError();if(focus)select('close').focus({preventScroll:true});void refreshContacts();void refresh();}
  else if(focus)trigger.focus({preventScroll:true});
 }
 function showScreen(screen){
  home.hidden=screen!=='home';creation.hidden=screen!=='creation';threadView.hidden=screen!=='thread';
  drawSelection();showError();
 }
 function leaveThread(){
  active=null;selection=null;revision++;messages.clear();retired.clear();hasMore=false;signature='';draft.value='';draftKey=null;lastRead=0;
  showScreen('home');drawThreadList();
 }
 function selectionSize(){
  return selection.through?Math.max(0,selection.total-selection.ids.size):selection.ids.size;
 }
 function allSelected(){
  return selection.kind==='messages'?Boolean(selection.through):threads.length>0&&selection.ids.size===threads.length;
 }
 function drawSelection(){
  const selecting=Boolean(selection);
  footer.hidden=threadView.hidden&&!selecting;
  compose.hidden=threadView.hidden;
  compose.inert=selecting;
  if(!admin)return;
  select('directory').hidden=selecting;
  select('group').hidden=selecting;
  const label=selecting?'Seçimi kapat':active?'Mesajları seç':'Konuşmaları seç';
  deleteToggle.hidden=!creation.hidden;
  deleteToggle.disabled=Boolean(selection?.busy);
  deleteToggle.title=label;deleteToggle.setAttribute('aria-label',label);deleteToggle.setAttribute('aria-pressed',String(selecting));
  selectionBar.hidden=!selecting;
  if(!selecting)return;
  const count=selectionSize();
  select('selection-count').textContent=count+' seçili';
  selectAll.textContent=allSelected()?'Seçimi temizle':'Tümünü seç';
  selectAll.disabled=selection.busy||selection.kind==='threads'&&!threads.length;
  deleteSelection.disabled=selection.busy||!count;
 }
 function redrawSelection(){
  drawSelection();
  if(active)drawMessages();else drawThreadList();
 }
 function drawMessages({older=false,force=false}={}){
  const rows=[...messages.values()].sort((a,b)=>a.id-b.id);
  const markup=chatMessages(rows,ownerId,hasMore,selection?.kind==='messages'?selection:null);
  if(markup===signature&&!force)return;
  const height=messageList.scrollHeight,top=messageList.scrollTop,atEnd=height-top-messageList.clientHeight<45;
  const focused=document.activeElement?.dataset.chatSelectMessage;
  signature=markup;messageList.innerHTML=markup;
  if(older)messageList.scrollTop=top+(messageList.scrollHeight-height);
  else if(atEnd||force)messageList.scrollTop=messageList.scrollHeight;
  else messageList.scrollTop=top;
  if(focused)messageList.querySelector('[data-chat-select-message="'+focused+'"]')?.focus({preventScroll:true});
 }
 function messageBounds(){
  let from=Infinity,to=0;
  for(const id of messages.keys()){from=Math.min(from,id);to=Math.max(to,id);}
  return [from,to];
 }
 function retireMessages(ids){
  for(const id of ids){
   retired.add(id);messages.delete(id);
   if(selection?.kind==='messages'&&!selection.through)selection.ids.delete(id);
  }
 }
 async function readMessages(before=0,force=false){
  if(!valid()||!active)return;
  const session=revision,id=active.id;
  const query=new URLSearchParams({visible:'1'});
  if(before)query.set('before',String(before));
  else if(messages.size){
   const [from,to]=messageBounds();
   query.set('knownFrom',String(from));query.set('knownTo',String(to));
  }
  const data=await request('/threads/'+id+'?'+query);
  if(!valid()||revision!==session||active?.id!==id)return;
  if(!before){
   const people=data.members.filter(member=>member.id!==ownerId);
   select('thread-presence').textContent=active.kind==='group'?data.members.length+' katılımcı':people[0]?.online?'Çevrimiçi':'Çevrimdışı';
  }
  retireMessages(data.removedIds??[]);
  for(const message of data.messages){
   if(message.deleted)retireMessages([message.id]);
   else if(!retired.has(message.id))messages.set(message.id,message);
  }
  if(before||messages.size===data.messages.length)hasMore=data.hasMore;
  drawSelection();
  drawMessages({older:Boolean(before),force});
  const latest=data.messages.at(-1)?.id;
  if(!before&&latest>lastRead&&opened()&&document.visibilityState==='visible'&&document.hasFocus()){
   lastRead=latest;
   try{await request('/threads/'+id+'/read','POST',{id:latest});}
   catch(cause){if(revision===session)lastRead=0;throw cause;}
  }
 }
 async function enter(thread){
  const company=updateContactScope();
  if(active?.id!==thread.id){
   leaveThread();active=thread;draftKey=JSON.stringify([company,thread.id]);
   draft.value=drafts.get(draftKey)?.text??'';
  }
  select('thread-title').textContent=thread.title;select('thread-presence').textContent='';
  showScreen('thread');
  messageList.textContent='Mesajlar yükleniyor…';signature='';
  try{await readMessages(0,true);}catch(cause){if(valid())showError(cause.message);}
 }
 function drawThreadList(){
  const markup=chatThreads(threads,selection?.kind==='threads'?selection:null);
  if(markup===listSignature)return;
  const focused=document.activeElement?.dataset.chatSelectThread;
  listSignature=markup;list.innerHTML=markup;
  if(focused)list.querySelector('[data-chat-select-thread="'+focused+'"]')?.focus({preventScroll:true});
 }
 function drawThreads(data){
  threads=data.threads;
  if(selection?.kind==='threads'){
   const visible=new Set(threads.map(thread=>thread.id));
   for(const id of selection.ids)if(!visible.has(id))selection.ids.delete(id);
   drawSelection();
  }
  drawThreadList();
  badge.hidden=!data.unread;badge.textContent=data.unread>99?'99+':String(data.unread);
  trigger.setAttribute('aria-label',data.unread?'Sohbet · '+data.unread+' okunmamış mesaj':'Sohbet');
 }
 async function runSelection(action){
  const current=selection;
  if(!current||current.busy)return;
  current.busy=true;redrawSelection();showError();
  try{await action(current);}
  catch(cause){if(valid()&&selection===current&&!cause.cancelled)showError(cause.message);}
  finally{
   current.busy=false;
   if(valid()&&selection===current)redrawSelection();
  }
 }
 function selectEverything(){
  if(allSelected()){
   selection.through=0;selection.total=0;selection.ids.clear();redrawSelection();return;
  }
  if(selection.kind==='threads'){
   selection.ids=new Set(threads.map(thread=>thread.id));redrawSelection();return;
  }
  void runSelection(async current=>{
   const result=await request('/threads/'+active.id+'/messages');
   if(!valid()||selection!==current)return;
   current.through=result.through;current.total=result.total;current.ids.clear();
   if(!result.total)showError('Bu konuşmada silebileceğiniz mesaj yok.');
  });
 }
 async function removeSelected(current){
  if(current.kind==='messages'){
   const scope={through:current.through,ids:new Set(current.ids)};
   const target=scope.through?{through:scope.through,except:[...scope.ids]}:{ids:[...scope.ids]};
   await request('/threads/'+active.id+'/messages','DELETE',{...target,count:selectionSize()});
   if(!valid()||selection!==current)return;
   const removed=[...messages.values()].filter(message=>message.canDelete&&chatSelectionIncludes(scope,message.id));
   retireMessages(removed.map(message=>message.id));
  }else{
   const selected=threads.filter(thread=>current.ids.has(thread.id)).map(thread=>({id:thread.id,through:thread.latestId}));
   await request('/threads','DELETE',{threads:selected});
   if(!valid()||selection!==current)return;
   const limits=new Map(selected.map(thread=>[thread.id,thread.through]));
   for(const key of drafts.keys())if(limits.has(JSON.parse(key)[1]))drafts.delete(key);
   threadChanges++;
   threads=threads.filter(thread=>!limits.has(thread.id)||thread.latestId>limits.get(thread.id));
  }
  selection=null;redrawSelection();
  if(opened())deleteToggle.focus({preventScroll:true});
  void refresh();
 }
 let launch=Number(window.__OMNIGO_CHAT_LAUNCH__||new URL(location.href).searchParams.get('chatThread'))||0;
 delete window.__OMNIGO_CHAT_LAUNCH__;
 async function refresh(){
  if(!valid()||syncing)return;
  updateContactScope();
  syncing=true;clearTimeout(timer);
  try{
   dock.align();
   if(document.visibilityState==='visible'&&document.hasFocus()&&Date.now()-heartbeat>=20000){
    heartbeat=Date.now();await request('/presence','POST',{});
   }
   const listRevision=threadChanges,data=await request('/threads');
   if(!valid()||listRevision!==threadChanges)return;
   drawThreads(data);
   if(opened()&&!active)void refreshContacts();
   if(launch){
    const thread=threads.find(item=>item.id===launch);launch=0;
    const url=new URL(location.href);url.searchParams.delete('chatThread');history.replaceState(history.state,'',url);
    if(thread){open(true);await enter(thread);}
   }else if(opened()&&active)await readMessages();
  }catch(cause){
   if(valid()&&cause.status===401){dispose();return;}
   if(valid()&&opened())showError(cause.message);
  }finally{
   syncing=false;if(valid())timer=setTimeout(refresh,opened()?5000:12000);
  }
 }
 function drawContacts(){
  const loading='<p class="chat-empty">Kullanıcılar yükleniyor…</p>';
  const nextPeople=contacts?chatPeople(contacts):loading;
  if(nextPeople!==peopleMarkup){
   const focused=document.activeElement?.dataset.chatPerson;
   peopleMarkup=nextPeople;peopleList.innerHTML=nextPeople;
   if(focused)peopleList.querySelector('[data-chat-person="'+focused+'"]')?.focus({preventScroll:true});
  }
  const selected=new Set(new FormData(createForm).getAll('member').map(Number));
  const nextGroup=contacts?chatContacts(admin?contacts:contacts.filter(person=>!person.admin),selected):loading;
  if(nextGroup!==groupMarkup){
   const focused=groupList.contains(document.activeElement)?document.activeElement.value:null;
   const top=groupList.parentElement.scrollTop;
   groupMarkup=nextGroup;groupList.innerHTML=nextGroup;groupList.parentElement.scrollTop=top;
   if(focused)groupList.querySelector('[value="'+Number(focused)+'"]')?.focus({preventScroll:true});
  }
 }
 function resetGroup(){
  createForm.reset();
  groupList.querySelectorAll('input:checked').forEach(input=>{input.checked=false;});
  drawContacts();
 }
 function updateContactScope(){
  const company=contactCompany();
  if(directoryCompany===company)return company;
  if(active)leaveThread();
  directoryCompany=company;contactRequest=null;contacts=null;groupRequest=null;
  createForm.reset();groupList.replaceChildren();groupMarkup='';drawContacts();
  return company;
 }
 async function refreshContacts(){
  if(!valid()||!opened()||active)return;
  const company=updateContactScope();
  if(contactRequest)return;
  const ticket={};contactRequest=ticket;
  try{
   const data=await request('/contacts'+(admin&&company?'?company='+company:''));
   if(valid()&&contactRequest===ticket&&company===contactCompany()){contacts=data.contacts;drawContacts();}
  }catch(cause){if(valid()&&contactRequest===ticket&&opened())showError(cause.message);}
  finally{if(contactRequest===ticket)contactRequest=null;}
 }
 async function createThread(input,company,screen){
  if(creating)return;
  creating=true;showError();
  try{
   const result=await request('/threads','POST',input);
   threadChanges++;
   const data=await request('/threads');
   if(!valid())return;
   drawThreads(data);
   if(opened()&&company===contactCompany()&&!screen.hidden){
    const thread=threads.find(item=>item.id===result.id);
    if(thread)await enter(thread);
   }
   return true;
  }catch(cause){if(valid()&&opened()&&company===contactCompany())showError(cause.message);return false;}
  finally{creating=false;}
 }
 function direct(personId){
  const company=updateContactScope();
  if(!contacts?.some(person=>person.id===personId))return;
  void createThread({kind:'direct',members:[personId]},company,home);
 }
 createForm.addEventListener('submit',async event=>{
  event.preventDefault();const button=createForm.querySelector('button[type=submit]');
  if(button.disabled||creating)return;
  const company=updateContactScope(),values=new FormData(createForm);
  const members=values.getAll('member').map(Number),title=String(values.get('title')||'').trim();
  if(!members.length||!title){showError('Grup adını girin ve en az bir katılımcı seçin.');return;}
  const key=JSON.stringify([company,members,title]);
  if(groupRequest?.key!==key)groupRequest={key,nonce:crypto.randomUUID()};
  button.disabled=true;
  try{
   if(await createThread({kind:'group',members,title,company,nonce:groupRequest.nonce},company,creation)&&company===contactCompany()){
    groupRequest=null;resetGroup();
   }
  }finally{button.disabled=false;}
 });
 const workspace=document.getElementById('app');
 const contactObserver=workspace?new MutationObserver(()=>{
  if(contactCompany()!==directoryCompany){updateContactScope();if(opened())void refreshContacts();}
 }):null;
 if(workspace)contactObserver?.observe(workspace,{childList:true});
 root.addEventListener('dblclick',event=>{
  const button=event.target.closest('[data-chat-person]');
  if(button&&opened()&&!home.hidden&&!selection)direct(Number(button.dataset.chatPerson));
 });
 draft.addEventListener('input',()=>{
  if(!draftKey||directoryCompany!==contactCompany())return;
  if(draft.value)drafts.set(draftKey,{text:draft.value});else drafts.delete(draftKey);
 });
 draft.addEventListener('keydown',event=>{
  if(event.key!=='Enter'||event.shiftKey||event.ctrlKey||event.altKey||event.metaKey||event.isComposing||event.keyCode===229)return;
  event.preventDefault();
  if(!event.repeat&&draft.value.trim())compose.requestSubmit();
 });
 compose.addEventListener('submit',async event=>{
  event.preventDefault();
  updateContactScope();
  const text=draft.value.trim(),thread=active?.id,session=revision,button=compose.querySelector('button');
  if(!text||!thread||sending||selection)return;
  const key=draftKey,entry=drafts.get(key)??{text:draft.value};
  entry.nonce??=crypto.randomUUID();drafts.set(key,entry);
  sending=true;button.disabled=true;showError();
  try{
   await request('/threads/'+thread+'/messages','POST',{text,nonce:entry.nonce});
   if(drafts.get(key)===entry){drafts.delete(key);if(draftKey===key)draft.value='';}
   if(valid()&&session===revision)await readMessages(0,true);
  }catch(cause){if(valid())showError(cause.message);}
  finally{sending=false;button.disabled=false;}
 });
 root.addEventListener('change',event=>{
  const input=event.target;
  if(!selection||selection.busy)return;
  const id=Number(selection.kind==='messages'?input.dataset.chatSelectMessage:input.dataset.chatSelectThread);
  if(!id)return;
  const recorded=selection.through?!input.checked:input.checked;
  if(recorded)selection.ids.add(id);else selection.ids.delete(id);
  drawSelection();
 });
 root.addEventListener('click',async event=>{
  const button=event.target.closest('button');if(!button||button.disabled)return;
  if(button.hasAttribute('data-chat-toggle')){open(!opened(),true);return;}
  if(button.hasAttribute('data-chat-close')){open(false,true);return;}
  if(button.hasAttribute('data-chat-dock')){dock.toggle();return;}
  if(button===deleteToggle){
   selection=selection?null:{kind:active?'messages':'threads',ids:new Set(),through:0,total:0,busy:false};
   redrawSelection();return;
  }
  if(button===selectAll&&selection){selectEverything();return;}
  if(button===deleteSelection&&selection&&selectionSize()){void runSelection(removeSelected);return;}
  if(button.hasAttribute('data-chat-back')){leaveThread();return;}
  if(button.hasAttribute('data-chat-group')){
   resetGroup();showScreen('creation');void refreshContacts();
   createForm.elements.title.focus({preventScroll:true});return;
  }
  if(button.hasAttribute('data-chat-person')){
   if(opened()&&!home.hidden&&!selection&&(event.detail===0||event.pointerType==='touch'||matchMedia('(hover:none)').matches))
    direct(Number(button.dataset.chatPerson));
   return;
  }
  const thread=threads.find(item=>item.id===Number(button.dataset.chatThreadId));
  if(thread){void enter(thread);return;}
  if(button.hasAttribute('data-chat-older')&&messages.size){
   button.disabled=true;
   try{await readMessages(messageBounds()[0]);}catch(cause){if(valid())showError(cause.message);}
   finally{button.disabled=false;}
  }
 });
 document.addEventListener('pointerdown',event=>{
  if(opened()&&!root.contains(event.target)&&!document.querySelector('dialog[open]'))open(false);
 },{capture:true,signal});
 document.addEventListener('keydown',event=>{
  if(event.key!=='Escape'||!opened()||document.querySelector('dialog[open]')||selection?.busy)return;
  if(selection){selection=null;redrawSelection();deleteToggle.focus({preventScroll:true});}
  else open(false,true);
 },{signal});
 window.addEventListener('focus',()=>void refresh(),{signal});
 window.addEventListener('omnigo-chat-launch',()=>{
  launch=Number(window.__OMNIGO_CHAT_LAUNCH__)||0;delete window.__OMNIGO_CHAT_LAUNCH__;void refresh();
 },{signal});
 function dispose(){abort.abort();clearTimeout(timer);drafts.clear();contactObserver?.disconnect();dock.dispose();root.remove();}
 drawSelection();dock.align();void refresh();
 return {dispose};
}
