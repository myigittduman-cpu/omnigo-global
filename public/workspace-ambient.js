// One composited CSS layer, paused in background tabs and disabled for reduced motion.
if(typeof document!=='undefined'){
 const pause=()=>document.documentElement.dataset.workspaceMotion=document.hidden?'paused':'running';
 document.addEventListener('visibilitychange',pause);pause();
}
