import {esc,initials} from './ui.js';

// Shared presentation rule: administrator artwork never depends on a personal upload or selected store.
export const adminPortraitUrl='/assets/omnigo-profile-v1.png';
export const hasUserPortrait=user=>user?.role==='admin'||Boolean(user?.avatarVersion);
export const portraitUrl=user=>user?.role==='admin'?adminPortraitUrl:'/api/account/portraits/'+Number(user?.id)+'?v='+Number(user?.avatarVersion||0);

export const companyWordmarkClass=name=>/^vizyona(?: beyaz eşya)?$/u.test(String(name??'').normalize('NFC').trim().toLocaleLowerCase('tr-TR'))?' vizyona-wordmark':'';

export function userPortraitMarkup(user,{fallbackName=user?.name,alt='',companyName}={}){
 const wordmark=user?.role==='client'?companyWordmarkClass(companyName):'';
 return hasUserPortrait(user)?'<img src="'+portraitUrl(user)+'" alt="'+esc(alt)+'"'+(wordmark?' class="'+wordmark.trim()+'"':'')+'>':esc(initials(fallbackName));
}
