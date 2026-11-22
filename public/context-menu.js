// Install before the first interactive content, including login and offline pages.
document.addEventListener('contextmenu',event=>event.preventDefault(),{capture:true});
document.addEventListener('keydown',event=>{
 if(event.key==='ContextMenu'||(event.key==='F10'&&event.shiftKey))event.preventDefault();
},{capture:true});
