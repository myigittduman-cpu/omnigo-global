/** Native vault writes finish before the login form is replaced; browser manager prompts stay optional. */
export async function commitRememberedLogin(form,control,user,{timeoutMs=3000}={}){
 if(!control?.rememberCredentials)return null;
 const pending=Promise.resolve().then(()=>control.rememberCredentials(user)).catch(()=>false);
 if(form.dataset.credentialStorage!=='device-vault'){void pending;return null;}
 let timer;
 try{return await Promise.race([pending,new Promise(resolve=>{timer=setTimeout(()=>resolve(false),timeoutMs);})]);}
 finally{clearTimeout(timer);}
}
