import './static-i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api,setCsrf} from './api.js';
import {hepsiburadaConnection} from './hepsiburada.js';
const form=document.getElementById('setupForm'),select=document.getElementById('setupCompany'),status=document.getElementById('setupStatus');
const state={company:null,companies:[],user:null};
async function initialize(){
 const session=await api('/me');setCsrf(session.csrf);
 if(session.user.role!=='admin')throw new Error(i18nT('Bu sayfa yalnızca yönetici hesapları içindir.'));
 if(session.reviewEnvironment)throw new Error(i18nT('Canlı bağlantı için gerçek yönetici hesabıyla giriş yapın.'));
 state.user=session.user;state.companies=await api('/companies');
 for(const company of state.companies){const option=document.createElement('option');option.value=String(company.id);option.textContent=company.name;select.append(option);}
 form.hidden=false;status.textContent=i18nT('Bağlanacak mağazanın müşterisini seçin.');
}
form.onsubmit=async event=>{event.preventDefault();state.company=Number(select.value);if(!state.companies.some(c=>c.id===state.company))return;await hepsiburadaConnection(state,async()=>{status.textContent=i18nT('Bağlantı bilgileri şifreli olarak kaydedildi.');});};
initialize().catch(error=>{status.textContent=error.status===401?i18nT('Devam etmek için yönetici uygulamasında giriş yapın.'):error.message;form.hidden=true;});
