/** A category visit acknowledges only the snapshot received for this user/company. */
export async function acknowledgeCategory({state,category,current,visible=()=>true,load,send,update}){
 state.notificationBusy=true;update();
 try{
  await load();
  if(!current()||!visible()||state.notificationError||!state.notificationData?.through)return;
  const through={...state.notificationData.through};
  if(!state.notificationData.counts?.unread)return;
  await send(category,through);
  if(current())await load();
 }catch(error){if(current())state.notificationActionError=error.message;}
 finally{if(current()){state.notificationBusy=false;update();}}
}

// Acknowledge only the snapshot present when the bell was opened.
export async function acknowledgeNotificationVisit({current,load,mark}){
 const snapshot=await load();
 if(!current()||!snapshot?.through||!(snapshot.totalUnread??snapshot.counts?.unread))return;
 await mark({...snapshot.through});
}
