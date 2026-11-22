import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
// Arithmetic only: no eval, scripting, network calls or workspace data.
export function calculateExpression(input){
 const text=String(input).replaceAll(',','.').replaceAll('×','*').replaceAll('÷','/').replace(/\s/g,'');
 if(!text||text.length>120||/[^0-9.+*/()%\-]/.test(text))throw new Error(i18nT('İşlemi kontrol edin.'));
 let index=0,depth=0;
 function value(){
  if(++depth>24)throw new Error(i18nT('İşlem çok uzun.'));let result;
  if(text[index]==='+'||text[index]==='-'){const sign=text[index++];result=value()*(sign==='-'?-1:1);}
  else if(text[index]==='('){index++;result=sum();if(text[index++]!==')')throw new Error(i18nT('Parantezi kapatın.'));}
  else{const number=text.slice(index).match(/^(?:\d+(?:\.\d*)?|\.\d+)/);if(!number)throw new Error(i18nT('İşlemi tamamlayın.'));index+=number[0].length;result=Number(number[0]);}
  while(text[index]==='%'){result/=100;index++;}depth--;return result;
 }
 function product(){let result=value();while(text[index]==='*'||text[index]==='/'){const operator=text[index++],right=value();if(operator==='/'&&right===0)throw new Error(i18nT('Sıfıra bölünemez.'));result=operator==='*'?result*right:result/right;}return result;}
 function sum(){let result=product();while(text[index]==='+'||text[index]==='-'){const operator=text[index++],right=product();result+=operator==='+'?right:-right;}return result;}
 const result=sum();if(index!==text.length||!Number.isFinite(result)||Math.abs(result)>1e15)throw new Error(i18nT('İşlem veya sonuç sınır dışında.'));
 return Number(result.toPrecision(12));
}
