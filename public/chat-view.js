import {esc} from './ui.js';

const bubble='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H5l1.7-3.4A7.5 7.5 0 1 1 20 11.5Z"/><path d="M9 12h7m-7-3h5"/></svg>';
const trash='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 10v7m4-7v7"/></svg>';
const time=new Intl.DateTimeFormat('tr-TR',{hour:'2-digit',minute:'2-digit'});

export function chatShell(admin){
 const deleteToggle=admin?`<button type="button" class="chat-icon-button" data-chat-delete-toggle title="Konuşmaları seç" aria-label="Konuşmaları seç" aria-pressed="false" hidden>${trash}</button>`:'';
 const selection=admin?`<div class="chat-compose chat-selection" data-chat-selection hidden><button type="button" data-chat-select-all>Tümünü seç</button><span data-chat-selection-count role="status"></span><button type="button" class="chat-icon-button chat-primary" data-chat-delete-selection title="Seçilenleri sil" aria-label="Seçilenleri sil" disabled>${trash}</button></div>`:'';
 return `<button type="button" class="chat-trigger" data-chat-toggle aria-expanded="false" aria-haspopup="dialog" aria-controls="omnigo-chat-panel">${bubble}<span>Sohbet</span><span class="chat-badge" data-chat-badge hidden></span></button>
 <section class="chat-panel" id="omnigo-chat-panel" role="dialog" aria-label="Omnigo sohbet" aria-hidden="true" inert>
  <header class="chat-header"><span class="chat-heading">${bubble}<strong>Sohbet</strong></span><div class="chat-head-actions"><button type="button" data-chat-dock>Sağ alta taşı</button>${deleteToggle}<button type="button" class="chat-close" data-chat-close aria-label="Sohbeti kapat">×</button></div></header>
  <div class="chat-alert" data-chat-error role="status" hidden></div>
  <div class="chat-content" data-chat-home>
   <div class="chat-section-header"><strong>Konuşmalar</strong><button type="button" data-chat-group>Grup Sohbeti</button></div>
   <section class="chat-directory" data-chat-directory><h3>Kullanıcılar</h3><div class="chat-directory-list" data-chat-people><p class="chat-empty">Kullanıcılar yükleniyor…</p></div></section>
   <div class="chat-thread-list" data-chat-threads aria-label="Mevcut konuşmalar"><p class="chat-empty">Sohbetler yükleniyor…</p></div>
  </div>
  <div class="chat-content" data-chat-creation hidden><div class="chat-section-header"><button type="button" data-chat-back>Geri</button><strong>Grup Sohbeti</strong></div>
   <form class="chat-create" data-chat-create>
   <label>Grup adı<input name="title" maxlength="100" autocomplete="off" required></label>
   <fieldset class="chat-contacts"><legend>Katılımcılar</legend><div data-chat-contacts></div></fieldset><button type="submit" class="chat-primary">Grup Sohbeti Başlat</button></form>
  </div>
  <div class="chat-content" data-chat-thread hidden><div class="chat-section-header"><button type="button" data-chat-back>Geri</button><div class="chat-thread-heading"><strong data-chat-thread-title></strong><small data-chat-thread-presence></small></div></div>
   <div class="chat-messages" data-chat-messages role="log" aria-label="Sohbet mesajları" tabindex="0"></div>
  </div>
  <div class="chat-footer" data-chat-footer hidden>
   <form class="chat-compose" data-chat-compose><textarea name="text" rows="2" maxlength="2000" aria-label="Mesajınızı yazın" placeholder="Mesajınızı yazın…" enterkeyhint="send" required></textarea><button type="submit" class="chat-primary">Gönder</button></form>
   ${selection}
  </div>
 </section>`;
}

export function chatThreads(threads,selection){
 return threads.map(thread=>{
  const details=`<span><strong>${esc(thread.title)}</strong>${thread.kind==='group'?'<small>Grup sohbeti</small>':''}</span>`;
  const unread=thread.unread?`<span class="chat-count">${Math.min(99,thread.unread)}${thread.unread>99?'+':''}</span>`:'';
  if(selection){
   const selected=selection.ids.has(thread.id)?' checked':'';
   return `<label class="chat-thread-row chat-thread-choice"><input type="checkbox" data-chat-select-thread="${thread.id}"${selected}${selection.busy?' disabled':''}>${details}${unread}</label>`;
  }
  return `<button type="button" class="chat-thread-row" data-chat-thread-id="${thread.id}">${details}${unread}</button>`;
 }).join('')
  ||'<p class="chat-empty">Henüz sohbet yok.</p>';
}

const contactStatus=person=>(person.admin?'Omnigo · ':'')+(person.online?'Çevrimiçi':'Çevrimdışı');
const contactDot=person=>`<span class="chat-presence" data-online="${person.online}" aria-hidden="true"></span>`;

export function chatPeople(contacts){
 return contacts.map(person=>`<button class="chat-person" type="button" data-chat-person="${person.id}" title="Sohbeti açmak için çift tıklayın" aria-label="${esc(person.name)} · ${contactStatus(person)} · Sohbeti aç"><strong>${esc(person.name)}</strong>${person.admin?'<small>Omnigo</small>':''}${contactDot(person)}</button>`).join('')
  ||'<p class="chat-empty">Sohbet için uygun kullanıcı bulunamadı.</p>';
}

export function chatContacts(contacts,selected){
 return contacts.map(person=>`<label class="chat-contact"><input type="checkbox" name="member" value="${person.id}"${selected.has(person.id)?' checked':''}><span><strong>${esc(person.name)}</strong><small>${contactStatus(person)}</small></span>${contactDot(person)}</label>`).join('')
  ||'<p class="chat-empty">Grup sohbeti için uygun kullanıcı bulunamadı.</p>';
}

export function chatSelectionIncludes(selection,id){
 return selection.through?id<=selection.through&&!selection.ids.has(id):selection.ids.has(id);
}

export function chatMessages(messages,userId,hasMore,selection){
 return (hasMore?'<button class="chat-older" type="button" data-chat-older>Önceki mesajları göster</button>':'')+
  (messages.filter(message=>!message.deleted).map(message=>{
   const stamp=time.format(new Date(message.createdAt*1000));
   const selectable=selection&&message.canDelete;
   const selected=selectable&&chatSelectionIncludes(selection,message.id);
   const disabled=selectable&&(selection.busy||(selection.through&&message.id>selection.through));
   return `<article class="chat-message${message.senderId===userId?' chat-own':''}${selectable?' chat-selectable':''}" data-message-id="${message.id}">
    <header><strong>${esc(message.sender)}</strong><time>${stamp}</time></header>
    <p id="chat-message-text-${message.id}">${esc(message.text)}</p>
    ${selectable?`<input type="checkbox" class="chat-message-choice" data-chat-select-message="${message.id}" aria-label="Mesajı seç" aria-describedby="chat-message-text-${message.id}"${selected?' checked':''}${disabled?' disabled':''}>`:''}
   </article>`;
  }).join('')||'<p class="chat-empty">İlk mesajı yazabilirsiniz.</p>');
}
