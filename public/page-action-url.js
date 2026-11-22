export function pageActionUrl(value){
 if(typeof value!=='string')return '';
 const url=value.trim();
 if(!url||url.length>500||/[\x00-\x20\x7f\\]/.test(url))return '';
 if(url.startsWith('/')&&!url.startsWith('//'))return url;
 try{const parsed=new URL(url);return parsed.protocol==='https:'&&parsed.hostname&&!parsed.username&&!parsed.password?url:'';}catch{return '';}
}
